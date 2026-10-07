<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserVocabulary;

class UserVocabularyPolicy
{
    /**
     * Determine whether the user can review the word.
     */
    public function update(User $user, UserVocabulary $vocabulary): bool
    {
        return $vocabulary->user_id === $user->id;
    }

    /**
     * Determine whether the user can remove the word from their deck.
     */
    public function delete(User $user, UserVocabulary $vocabulary): bool
    {
        return $vocabulary->user_id === $user->id;
    }
}
