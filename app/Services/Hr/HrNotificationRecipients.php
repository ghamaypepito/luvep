<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Models\HrEvent;
use App\Models\HrWeeklySignoff;
use App\Models\User;
use Illuminate\Support\Collection;

class HrNotificationRecipients
{
    public function employee(HrEvent $event): ?HrEmployee
    {
        $subject = $event->subject;
        if ($subject instanceof \App\Models\HrChecklistTask) {
            return $subject->checklist->employee;
        }
        if ($subject instanceof HrEmployee) {
            return $subject;
        }
        if ($subject instanceof DtrEntry || $subject instanceof DtrLeave) {
            return HrEmployee::where('user_id', $subject->user_id)->first();
        }
        if ($subject instanceof HrWeeklySignoff) {
            return $subject->employee;
        }
        if ($subject && isset($subject->hr_employee_id)) {
            return HrEmployee::find($subject->hr_employee_id);
        }

        return null;
    }

    public function forEvent(HrEvent $event): Collection
    {
        $employee = $this->employee($event);
        $ids = User::whereIn('role', ['admin', 'super_admin'])->pluck('id');
        if ($event->subject instanceof \App\Models\HrRequest) {
            $case = $event->subject;
            $ids->push($employee?->user_id);
            $assignee = User::find($case->assigned_user_id);
            if ($assignee && app(\App\Policies\HrRequestPolicy::class)->manage($assignee, $case)) {
                $ids->push($assignee->id);
            }

            return User::whereIn('id', $ids->filter()->unique())->where('id', '!=', $event->actor_id)->get()->filter(fn ($u) => app(HrAccess::class)->internal($u) && ($u->isAdmin() || app(HrAccess::class)->employee($u)))->values();
        }
        if ($event->subject instanceof \App\Models\HrChecklistTask || $event->subject instanceof \App\Models\HrMilestone) {
            $owner = User::find($event->subject->owner_user_id);
            if ($owner && app(ChecklistService::class)->eligibleOwner($owner)) {
                $ids->push($owner->id);
            }
        }
        if ($employee) {
            $ids->push($employee->manager_user_id);
            if (str_starts_with($event->type, 'dtr.')) {
                $ids->push($employee->reviewer_user_id);
            }
            if (! ($event->subject instanceof \App\Models\HrChecklistTask || $event->subject instanceof \App\Models\HrMilestone) || $event->subject->employee_visible || $event->subject->owner_user_id === $employee->user_id) {
                $ids->push($employee->user_id);
            }
        }

        return User::whereIn('id', $ids->filter()->unique())->where('id', '!=', $event->actor_id)->get()->filter(fn ($u) => app(HrAccess::class)->internal($u) && ($u->isAdmin() || app(HrAccess::class)->employee($u)))->values();
    }

    public function link(HrEvent $event, User $user): string
    {
        $employee = $this->employee($event);
        $access = app(HrAccess::class);
        $path = 'notifications';
        if ($event->subject instanceof \App\Models\HrMilestone) {
            return app(HrContext::class)->url('milestones');
        }
        if ($event->subject instanceof \App\Models\HrChecklistTask) {
            return app(HrContext::class)->url('checklists');
        }
        if ($event->subject instanceof \App\Models\HrEquipment) {
            return app(HrContext::class)->url('equipment');
        }
        if ($event->subject instanceof \App\Models\HrRequest) {
            return app(HrContext::class)->url('requests');
        }
        if ($employee && str_starts_with($event->type, 'leave.')) {
            if ($user->id === $employee->user_id) {
                $path = 'leave';
            } elseif ($access->allows($user, 'leave.review', $employee) || $access->allows($user, 'leave.fallback', $employee) || $access->allows($user, 'leave.emergency', $employee)) {
                $path = 'leave/review';
            }
        } elseif ($employee && str_starts_with($event->type, 'dtr.')) {
            if ($user->id === $employee->user_id) {
                $path = 'dtr';
            } elseif ($access->allows($user, 'dtr.review', $employee)) {
                $path = 'dtr/review';
            }
        }

        return app(HrContext::class)->url($path);
    }
}
