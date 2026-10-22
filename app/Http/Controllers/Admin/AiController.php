<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\PlatformAi;
use App\Services\AiConnectionCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin page for the platform's AI connection, the models per task and the key (App\Services\Ai\PlatformAi). A
 * blank field means the value in config/ or .env applies. The values apply from the next call: nothing is written to
 * config/.
 */
class AiController extends Controller
{
    /**
     * A model name or a voice: letters, digits and the characters providers use in ids (gpt-4o-mini, openai/gpt-oss-20b).
     */
    private const MODEL_PATTERN = '/^[A-Za-z0-9._\/:@-]+$/';

    public function edit(PlatformAi $ai): Response
    {
        return Inertia::render('admin/Ai', [
            'settings' => $ai->settings(),
        ]);
    }

    /**
     * Save the address and any of the models. A field that is not sent keeps its saved value.
     */
    public function update(Request $request, PlatformAi $ai): RedirectResponse
    {
        $rules = [
            'base_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https'],
            'models' => ['sometimes', 'nullable', 'array'],
        ];

        foreach (array_keys(PlatformAi::FIELDS) as $field) {
            $rules["models.{$field}"] = ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.self::MODEL_PATTERN];
        }

        $validated = $request->validate($rules, [
            'base_url.url' => 'Enter a web address that starts with http:// or https://.',
            'models.*.regex' => 'Use only letters, digits and . _ / : @ -.',
            'models.*.max' => 'The name is too long.',
        ]);

        if ($request->has('base_url')) {
            $ai->setBaseUrl($validated['base_url'] ?? null, $request->user());
        }

        foreach (array_keys(PlatformAi::FIELDS) as $field) {
            if ($request->has("models.{$field}")) {
                $ai->setModel($field, $validated['models'][$field] ?? null, $request->user());
            }
        }

        return back()->with('status', 'AI settings saved. They apply from the next request.');
    }

    /**
     * Save the key, encrypted. It is never shown again: the page only shows its last four characters.
     */
    public function saveKey(Request $request, PlatformAi $ai): RedirectResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string', 'max:500'],
        ], [
            'api_key.required' => 'Enter the key.',
            'api_key.max' => 'The key is too long.',
        ]);

        $ai->setApiKey(trim($validated['api_key']), $request->user());

        return back()->with('status', 'AI key saved. It is stored encrypted and is not shown again.');
    }

    /**
     * Go back to the key in .env.
     */
    public function forgetKey(Request $request, PlatformAi $ai): RedirectResponse
    {
        $ai->forgetApiKey($request->user());

        return back()->with('status', 'The key saved on the panel is removed. The key in .env applies.');
    }

    /**
     * Check the connection with the settings in force. Throttled: each check is a request to the provider.
     */
    public function test(AiConnectionCheck $check): JsonResponse
    {
        return response()->json($check->run());
    }
}
