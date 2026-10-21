<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Privacy\AudioFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Erases an account (GDPR art. 17): its rows, sessions and tokens, and the audio of its debates.
 * News articles are shared by every reader and are not touched.
 *
 * The caller signs the user out before calling this: logging out saves the user, and a deleted
 * model saved again is inserted again.
 */
class AccountEraser
{
    public function __construct(private AudioFiles $audioFiles) {}

    public function erase(User $user): void
    {
        $debateIds = $user->debates()->pluck('id')->all();

        // Rows first, in one transaction. Debates, messages and vocabulary go with the user (foreign keys
        // cascade); tokens, sessions and password resets have no foreign key, so they are deleted here.
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });

        // Files after the rows: if one cannot be deleted the account is gone anyway, and the nightly
        // purge removes what is left, since no debate row refers to it any more.
        $disk = Storage::disk(config('debate.audio.disk'));

        foreach ($debateIds as $debateId) {
            $this->audioFiles->removeDebate($disk, (string) $debateId);
        }
    }
}
