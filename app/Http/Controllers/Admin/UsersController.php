<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountEraser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The users of the platform, for the owner: how each one stands this month (calls and failures), whether they have a
 * key and whether they may use the platform's connection. No conversations, no keys.
 */
class UsersController extends Controller
{
    public function index(): Response
    {
        $since = now()->startOfMonth();

        $users = User::query()
            ->withExists('aiKeys as has_key')
            ->withCount([
                'aiUsage as month_calls' => fn ($query) => $query->where('created_at', '>=', $since),
                'aiUsage as month_errors' => fn ($query) => $query->where('created_at', '>=', $since)->where('ok', false),
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'platform_ai' => $user->platform_ai,
                'has_key' => (bool) $user->has_key,
                'month_calls' => $user->month_calls,
                'month_errors' => $user->month_errors,
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Users', ['users' => $users]);
    }

    /**
     * Change whether the user may use the platform's connection, or whether they are an admin. An admin cannot take their
     * own admin rights away.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'platform_ai' => ['sometimes', 'boolean'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_admin'] ?? true) === false && $user->is($request->user())) {
            return back()->withErrors(['is_admin' => 'You cannot remove your own admin rights.']);
        }

        // Not mass-assignable on purpose: only these two pages set them.
        $user->forceFill($data)->save();

        return back()->with('status', 'User saved.');
    }

    /**
     * Erase the account, as the user would erase their own (App\Services\Account\AccountEraser). Not the owner's own.
     */
    public function destroy(Request $request, User $user, AccountEraser $eraser): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['delete' => 'Delete your own account from Settings, not here.']);
        }

        $eraser->erase($user);

        return back()->with('status', 'Account deleted.');
    }
}
