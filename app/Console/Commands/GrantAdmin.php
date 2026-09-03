<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * There is no in-app way to promote a user to admin (an admin-panel endpoint for it
 * would let the first admin mint arbitrarily many more, widening the attack surface
 * for no MVP-scoped benefit) — the first admin, and any afterward, are granted here.
 */
class GrantAdmin extends Command
{
    protected $signature = 'admin:grant {email}';

    protected $description = 'Grant administrator access to an existing user by email';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("{$user->email} is already an administrator.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("Granted administrator access to {$user->email}.");

        return self::SUCCESS;
    }
}
