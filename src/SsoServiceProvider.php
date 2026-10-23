<?php

namespace Webimpian\Sso;

use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Webimpian\Sso\Console\ApproveCommand;
use Webimpian\Sso\Filament\LogoutResponse;
use Webimpian\Sso\Filament\V3LogoutResponse;
use Webimpian\Sso\Http\Middleware\KeepSsoSession;

class SsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sso.php', 'sso');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/sso.php' => config_path('sso.php')], 'sso-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/sso')], 'sso-views');

        $this->loadRoutesFrom(__DIR__.'/../routes/sso.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sso');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Through the kernel: one built later would overwrite groups pushed to the router.
        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('web', KeepSsoSession::class);
        }

        Event::listen(Logout::class, [SignOut::class, 'remember']);

        if (interface_exists(\Filament\Auth\Http\Responses\Contracts\LogoutResponse::class)) {
            $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LogoutResponse::class, LogoutResponse::class);
        } elseif (interface_exists(\Filament\Http\Responses\Auth\Contracts\LogoutResponse::class)) {
            $this->app->bind(\Filament\Http\Responses\Auth\Contracts\LogoutResponse::class, V3LogoutResponse::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ApproveCommand::class]);
        }
    }
}
