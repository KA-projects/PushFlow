<?php

namespace Tests\Feature\Stress;

use App\Contracts\PushProviderInterface;
use App\Dto\DeliveryReceipt;
use App\Dto\SendPushNotificationData;
use App\Dto\SendResult;
use App\Enums\PushNotificationStatus;
use App\Jobs\SendPushNotification;
use App\Models\PushAttempt;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use App\Services\Push\Providers\MockFcmProvider;
use App\Services\Push\PushNotificationManager;
use App\Services\Push\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendPushNotificationStressTest extends TestCase
{
    use RefreshDatabase;

    private const COUNT = 500;

    private function makeSubscription(array $attributes = []): PushSubscription
    {
        return PushSubscription::create(array_merge([
            'endpoint' => 'https://example.com/push',
            'provider' => 'stress-provider',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ], $attributes));
    }

    /**
     * Драйвер, считающий вызовы send.
     */
    private function makeCountingDriver(): PushProviderInterface
    {
        return new class implements PushProviderInterface
        {
            public int $calls = 0;

            public function send(PushSubscription $subscription, string $title, string $body, array $extra = [], ?string $idempotencyKey = null): SendResult
            {
                $this->calls++;

                return new SendResult(ticketId: 'ticket-'.$this->calls);
            }

            public function checkDelivery(PushSubscription $subscription, string $ticketId): DeliveryReceipt
            {
                return new DeliveryReceipt(status: 'delivered');
            }
        };
    }

    /**
     * Массовый фан-аут: 500 активных подписок, отправка всем, прогон всех job'ов без дублей и потерь.
     */
    public function test_mass_queue_and_delivery_no_duplicates(): void
    {
        Queue::fake();

        foreach (range(1, self::COUNT) as $i) {
            $this->makeSubscription(['endpoint' => "https://example.com/push/{$i}"]);
        }

        $driver = $this->makeCountingDriver();
        app(PushNotificationManager::class)->registerDriver('stress-provider', $driver);

        app(PushNotificationService::class)->queueForEndpoint(
            new SendPushNotificationData(title: 'Заголовок', body: 'Текст')
        );

        $this->assertSame(self::COUNT, PushNotification::count());
        Queue::assertPushed(SendPushNotification::class, self::COUNT);

        // Имитация N воркеров, подбирающих job-ы из очереди.
        foreach (PushNotification::all() as $notification) {
            (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
        }

        $this->assertSame(self::COUNT, $driver->calls);
        $this->assertSame(0, PushNotification::where('status', '!=', PushNotificationStatus::Accepted->value)->count());
        $this->assertSame(self::COUNT, PushAttempt::count());
        $this->assertSame(0, PushNotification::where('attempts', '!=', 1)->count());
        $this->assertSame(0, PushNotification::whereIn('status', ['pending', 'processing'])->count());
    }

    /**
     * Гонка воркеров за один notification: атомарный claim() пропускает только первого.
     */
    public function test_atomic_claim_prevents_double_send(): void
    {
        $subscription = $this->makeSubscription();
        $notification = PushNotification::create([
            'push_subscription_id' => $subscription->id,
            'title' => 'Заголовок',
            'body' => 'Текст',
            'status' => PushNotificationStatus::Pending,
            'provider' => $subscription->provider,
        ]);

        $driver = $this->makeCountingDriver();
        app(PushNotificationManager::class)->registerDriver('stress-provider', $driver);

        // 20 параллельных воркеров: первый переводит строку в processing, остальные отсеиваются claim().
        for ($i = 0; $i < 20; $i++) {
            (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
        }

        $this->assertSame(1, $driver->calls);
        $this->assertSame(1, $notification->refresh()->attempts);
        $this->assertSame(1, PushAttempt::where('notification_id', $notification->id)->where('status', 'accepted')->count());
    }

    /**
     * Маршрутизация по полю provider подписки при большой пачке.
     */
    public function test_mixed_provider_fanout(): void
    {
        Queue::fake();

        foreach (range(1, 40) as $i) {
            $this->makeSubscription([
                'endpoint' => "https://fcm.example.com/{$i}",
                'provider' => 'fcm',
            ]);
        }

        foreach (range(1, 30) as $i) {
            $this->makeSubscription([
                'endpoint' => "https://webpush.example.com/{$i}",
                'provider' => 'webpush',
            ]);
        }

        // Реальный MockFcmProvider, обёрнутый в счётчик вызовов.
        $fcmDriver = new class(new MockFcmProvider) implements PushProviderInterface
        {
            public int $calls = 0;

            public function __construct(private MockFcmProvider $inner) {}

            public function send(PushSubscription $subscription, string $title, string $body, array $extra = [], ?string $idempotencyKey = null): SendResult
            {
                $this->calls++;

                return $this->inner->send($subscription, $title, $body, $extra, $idempotencyKey);
            }

            public function checkDelivery(PushSubscription $subscription, string $ticketId): DeliveryReceipt
            {
                return $this->inner->checkDelivery($subscription, $ticketId);
            }
        };

        $webpushDriver = $this->makeCountingDriver();

        app(PushNotificationManager::class)->registerDriver('fcm', $fcmDriver);
        app(PushNotificationManager::class)->registerDriver('webpush', $webpushDriver);

        app(PushNotificationService::class)->queueForEndpoint(
            new SendPushNotificationData(title: 'Заголовок', body: 'Текст')
        );

        $this->assertSame(70, PushNotification::count());

        foreach (PushNotification::all() as $notification) {
            (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
        }

        $this->assertSame(40, $fcmDriver->calls);
        $this->assertSame(30, $webpushDriver->calls);
        $this->assertSame(0, PushNotification::where('status', '!=', PushNotificationStatus::Accepted->value)->count());

        // Каждая notification ушла в драйвер, соответствующий provider подписки.
        foreach (PushNotification::all() as $notification) {
            $this->assertSame($notification->subscription->provider, $notification->provider);
        }
    }
}
