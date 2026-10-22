<?php

namespace App\Services\Captcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The ALTCHA check that guards sign-up and password reset. The server issues signed challenges and accepts
 * each solution once. Nothing is stored per visitor: a challenge carries its own signature, and only the
 * solutions already used are remembered, until their challenge expires.
 */
class AltchaChallenges
{
    public const FAILED_MESSAGE = 'The human check is missing or has expired. Wait for it to finish, then try again.';

    /**
     * A new challenge for the widget to solve.
     */
    public function issue(): Challenge
    {
        return $this->altcha()->createChallenge(new CreateChallengeOptions(
            algorithm: $this->algorithm(),
            cost: (int) config('altcha.cost'),
            keyPrefix: '', // empty: the server draws a random prefix of keyPrefixLength bytes
            keyPrefixLength: (int) config('altcha.key_prefix_bytes'),
            expiresAt: time() + (int) config('altcha.expires_in'),
        ));
    }

    /**
     * Accepts a solved check exactly once. Anything else is a validation error on the `altcha` field.
     *
     * @throws ValidationException
     */
    public function ensureSolved(mixed $payload): void
    {
        if (! $this->accept($payload)) {
            throw ValidationException::withMessages(['altcha' => self::FAILED_MESSAGE]);
        }
    }

    private function accept(mixed $payload): bool
    {
        if (! is_string($payload) || $payload === '') {
            return false;
        }

        try {
            $payload = Payload::fromBase64($payload);
        } catch (\InvalidArgumentException|\TypeError|\ValueError) {
            // Not a payload this server could have issued: refused like any other wrong answer.
            return false;
        }

        $parameters = $payload->challenge->parameters;

        // The algorithm and the expiry are signed with the rest of the challenge; a challenge without an
        // expiry is never one this server issues.
        if ($parameters->algorithm !== $this->algorithm()->getAlgorithmName() || $parameters->expiresAt === null) {
            return false;
        }

        $result = $this->altcha()->verifySolution(new VerifySolutionOptions(
            payload: $payload,
            algorithm: $this->algorithm(),
        ));

        if (! $result->verified) {
            return false;
        }

        // Keyed by the challenge's signature: one challenge gives one solution, whatever counter a second
        // solver finds. The record outlives the challenge, so it cannot be replayed while the challenge is valid.
        $lifetime = (int) ceil($parameters->expiresAt - microtime(true)) + 60;

        return Cache::add('altcha:solved:'.$payload->challenge->signature, true, max(60, $lifetime));
    }

    private function altcha(): Altcha
    {
        return new Altcha(hmacSignatureSecret: $this->signatureSecret());
    }

    private function algorithm(): Pbkdf2
    {
        return new Pbkdf2;
    }

    /**
     * A key of its own, derived from APP_KEY: no new secret to create, store or rotate by hand.
     */
    private function signatureSecret(): string
    {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            // An empty key would make the signatures forgeable: refuse to issue or verify without one.
            throw new \RuntimeException('APP_KEY is not set; the human check cannot sign its challenges.');
        }

        return hash_hmac('sha256', 'dailyspeak:altcha:signature:v1', $appKey);
    }
}
