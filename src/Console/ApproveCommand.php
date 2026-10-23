<?php

namespace Webimpian\Sso\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webimpian\Sso\Sso;

class ApproveCommand extends Command
{
    protected $signature = 'sso:approve {email}';

    protected $description = 'Let someone waiting for approval sign in through the portal';

    public function handle(): int
    {
        $model = Sso::userModel();
        $user = $model::query()->whereRaw('lower(email) = ?', [Str::lower($this->argument('email'))])->first();

        if ($user === null) {
            $this->error('No account has that email.');

            return self::FAILURE;
        }

        $user->forceFill(['sso_pending' => false])->save();

        $this->info("{$user->email} can sign in now.");

        return self::SUCCESS;
    }
}
