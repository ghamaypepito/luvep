<?php

namespace App\Support;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CaseRecordAccess
{
    public const NAME_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    public static function canManage(Request $request): bool
    {
        return $request->user()?->is_active && $request->user()->isAdmin()
            && ! $request->attributes->has('user_view_actor');
    }

    public static function authorizeArchive(Request $request, array $ids): void
    {
        abort_if(! self::canManage($request) && Lead::immigrationCase()->whereKey($ids)->exists(), 403);
    }

    public static function canCorrectNames(Request $request): bool
    {
        $user = $request->user();

        return $user?->is_active && ! $request->attributes->has('user_view_actor')
            && ($user->isAdmin() || ($user->role === \App\Models\User::ROLE_IMMIGRATION_MANAGER
                && in_array('case_name_correction', $user->grantedModules(), true)));
    }

    public static function nameLocked(Request $request, Lead $record): bool
    {
        return ! self::canCorrectNames($request) && self::isExistingCase($record);
    }

    public static function preserveCaseIdentity(Lead $record, array $submission): array
    {
        return self::isExistingCase($record)
            ? array_diff_key($submission, array_flip([...self::NAME_FIELDS, 'lead_id']))
            : $submission;
    }

    private static function isExistingCase(Lead $record): bool
    {
        return $record->getOriginal('is_immigration_case')
            || in_array($record->getOriginal('education_stage'), Lead::EDUCATION_STAGES_IMMIGRATION, true);
    }

    /** Staff can save other case details while preserving the existing name. */
    public static function authorizeNameChanges(Request $request, Model $record, array $values, array $fields): void
    {
        if (self::canCorrectNames($request)) {
            return;
        }
        foreach ($fields as $field) {
            if (array_key_exists($field, $values) && (string) $values[$field] !== (string) $record->getOriginal($field)) {
                throw ValidationException::withMessages([$field => 'Case name corrections require Admin access or an explicit Name Correction grant.']);
            }
        }
    }
}
