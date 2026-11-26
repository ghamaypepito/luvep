<?php

namespace App\Support;

use App\Models\FamilyIntake;
use App\Models\ResidentIntake;
use App\Models\StudentIntake;
use App\Models\VisaType;
use App\Models\VisitorIntake;
use App\Models\WorkIntake;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps the four public visa-interest intake models to the
 * VisaType.code they should attach to when an Assessment row is created.
 *
 * The seeded VisaType codes are the source of truth — this lookup keeps
 * the (intake type → visa type) wiring in one place so all four intake
 * controllers + the backfill command agree on the same mapping.
 *
 * Each value is a list of candidate codes tried in order; the first one
 * that resolves to a real VisaType wins. Listing the legacy lowercase
 * `category` value as a final fallback means an admin who renames the
 * seeded "Skilled Migrant Resident Visa" code still gets resolution via
 * VisaType.category.
 */
final class IntakeVisaTypeMap
{
    /**
     * Intake model class → ordered list of VisaType.code candidates.
     * The map keeps the FQCN keys so it round-trips cleanly even when
     * model namespaces shift.
     */
    public const MAP = [
        ResidentIntake::class => [
            'code'     => ['SMC'],
            'category' => 'Resident',
            'label'    => 'Resident Visa (SMC)',
        ],
        WorkIntake::class => [
            'code'     => ['WORK_AEWV', 'AEWV'],
            'category' => 'Work',
            'label'    => 'Work Visa (AEWV)',
        ],
        StudentIntake::class => [
            'code'     => ['STUDENT', 'SV'],
            'category' => 'Student',
            'label'    => 'Student Visa',
        ],
        VisitorIntake::class => [
            'code'     => ['VISITOR', 'GVV'],
            'category' => 'Visitor',
            'label'    => 'Visitor Visa (GVV)',
        ],
        FamilyIntake::class => [
            'code'     => ['FAMILY', 'PARTNER'],
            'category' => 'Partnership',
            'label'    => 'Family Visa (Partner / Child)',
        ],
    ];

    /**
     * Resolve the VisaType row that pairs with the given intake-model
     * class, or null if no candidate code or category matches.
     *
     * @param  class-string|Model  $intakeClass
     */
    public static function resolve(string|Model $intakeClass, ?string $workCategory = null): ?VisaType
    {
        if ($intakeClass instanceof Model) {
            $category = $intakeClass instanceof WorkIntake ? ($intakeClass->vif_answers['work_visa_category'] ?? null) : null;
            $workCategory = is_string($category) ? $category : null;
            $intakeClass = $intakeClass::class;
        }
        if ($intakeClass === WorkIntake::class && $workCategory === 'Post-Study Work Visa') {
            return VisaType::whereIn('code', ['POST_STUDY_WORK', 'PSWV'])->first();
        }

        $config = self::MAP[$intakeClass] ?? null;
        if (! $config) {
            return null;
        }

        // Try explicit codes first — exact-match against the seeded
        // VisaType.code column. Fall back to category match so an admin
        // who renames the "SMC" code (e.g. to "RESIDENT_SMC") still
        // resolves through `category = Resident`.
        return VisaType::query()->whereIn('code', $config['code'])->first()
            ?: VisaType::query()->where('category', $config['category'])
                ->when($intakeClass === WorkIntake::class, fn ($query) => $query->whereNotIn('code', ['POST_STUDY_WORK', 'PSWV']))->first();
    }

    /** Update only the assessment paired with a staff/client's stated Work category. */
    public static function syncWorkCategory(WorkIntake $intake, string $errorField): void
    {
        $visa = self::resolve($intake);
        if (! $visa) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $errorField => 'This work visa category is not configured. Please contact the Immigration team.',
            ]);
        }
        \App\Models\Assessment::where('intakeable_type', WorkIntake::class)->where('intakeable_id', $intake->id)
            ->update(['visa_type_id' => $visa->id]);
    }

    /**
     * Human-readable label used in the post-submit flash and in the
     * backfill command's progress output.
     */
    public static function label(string $intakeClass): string
    {
        return self::MAP[$intakeClass]['label'] ?? class_basename($intakeClass);
    }

    /** List of intake model classes the map covers. */
    public static function intakeClasses(): array
    {
        return array_keys(self::MAP);
    }
}
