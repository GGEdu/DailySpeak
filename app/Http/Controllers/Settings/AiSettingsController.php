<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAiKey;
use App\Services\Ai\AiConnections;
use App\Services\Ai\AiKeyCheck;
use App\Services\Ai\ProviderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own AI connection (Settings → AI): one key per provider of the closed list, and the provider, model and
 * voice picked for each task. A key is proved before it is kept, shown only by its last four characters, and never
 * returned.
 */
class AiSettingsController extends Controller
{
    /**
     * A model name or a voice: letters, digits and the characters providers use in ids (gpt-4o-mini, openai/gpt-oss-20b).
     */
    private const MODEL_PATTERN = '/^[A-Za-z0-9._\/:@-]+$/';

    /**
     * The value that picks the platform's connection for a task, where the user is allowed it.
     */
    private const PLATFORM = 'platform';

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $keys = $user->aiKeys()->get()->keyBy('provider');
        $tasks = $user->aiTasks()->get()->keyBy('task');

        return Inertia::render('settings/Ai', [
            'providers' => collect(ProviderCatalog::ids())
                ->map(fn (string $id) => [
                    'id' => $id,
                    'label' => ProviderCatalog::label($id),
                    'capabilities' => ProviderCatalog::capabilities($id),
                    'key' => $this->keyState($keys->get($id)),
                ])
                ->values()
                ->all(),
            'tasks' => collect(array_keys(ProviderCatalog::TASK_CAPABILITY))
                ->mapWithKeys(fn (string $task) => [$task => $this->taskState($tasks->get($task), $task, $user->mayUsePlatformAi())])
                ->all(),
            'platform' => ['allowed' => $user->mayUsePlatformAi()],
            'suggestions' => collect(ProviderCatalog::ids())
                ->mapWithKeys(fn (string $id) => [$id => ProviderCatalog::suggestions($id)])
                ->all(),
        ]);
    }

    /**
     * Prove the key with the provider, and keep it if it works. A key that does not work is not kept, and the page is
     * told why without the provider's own words.
     */
    public function saveKey(Request $request, string $provider, AiKeyCheck $check): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:500'],
        ], [
            'key.required' => 'Enter the key.',
            'key.max' => 'The key is too long.',
        ]);

        $key = trim($data['key']);

        if ($key === '') {
            return back()->withErrors(['key' => 'Enter the key.']);
        }

        $result = $check->run($provider, $key);

        if (! $result['ok']) {
            return back()->withErrors(['key' => $result['message']]);
        }

        $request->user()->aiKeys()->updateOrCreate(
            ['provider' => $provider],
            ['key' => $key, 'last4' => substr($key, -4), 'verified_at' => now()],
        );

        return back()->with('status', 'Key saved. It is stored encrypted and is not shown again.');
    }

    /**
     * Remove the user's key for a provider. The tasks that picked it keep their choice and report it missing when used.
     */
    public function forgetKey(Request $request, string $provider): RedirectResponse
    {
        $request->user()->aiKeys()->where('provider', $provider)->delete();

        return back()->with('status', 'Key removed.');
    }

    /**
     * Save the provider, model and voice picked for each task. A task that is not sent keeps its choice; a task sent
     * without a provider loses it. Every task is checked before any is saved: its provider must do that task, the user
     * must have its key (or be allowed the platform), and it must name a model (and a voice, for tts).
     */
    public function saveTasks(Request $request): RedirectResponse
    {
        $tasks = array_keys(ProviderCatalog::TASK_CAPABILITY);
        $providers = [...ProviderCatalog::ids(), self::PLATFORM];

        $rules = [];

        foreach ($tasks as $task) {
            $rules["tasks.{$task}.provider"] = ['nullable', 'string', Rule::in($providers)];
            $rules["tasks.{$task}.model"] = ['nullable', 'string', 'max:120', 'regex:'.self::MODEL_PATTERN];
            $rules["tasks.{$task}.voice"] = ['nullable', 'string', 'max:60', 'regex:'.self::MODEL_PATTERN];
        }

        $validated = $request->validate($rules, [
            '*.regex' => 'Use only letters, digits and . _ / : @ -.',
            '*.max' => 'The name is too long.',
            '*.in' => 'Choose a provider from the list.',
        ]);

        $user = $request->user();
        $plan = [];

        foreach ($tasks as $task) {
            if (! $request->has("tasks.{$task}")) {
                continue;
            }

            $picked = $validated['tasks'][$task] ?? [];
            $plan[$task] = $this->planTask($user, $task, $picked);
        }

        foreach ($plan as $task => $choice) {
            if ($choice === null) {
                $user->aiTasks()->where('task', $task)->delete();

                continue;
            }

            $user->aiTasks()->updateOrCreate(['task' => $task], $choice);
        }

        return back()->with('status', 'Connections saved.');
    }

    /**
     * Prove the saved key of a provider again, with the provider. Throttled: each check is a request to the provider.
     */
    public function test(Request $request, string $provider, AiKeyCheck $check): JsonResponse
    {
        $key = $request->user()->aiKeys()->where('provider', $provider)->first();

        if ($key === null) {
            return response()->json(['ok' => false, 'message' => 'No key is saved for this provider.']);
        }

        $result = $check->run($provider, $key->key);

        if ($result['ok']) {
            $key->update(['verified_at' => now()]);
        }

        return response()->json($result);
    }

    /**
     * The state of one key for the page: whether it is set, its last four characters and when it was proved.
     *
     * @return array{set: bool, last4: ?string, verified_at: ?string}
     */
    private function keyState(?UserAiKey $key): array
    {
        return [
            'set' => $key !== null,
            'last4' => $key?->last4,
            'verified_at' => $key?->verified_at?->toIso8601String(),
        ];
    }

    /**
     * The state of one task for the page. A task never picked has no provider.
     *
     * @return array{provider: ?string, model: ?string, voice?: ?string}
     */
    private function taskState(mixed $picked, string $task, bool $platformAllowed): array
    {
        $state = [
            // A task never picked runs on the platform for whoever may use it (AiConnections): show it that way.
            'provider' => $picked?->provider ?? ($platformAllowed ? AiConnections::PLATFORM : null),
            'model' => $picked?->model,
        ];

        return $task === 'tts' ? [...$state, 'voice' => $picked?->voice] : $state;
    }

    /**
     * Check one task's choice and return what to save for it, or null to clear it. Refuses with a message under the
     * task's own key, so the page can say which line is wrong.
     *
     * @param  array{provider?: ?string, model?: ?string, voice?: ?string}  $picked
     * @return array{provider: string, model: ?string, voice: ?string}|null
     *
     * @throws ValidationException
     */
    private function planTask(User $user, string $task, array $picked): ?array
    {
        $provider = $picked['provider'] ?? null;

        if ($provider === null) {
            return null;
        }

        if ($provider === self::PLATFORM) {
            if (! $user->mayUsePlatformAi()) {
                $this->refuse($task, 'The platform is not available to your account.');
            }

            return ['provider' => self::PLATFORM, 'model' => null, 'voice' => null];
        }

        if (! ProviderCatalog::supportsTask($provider, $task)) {
            $this->refuse($task, 'This provider cannot do this task.');
        }

        if (! $user->mayUsePlatformAi() && ! $user->aiKeys()->where('provider', $provider)->exists()) {
            $this->refuse($task, 'Save a key for this provider first.');
        }

        $suggested = ProviderCatalog::suggestions($provider);
        $model = $picked['model'] ?? $suggested[$task];

        if ($model === null || $model === '') {
            $this->refuse($task, 'Enter the model.');
        }

        $voice = null;

        if ($task === 'tts') {
            $voice = $picked['voice'] ?? $suggested['voice'];

            if ($voice === null || $voice === '') {
                $this->refuse($task, 'Enter the voice.');
            }
        }

        return ['provider' => $provider, 'model' => $model, 'voice' => $voice];
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $task, string $message): never
    {
        throw ValidationException::withMessages(["tasks.{$task}" => $message]);
    }
}
