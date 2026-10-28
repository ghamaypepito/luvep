<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_role',
        'portal',
        'action',
        'description',
        'properties',
        'ip_address',
        'entity_type',
        'entity_id',
        'changes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $request = request();
            $actor = $request?->attributes->get('user_view_actor');
            $target = $request?->attributes->get('user_view_target');
            if (! $actor || ! $target) {
                return;
            }
            $log->user_id = $actor->id;
            $log->actor_name = $actor->name;
            $log->actor_role = $actor->role;
            $log->metadata = array_merge($log->metadata ?? [], ['user_view' => [
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'target_id' => $target->id, 'target_name' => $target->name, 'target_role' => $target->role,
            ]]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Write an audit-trail row for the currently authenticated actor.
     *
     * @param  string  $action  short machine key, e.g. 'login', 'user.created'
     * @param  array<string,mixed>  $attrs  overrides: description, portal, properties,
     *                                      or even user_id/actor_name for special cases
     */
    public static function record(string $action, array $attrs = []): self
    {
        $user = auth()->user();
        $request = request();

        $defaults = [
            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_role' => $user?->role,
            'portal' => $user ? ($user->isAdmin() ? 'admin' : $user->role) : 'public',
            'ip_address' => $request?->ip(),
            'description' => null,
            'properties' => null,
        ];

        return static::create(array_merge($defaults, $attrs, ['action' => $action]));
    }
}
