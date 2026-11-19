<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EducationDatedReportNote extends Model
{
    use LogsActivity, SoftDeletes;

    public const CATEGORIES = [
        'accomplishments' => 'Weekly accomplishment',
        'other_activities' => 'Other activity',
    ];

    protected $fillable = ['user_id', 'submission_key', 'occurred_on', 'category', 'body', 'revision'];

    protected $casts = ['occurred_on' => 'date', 'revision' => 'integer'];

    public function activityIgnoredAttributes(): array
    {
        return ['body', 'submission_key'];
    }
}
