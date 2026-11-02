<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Notifications\LeadConvertedToDepartment;
use App\Support\LeadAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ClientPathways
{
    public function definitions(): array
    {
        return [
            'education' => [
                'label' => 'Education', 'flag' => 'is_student', 'prefix' => 'student',
                'column' => 'education_stage', 'stages' => Lead::EDUCATION_STAGES,
                'default_stage' => 'New Lead', 'assignee_column' => null, 'assignees' => [],
                'roles' => ['education'],
            ],
            'immigration' => [
                'label' => 'Immigration', 'flag' => 'is_immigration_case', 'prefix' => 'immigration',
                'column' => 'immigration_stage', 'stages' => Lead::IMMIGRATION_STAGES,
                'default_stage' => Lead::IMMIGRATION_STAGES[0],
                'assignee_column' => 'immigration_assignee', 'assignees' => Lead::IMMIGRATION_STAGE_ASSIGNEES,
                'roles' => array_merge(['immigration'], User::IMMIGRATION_ROLES),
            ],
            'english' => [
                'label' => 'English', 'flag' => 'is_english_student', 'prefix' => 'english',
                'column' => 'english_stage', 'stages' => Lead::ENGLISH_STAGES,
                'default_stage' => Lead::ENGLISH_STAGES[0],
                'assignee_column' => 'english_assignee', 'assignees' => Lead::ENGLISH_STAGE_ASSIGNEES,
                'roles' => ['english'],
            ],
            'accommodation' => [
                'label' => 'Accommodation', 'flag' => 'is_accommodation_client', 'prefix' => 'accommodation',
                'column' => null, 'stages' => [], 'default_stage' => null,
                'assignee_column' => null, 'assignees' => [], 'roles' => ['accommodation'],
            ],
        ];
    }

    public function active(Lead $lead, string $pathway): bool
    {
        return match ($pathway) {
            'education' => (bool) $lead->is_student,
            'immigration' => $lead->is_immigration_case
                || in_array($lead->education_stage, Lead::EDUCATION_STAGES_IMMIGRATION, true),
            'english' => $lead->is_english_student || filled($lead->english_stage) || $lead->stage === 'English Pro',
            'accommodation' => (bool) $lead->is_accommodation_client,
            default => false,
        };
    }

    public function canManage(User $user, Lead $lead): bool
    {
        // Sales must not regain access to clients whose membership is derived
        // from a stage rather than a conversion flag.
        if ($user->role === 'sales') {
            return ! collect(array_keys($this->definitions()))->contains(fn ($key) => $this->active($lead, $key));
        }

        return LeadAccess::canView($user, $lead)
            || ($user->role === 'english' && $this->active($lead, 'english'))
            || (in_array($user->role, array_merge(['immigration'], User::IMMIGRATION_ROLES), true) && $this->active($lead, 'immigration'));
    }

    public function options(User $user, Lead $lead): array
    {
        $options = [];
        foreach ($this->definitions() as $key => $definition) {
            $stage = $definition['column'] ? $lead->{$definition['column']} : null;
            $options[] = [
                'key' => $key, 'label' => $definition['label'], 'active' => $this->active($lead, $key),
                'stage' => $stage, 'stages' => $definition['stages'],
                'default_stage' => in_array($stage, $definition['stages'], true) ? $stage : $definition['default_stage'],
                'assignee' => $definition['assignee_column'] ? $lead->{$definition['assignee_column']} : null,
                'assignees' => $definition['assignees'],
                'open_url' => $this->openUrl($user, $lead, $key),
            ];
        }

        return $options;
    }

    private function openUrl(User $user, Lead $lead, string $pathway): ?string
    {
        if (! $this->active($lead, $pathway)) {
            return null;
        }
        $admin = in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true);
        $ownPortal = $user->role === $pathway
            || ($pathway === 'immigration' && in_array($user->role, User::IMMIGRATION_ROLES, true));
        if (! $admin && ! $ownPortal) {
            return null;
        }
        if ($pathway === 'immigration' && $lead->is_immigration_case) {
            $base = $admin ? '/admin/immigration' : '/portal/immigration';

            return "{$base}/cases/{$lead->id}/profile";
        }
        $base = $admin ? '/admin' : "/portal/{$pathway}";

        return "{$base}/leads/{$lead->id}";
    }

    public function add(User $user, Lead $client, array $data): bool
    {
        return DB::transaction(function () use ($user, $client, $data) {
            $lead = Lead::whereKey($client->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canManage($user, $lead), 403);
            $key = $data['pathway'];
            if ($this->active($lead, $key)) {
                return false;
            }

            $definition = $this->definitions()[$key];
            $lead->{$definition['flag']} = true;
            $lead->{$definition['prefix'].'_converted_at'} ??= now();
            $lead->{$definition['prefix'].'_converted_by'} ??= $user->id;
            if ($column = $definition['column']) {
                $existing = $lead->{$column};
                $lead->{$column} = $data['stage'] ?? (in_array($existing, $definition['stages'], true) ? $existing : $definition['default_stage']);
            }
            if (($assigneeColumn = $definition['assignee_column']) && filled($data['assignee'] ?? null)) {
                $lead->{$assigneeColumn} = $data['assignee'];
            }
            $stage = $column ? $lead->{$column} : null;
            $assignee = $assigneeColumn ? $lead->{$assigneeColumn} : null;
            $lead->pushStageHistory($key, $stage, $assignee);

            // Initializing a department is not a new visa/status event. Avoid
            // stage-email observers, inferred lodgement dates, and owner changes.
            $lead->saveQuietly();
            $lead->recordStaffActivity('Added '.$definition['label'].' pathway');
            $note = $data['note'] ?? null;
            if (filled($note)) {
                LeadNote::create([
                    'lead_id' => $lead->id, 'user_id' => $user->id,
                    'author_name' => $user->name, 'author_role' => $user->role,
                    'kind' => 'general', 'body' => 'Added '.$definition['label'].' pathway: '.$note,
                ]);
            }
            ActivityLog::record('client.pathway_added', [
                'description' => 'Added '.$definition['label'].' pathway',
                'properties' => [
                    'subject_type' => 'Lead', 'subject_id' => $lead->id,
                    'pathway' => $key, 'stage' => $stage, 'assignee' => $assignee, 'note' => $note,
                ],
            ]);

            DB::afterCommit(function () use ($lead, $user, $key, $definition) {
                try {
                    $recipients = User::whereIn('role', $definition['roles'])->get();
                    if ($recipients->isEmpty()) {
                        $recipients = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->get();
                    }
                    Notification::send($recipients, new LeadConvertedToDepartment($lead, $definition['label'], $user->name));
                } catch (\Throwable $e) {
                    Log::warning('Pathway team notification failed', ['lead_id' => $lead->id, 'pathway' => $key, 'error' => $e->getMessage()]);
                }
                if ($key === 'immigration') {
                    try {
                        app(EmailAutomationService::class)->fire($lead->is_student ? 'immigration.education.handoff' : 'immigration.case.converted', $lead, [
                            'visa_type' => $lead->inz_visa_type ?? '',
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Pathway conversion automation failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
                    }
                }
            });

            return true;
        });
    }
}
