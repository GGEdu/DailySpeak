<?php

namespace App\Policies;

use App\Models\Debate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DebatePolicy
{
    /**
     * Determine whether the user can see the debate and listen to its channel.
     */
    public function view(User $user, Debate $debate): bool
    {
        return $debate->user_id === $user->id;
    }

    /**
     * Determine whether the user can send a new voice turn to the debate.
     */
    public function speak(User $user, Debate $debate): Response
    {
        if (! $this->view($user, $debate)) {
            return Response::deny();
        }

        return $debate->isActive()
            ? Response::allow()
            : Response::deny('This debate has already finished.');
    }
}
