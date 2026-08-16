<?php

namespace Tests\Feature;

use App\Dto\DeliveryReceipt;
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
     * Проверяем, что MockFcmProvider логирует отправку FCM-уведомления и возвращает ticket.
     */
    public function test_send_logs_fcm_notification_and_returns_ticket(): void
    {
        Log::spy();

        $subscription = new PushSubscription([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        ]);

        $result = (new MockFcmProvider)->send($subscription, 'Заголовок', 'Текст', [], 'notification-1');

        $this->assertNotSame('', $result->ticketId);

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'Sending FCM notification via Google API...',
                Mockery::on(fn (array $context) => $context['endpoint'] === 'https://fcm.googleapis.com/fcm/send/test-endpoint'
                    && $context['idempotency_key'] === 'notification-1')
            );
    }

    /**
     * Проверяем, что MockFcmProvider по умолчанию считает доставку подтверждённой.
     */
    public function test_check_delivery_returns_delivered(): void
    {
        $subscription = new PushSubscription([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        ]);

        $receipt = (new MockFcmProvider)->checkDelivery($subscription, 'ticket-1');

        $this->assertInstanceOf(DeliveryReceipt::class, $receipt);
        $this->assertSame('delivered', $receipt->status);
    }
}
