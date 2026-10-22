<?php

namespace App\Services\Ai;

use Laravel\Ai\AiManager;
use LogicException;

/**
 * One resolved connection for one task: which provider pays, which model and voice, and the key it uses. It is made
 * for one call and dies with it. It holds a key, so it is never serialised: a job carries the ids it needs (the user,
 * the debate), and resolves its connection where the work runs.
 *
 * providerName() builds the provider in the current process (AiManager::build) and returns the name the Laravel AI
 * SDK resolves it by. Nothing is written to config/: two users' keys cannot meet in one worker.
 */
final class AiConnection
{
    /**
     * @param  string  $task  conversation, writing, stt or tts
     * @param  string  $paidBy  user or platform
     * @param  string  $provider  a catalogue id, or the name of the platform's provider in config/ai.php
     * @param  array<string, mixed>  $config  The driver, key and address the SDK builds the provider from.
     */
    public function __construct(
        public readonly string $task,
        public readonly string $paidBy,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ?string $voice,
        private readonly array $config,
    ) {}

    /**
     * Build the provider in this process and return its name, for the agent's provider() and the Audio and
     * Transcription calls.
     */
    public function providerName(): string
    {
        return app(AiManager::class)->build($this->config)->name();
    }

    /**
     * The key stays in this object. A connection that reaches a serialiser is a leak, so that fails loudly.
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException('An AI connection holds a key and is never serialised, queued or stored.');
    }
}
