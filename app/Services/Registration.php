<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may open an account. A new install takes its first account, which becomes the owner: an admin, with the
 * address already confirmed. After that, sign-up is closed until an admin opens it on /admin.
 */
class Registration
{
    public const OPEN_KEY = 'registration.open';

    /**
     * The key of the PostgreSQL advisory lock that orders sign-ups (see create()).
     */
    private const SIGN_UP_LOCK = 602210;

    public function __construct(private AppOptions $options) {}

    /**
     * Whether a visitor may open an account now. An install without accounts always accepts the first one. After
     * that, sign-up is open only when an admin has opened it: no saved choice means closed.
     */
    public function isOpen(): bool
    {
        if (! User::query()->exists()) {
            return true;
        }

        return $this->options->get(self::OPEN_KEY) === '1';
    }

    /**
     * Open or close sign-up. Accounts that already exist are not affected.
     */
    public function setOpen(bool $open, ?User $admin): void
    {
        $this->options->put(self::OPEN_KEY, $open ? '1' : '0', $admin);
    }

    /**
     * Create the account, or return null when sign-up is closed. The check and the insert run in one transaction,
     * after the sign-up lock: two sign-ups into an empty install cannot both see no account and both become owner.
     *
     * @param  array<string, mixed>  $attributes  name, email, password and current_level, as validated.
     */
    public function create(array $attributes): ?User
    {
        return DB::transaction(function () use ($attributes) {
            $this->lockSignUps();

            if (! $this->isOpen()) {
                return null;
            }

            $owner = ! User::query()->exists();

            $user = new User($attributes);
            $user->forceFill([
                'is_admin' => $owner,
                // A new install may have no mail server yet, and the owner must be able to sign in at once.
                'email_verified_at' => $owner ? now() : null,
            ])->save();
            $user->recordConsent();

            return $user;
        });
    }

    /**
     * Held until the transaction ends. Other drivers serialise their writes already.
     */
    private function lockSignUps(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(?)', [self::SIGN_UP_LOCK]);
        }
    }
}
