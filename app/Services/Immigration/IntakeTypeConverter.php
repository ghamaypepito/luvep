<?php

namespace App\Services\Immigration;

use App\Models\Assessment;
use App\Models\FamilyIntake;
use App\Models\ResidentIntake;
use App\Models\StudentIntake;
use App\Models\VisitorIntake;
use App\Models\WorkIntake;
use App\Support\IntakeVisaTypeMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Re-create a submitted visa assessment as a DIFFERENT visa type, carrying over
 * every answer the two forms share.
 *
 * Used when a client submits the wrong visa type by mistake (e.g. an AEWV work
 * assessment when they meant a Student visa). The intake forms share the same
 * column names for the whole applicant profile — identity, character, health,
 * current employment, family, travel — so those copy 1:1; only the visa-specific
 * sections (a work job offer vs a study programme + financials) differ and are
 * left blank for the client/staff to complete. A new paired Assessment is
 * created so the re-made assessment appears in the Visa Assessment list with its
 * own token. The original is left untouched (staff delete it if they want).
 */
class IntakeTypeConverter
{
    /** target key => [model class, intake_id prefix]. */
    public const TARGETS = [
        'resident' => [ResidentIntake::class, 'RI'],
        'work' => [WorkIntake::class, 'WI'],
        'student' => [StudentIntake::class, 'SI'],
        'visitor' => [VisitorIntake::class, 'VI'],
        'family' => [FamilyIntake::class, 'FV'],
    ];

    /**
     * Fields never copied across: per-record metadata, files, and the
     * declaration/signature — the client must re-declare and re-sign for the
     * new visa type (accepting the work declaration isn't accepting the student
     * one), so these are reset rather than carried.
     */
    private const SKIP = [
        'intake_id', 'status', 'edit_token', 'id', 'created_at', 'updated_at',
        'documents', 'document_files',
        'declaration_accepted', 'signature_name', 'signature_date',
    ];

    /** Equivalent fields that are named differently between forms. */
    private const ALIASES = [
        ['last_name', 'family_name'],
    ];

    public function recreateAs(Model $source, string $targetType): Model
    {
        [$targetClass, $prefix] = self::TARGETS[$targetType];

        // The new intake needs a paired Assessment to appear in the Visa
        // Assessment list, and that needs the target visa type to exist. Resolve
        // it up front so we never create an orphan intake with no assessment.
        $visaType = IntakeVisaTypeMap::resolve($targetClass);
        if (! $visaType) {
            throw new \RuntimeException("The {$targetType} visa type isn't configured yet — add it under Visa Types first.");
        }

        /** @var Model $target */
        $target = new $targetClass;
        $targetFillable = $target->getFillable();

        // Copy every field the two forms share, minus metadata/files.
        $shared = array_diff(array_intersect($source->getFillable(), $targetFillable), self::SKIP);
        $attrs = [];
        foreach ($shared as $f) {
            $attrs[$f] = $source->{$f};
        }

        // Carry the surname across the last_name <-> family_name naming split.
        foreach (self::ALIASES as [$a, $b]) {
            foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
                if (! array_key_exists($to, $attrs)
                    && in_array($to, $targetFillable, true)
                    && in_array($from, $source->getFillable(), true)
                    && $source->{$from} !== null) {
                    $attrs[$to] = $source->{$from};
                }
            }
        }

        $attrs['intake_id'] = $prefix.'-'.strtoupper(uniqid());
        $attrs['status'] = 'Submitted';
        $attrs['edit_token'] = Str::random(48);
        // Reset the declaration — the client re-affirms it for the new visa.
        $attrs['declaration_accepted'] = false;

        /** @var Model $new */
        $new = $targetClass::create($attrs);

        // Pair a tracking Assessment so it shows in the Visa Assessment list.
        Assessment::createForIntake($new, $visaType);

        return $new;
    }
}
