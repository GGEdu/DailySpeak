<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:admin {email : Email of the user} {--revoke : Remove admin rights instead of granting them}')]
#[Description('Grant (or revoke) admin rights: unlimited voice turns, news sources and Horizon')]
class MakeUserAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if ($user === null) {
            $this->components->error("No user with email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->components->info($user->is_admin
            ? "{$user->email} is now an admin."
            : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
