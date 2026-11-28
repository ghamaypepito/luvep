<?php

namespace App\Services\Accommodation;

use App\Models\User;

class OnboardingOwners
{
    public static function options(): array
    {
        $owners = config('accommodation_onboarding.owners', []);
        $accounts = User::where('is_active', true)
            ->whereIn('role', ['accommodation', User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->whereIn('email', array_values($owners))->get(['id', 'email'])->keyBy('email');
        $options = [];
        foreach ($owners as $name => $email) {
            if ($account = $accounts->get($email)) {
                $options[] = ['id' => $account->id, 'name' => $name];
            }
        }

        return $options;
    }
}
