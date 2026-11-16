<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EducationReportActivity extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['user_id', 'lead_id', 'client_name', 'reported_status', 'submission_key', 'kind', 'occurred_on', 'details'];

    protected $casts = ['occurred_on' => 'date'];

    public function activityIgnoredAttributes(): array
    {
        return ['client_name', 'details'];
    }

    public function editVersion(): string
    {
        return hash_hmac('sha256', json_encode($this->only(['lead_id', 'client_name', 'reported_status', 'kind', 'occurred_on', 'details'])), config('app.key'));
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
