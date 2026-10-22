<?php

namespace Tests\Support;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Services\Captcha\AltchaChallenges;

/**
 * Solves ALTCHA challenges the way the widget does, so a test can post a real human-check payload.
 */
trait SolvesAltcha
{
    /**
     * The payload the widget posts: a challenge from the server, solved here.
     */
    protected function solvedAltcha(?Challenge $challenge = null): string
    {
        $challenge ??= app(AltchaChallenges::class)->issue();

        $solution = (new Altcha)->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2,
            challenge: $challenge,
        ));

        $this->assertNotNull($solution, 'The test challenge could not be solved.');

        return (new Payload($challenge, $solution))->toBase64();
    }

    /**
     * The form data with a solved human check added.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withAltcha(array $data): array
    {
        return [...$data, 'altcha' => $this->solvedAltcha()];
    }
}
