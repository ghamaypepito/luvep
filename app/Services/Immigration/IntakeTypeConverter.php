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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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
        ['country_of_citizenship', 'nationality'],
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

        // Some intakes (Resident) have NOT NULL columns with no default that the
        // other forms don't collect. Seed those with safe placeholders so the
        // record saves; they're visa-specific and the client completes them.
        $this->fillRequiredDefaults($target, $attrs);

        /** @var Model $new */
        $new = $targetClass::create($attrs);

        // Pair a tracking Assessment so it shows in the Visa Assessment list.
        Assessment::createForIntake($new, $visaType);

        return $new;
    }

    /**
     * Seed any NOT NULL column (with no DB default) the target form requires but
     * the source form didn't provide — so the record saves. Values are neutral
     * placeholders for visa-specific fields the client completes afterwards.
     */
    private function fillRequiredDefaults(Model $model, array &$attrs): void
    {
        try {
            $columns = Schema::getColumns($model->getTable());
        } catch (\Throwable $e) {
            return;
        }

        $fillable = $model->getFillable();
        foreach ($columns as $col) {
            $name = $col['name'];
            // Already has a non-null value (set or copied), or the column can be
            // null / has a default — nothing to seed.
            $hasValue = array_key_exists($name, $attrs) && $attrs[$name] !== null;
            if ($hasValue
                || ! empty($col['nullable'])
                || ($col['default'] ?? null) !== null
                || in_array($name, ['id', 'created_at', 'updated_at'], true)
                || ! in_array($name, $fillable, true)) {
                continue;
            }

            $type = strtolower((string) ($col['type_name'] ?? $col['type'] ?? ''));
            if (str_contains($type, 'json')) {
                continue; // JSON columns are nullable in practice; leave them
            }

            $attrs[$name] = match (true) {
                str_contains($type, 'bool') => false,
                str_contains($type, 'int') => 0,
                str_contains($type, 'dec') || str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'numeric') => 0,
                str_contains($type, 'date') || str_contains($type, 'time') => Carbon::today()->toDateString(),
                default => '',
            };
        }
    }
}
