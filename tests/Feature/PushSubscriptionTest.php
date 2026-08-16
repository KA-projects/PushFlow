<?php

namespace Tests\Feature;

use App\Services\Push\PushNotificationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Проверяем, что PushNotificationManager зарегистрирован в контейнере как singleton.
     */
    public function test_manager_is_resolvable_as_singleton(): void
    {
        $first = $this->app->make(PushNotificationManager::class);
        $second = $this->app->make(PushNotificationManager::class);

        $this->assertSame($first, $second);
    }
}
