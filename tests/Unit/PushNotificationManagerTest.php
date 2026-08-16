<?php

namespace Tests\Unit;

use App\Models\PushSubscription;
use App\Services\Push\PushNotificationManager;
use App\Services\Push\PushProviderInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PushNotificationManagerTest extends TestCase
{
    /**
     * Проверяем, что запрос незарегистрированного драйвера выбрасывает InvalidArgumentException.
     */
    public function test_driver_throws_exception_for_unregistered_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Push driver [fcm] is not registered.');

        (new PushNotificationManager)->driver('fcm');
    }

    /**
     * Проверяем, что registerDriver возвращает тот же экземпляр менеджера (fluent API).
     */
    public function test_register_driver_returns_self(): void
    {
        $manager = new PushNotificationManager;

        $this->assertSame($manager, $manager->registerDriver('webpush', $this->createMock(PushProviderInterface::class)));
    }

    /**
     * Проверяем, что зарегистрированный драйвер возвращается из driver().
     */
    public function test_driver_returns_registered_driver(): void
    {
        $manager = new PushNotificationManager;
        $driver = $this->createMock(PushProviderInterface::class);

        $manager->registerDriver('webpush', $driver);

        $this->assertSame($driver, $manager->driver('webpush'));
    }

    /**
     * Проверяем, что поле payload модели приводится к массиву.
     */
    public function test_push_subscription_payload_is_cast_to_array(): void
    {
        $subscription = new PushSubscription(['payload' => ['key' => 'value']]);

        $this->assertSame(['key' => 'value'], $subscription->payload);
    }
}
