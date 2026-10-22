<?php

namespace App\Http\Controllers;

use App\Services\Account\AccountEraser;
use App\Services\Account\AccountExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AccountController extends Controller
{
    /**
     * Download everything the account holds as a JSON file.
     */
    public function export(Request $request, AccountExporter $exporter): JsonResponse
    {
        $filename = 'dailyspeak-'.now()->format('Y-m-d').'.json';

        return response()->json($exporter->export($request->user()), 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Erase the account and everything in it, then sign out.
     */
    public function destroy(Request $request, AccountEraser $eraser): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        // Signing out first: the guard saves the user to rotate its remember token, and that save would
        // insert the row again once the account is deleted.
        Auth::guard('web')->logout();
        $eraser->erase($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // As on logout: the next page forgets the key of the encrypted history, so "Back" cannot show the deleted account.
        Inertia::clearHistory();

        return to_route('home')->with('status', 'Your account and its data have been deleted.');
    }
}
