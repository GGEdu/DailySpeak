<?php

namespace App\Services\Privacy;

use App\Models\User;
use App\Services\AppOptions;

/**
 * Who is responsible for the data, as the privacy notice shows it (GDPR art. 13). An admin sets it on /admin/privacy;
 * the operator's values in .env are the defaults (config/privacy.php). A line stays out while it is empty.
 */
class Responsible
{
    public const CONTROLLER = 'privacy.controller';

    public const CONTACT_EMAIL = 'privacy.contact_email';

    public function __construct(private AppOptions $options) {}

    public function controller(): ?string
    {
        return $this->options->get(self::CONTROLLER) ?? config('privacy.controller');
    }

    public function contactEmail(): ?string
    {
        return $this->options->get(self::CONTACT_EMAIL) ?? config('privacy.contact_email');
    }

    /**
     * Both lines are needed for the notice to name its controller and the way to reach it.
     */
    public function configured(): bool
    {
        return filled($this->controller()) && filled($this->contactEmail());
    }

    /**
     * What the admin page shows: each line with the value saved on the panel and the default from .env.
     *
     * @return array{controller: array{value: ?string, default: ?string}, contact_email: array{value: ?string, default: ?string}}
     */
    public function settings(): array
    {
        return [
            'controller' => [
                'value' => $this->options->get(self::CONTROLLER),
                'default' => config('privacy.controller'),
            ],
            'contact_email' => [
                'value' => $this->options->get(self::CONTACT_EMAIL),
                'default' => config('privacy.contact_email'),
            ],
        ];
    }

    /**
     * Save both lines. A blank one goes back to its default in .env.
     */
    public function save(?string $controller, ?string $contactEmail, ?User $admin): void
    {
        $this->options->put(self::CONTROLLER, $controller, $admin);
        $this->options->put(self::CONTACT_EMAIL, $contactEmail, $admin);
    }
}
