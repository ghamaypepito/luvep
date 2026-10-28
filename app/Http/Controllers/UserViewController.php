<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserView;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserViewController extends Controller
{
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor?->isSuperAdmin() && $actor->is_active, 403);

        return $actor;
    }

    public function index(Request $request)
    {
        $actor = $this->actor($request);
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $users = User::where('is_active', true)->whereKeyNot($actor->id)
            ->when(trim($data['q'] ?? ''), function ($query, $q) {
                $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'));
            })->orderBy('name')->limit(30)->get(['id', 'name', 'email', 'role']);

        return response()->json(['users' => $users]);
    }

    public function store(Request $request, User $user)
    {
        $actor = $this->actor($request);
        abort_unless($user->is_active && $user->id !== $actor->id, 422, 'Select another active user.');
        UserView::audit($request, $actor, $user, $request->session()->has('user_view') ? 'user_view.switched' : 'user_view.started');
        $request->session()->forget(['hr_preview_employee_id', 'lead_portal_preview_id']);
        $request->session()->put('user_view', ['target_id' => $user->id, 'token' => (string) Str::uuid()]);
        $request->session()->regenerate();

        return response()->json(['redirect' => $request->getHost() === config('hr.host') ? '/' : $user->homeRoute()]);
    }

    public function destroy(Request $request)
    {
        $actor = $this->actor($request);
        UserView::audit($request, $actor, User::find($request->session()->get('user_view.target_id')), 'user_view.ended');
        $request->session()->forget('user_view');
        $request->session()->regenerate();

        return response()->json(['redirect' => $request->getHost() === config('hr.host') ? '/' : $actor->homeRoute()]);
    }
}
