<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Services\Push\Providers\MockFcmProvider;
use App\Services\Push\PushNotificationManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class MockFcmProviderTest extends TestCase
{
    /**
     * Проверяем, что менеджер отдаёт инстанс MockFcmProvider для драйвера 'fcm'.
     */
    public function test_manager_returns_mock_fcm_provider(): void
    {
        $manager = app(PushNotificationManager::class);

        $this->assertInstanceOf(MockFcmProvider::class, $manager->driver('fcm'));
    }

    /**
     * Проверяем, что MockFcmProvider логирует отправку FCM-уведомления без падения.
     */
    public function test_send_logs_fcm_notification(): void
    {
        Log::spy();

        $subscription = new PushSubscription([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        ]);

        (new MockFcmProvider)->send($subscription, 'Заголовок', 'Текст');

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'Sending FCM notification via Google API...',
                Mockery::on(fn (array $context) => $context['endpoint'] === 'https://fcm.googleapis.com/fcm/send/test-endpoint')
            );
    }
}
