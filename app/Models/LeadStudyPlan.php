<?php

namespace App\Models;

use App\Services\StudentProgramIntakes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadStudyPlan extends Model
{
    protected $fillable = [
        'lead_id', 'preferred_course', 'program_schools', 'qualification_level', 'preferred_city',
        'preferred_intake', 'program_intakes', 'english_test_taken', 'english_test_type',
        'english_test_date', 'score_overall', 'score_reading', 'score_listening',
        'score_writing', 'score_speaking',
    ];

    protected $casts = [
        'english_test_taken' => 'boolean',
        'english_test_date' => 'date',
        'program_schools' => 'array',
        'program_intakes' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving([StudentProgramIntakes::class, 'syncLegacyChanges']);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
