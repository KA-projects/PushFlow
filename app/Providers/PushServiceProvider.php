<?php

namespace App\Providers;

use App\Services\Push\PushNotificationManager;
use Illuminate\Support\ServiceProvider;

class PushServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PushNotificationManager::class, function ($app) {
            return new PushNotificationManager;
        });
    }
}
