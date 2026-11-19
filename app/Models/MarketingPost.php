<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MarketingPost extends Model
{
    use HasUuids;

    protected $guarded = [];

    // Always return the controller's explicit public shape instead of this record.
    protected $hidden = ['media_path', 'media_hash', 'approval_fingerprint'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'approved_version' => 'integer', 'scheduled_at' => 'immutable_datetime', 'publish_now' => 'boolean'];
    }

    public function fingerprint(): string
    {
        $content = [
            $this->campaign, $this->service, $this->caption, $this->media_hash,
            $this->media_mime, $this->location_id, $this->account_id,
            $this->publish_now ? 'publish_now' : $this->scheduled_at?->utc()->toIso8601String(),
        ];
        // Empty optional fields preserve approvals created before the calendar composer.
        if ($this->post_type && $this->post_type !== 'post') {
            $content[] = ['post_type' => $this->post_type];
        }
        if ($this->first_comment !== null && $this->first_comment !== '') {
            $content[] = ['first_comment' => $this->first_comment];
        }

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }

    public function hasCurrentApproval(): bool
    {
        return $this->approved_by !== null && $this->approved_version !== null
            && is_string($this->approval_fingerprint)
            && hash_equals($this->approval_fingerprint, $this->fingerprint());
    }
}
