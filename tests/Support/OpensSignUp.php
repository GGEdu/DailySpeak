<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Registration;

/**
 * A sign-up after the install's first account: the owner exists and sign-up is open, as an admin leaves it.
 */
trait OpensSignUp
{
    protected function signUpIsOpen(): User
    {
        $owner = User::factory()->admin()->create();

        app(Registration::class)->setOpen(true, $owner);

        return $owner;
    }
}
