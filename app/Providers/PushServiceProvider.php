<?php

namespace App\Providers;

use App\Services\Push\Providers\MockFcmProvider;
use App\Services\Push\Providers\WebPushProvider;
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
            $manager = new PushNotificationManager;
            $manager->registerDriver('webpush', new WebPushProvider);
            $manager->registerDriver('fcm', new MockFcmProvider);

            return $manager;
        });
    }
}
