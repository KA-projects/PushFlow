<?php

namespace Tests\Feature\Stress;

use App\Contracts\PushProviderInterface;
use App\Dto\DeliveryReceipt;
use App\Dto\SendResult;
use App\Enums\PushNotificationStatus;
use App\Jobs\CheckPushDelivery;
use App\Jobs\SendPushNotification;
use App\Models\PushAttempt;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use App\Services\Push\Exceptions\TemporaryPushException;
use App\Services\Push\PushNotificationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryStormStressTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubscription(array $attributes = []): PushSubscription
    {
        return PushSubscription::create(array_merge([
            'endpoint' => 'https://example.com/push',
            'provider' => 'storm-provider',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ], $attributes));
    }

    /**
     * Шторм временных ошибок: все уведомления исчерпывают попытки и корректно падают без «зависших» записей.
     */
    public function test_retry_storm_all_fail_cleanly(): void
    {
        foreach (range(1, 200) as $i) {
            $subscription = $this->makeSubscription(['endpoint' => "https://example.com/push/{$i}"]);

            PushNotification::create([
                'push_subscription_id' => $subscription->id,
                'title' => 'Заголовок',
                'body' => 'Текст',
                'status' => PushNotificationStatus::Pending,
                'provider' => $subscription->provider,
            ]);
        }

        $driver = new class implements PushProviderInterface
        {
            public function send(PushSubscription $subscription, string $title, string $body, array $extra = [], ?string $idempotencyKey = null): SendResult
            {
                throw TemporaryPushException::http(503);
            }

            public function checkDelivery(PushSubscription $subscription, string $ticketId): DeliveryReceipt
            {
                return new DeliveryReceipt(status: 'delivered');
            }
        };

        app(PushNotificationManager::class)->registerDriver('storm-provider', $driver);

        $manager = app(PushNotificationManager::class);

        foreach (PushNotification::all() as $notification) {
            $job = new SendPushNotification($notification->id);

            // Каждая попытка падает — очередь выполнит retry.
            for ($i = 0; $i < 5; $i++) {
                try {
                    $job->handle($manager);
                    $this->fail('Ожидалась временная ошибка.');
                } catch (TemporaryPushException) {
                    // Ожидаемое исключение для retry.
                }
            }

            $job->failed(new TemporaryPushException('HTTP_503', 'Service unavailable'));
        }

        $this->assertSame(200, PushNotification::where('status', PushNotificationStatus::Failed->value)->where('error_code', 'MAX_ATTEMPTS')->count());
        $this->assertSame(0, PushNotification::where('attempts', '!=', 5)->count());
        // 200 уведомлений × (5 ошибок + 1 финальный failed в failed()) = 1200 записей.
        $this->assertSame(1200, PushAttempt::count());
        $this->assertSame(0, PushNotification::whereIn('status', ['pending', 'processing'])->count());
    }

    /**
     * Массовая проверка delivery receipts: pending → повторная проверка → delivered.
     */
    public function test_receipt_checks_under_load(): void
    {
        Queue::fake();

        foreach (range(1, 300) as $i) {
            $subscription = $this->makeSubscription(['endpoint' => "https://example.com/push/{$i}"]);

            PushNotification::create([
                'push_subscription_id' => $subscription->id,
                'title' => 'Заголовок',
                'body' => 'Текст',
                'status' => PushNotificationStatus::Accepted,
                'provider' => $subscription->provider,
                'ticket_id' => "ticket-{$i}",
            ]);
        }

        $driver = new class implements PushProviderInterface
        {
            /** @var array<string, int> */
            public array $checkCalls = [];

            public function send(PushSubscription $subscription, string $title, string $body, array $extra = [], ?string $idempotencyKey = null): SendResult
            {
                return new SendResult(ticketId: 'ticket');
            }

            public function checkDelivery(PushSubscription $subscription, string $ticketId): DeliveryReceipt
            {
                $this->checkCalls[$ticketId] = ($this->checkCalls[$ticketId] ?? 0) + 1;

                // Первая проверка — receipt ещё не готов, вторая — доставлено.
                if ($this->checkCalls[$ticketId] === 1) {
                    return new DeliveryReceipt(status: 'pending');
                }

                return new DeliveryReceipt(status: 'delivered');
            }
        };

        app(PushNotificationManager::class)->registerDriver('storm-provider', $driver);

        $manager = app(PushNotificationManager::class);

        foreach (PushNotification::all() as $notification) {
            (new CheckPushDelivery($notification->id, 1))->handle($manager);
            (new CheckPushDelivery($notification->id, 2))->handle($manager);
        }

        Queue::assertPushed(CheckPushDelivery::class, 300);

        $this->assertSame(0, PushNotification::where('status', '!=', PushNotificationStatus::Delivered->value)->count());
        $this->assertSame(0, PushNotification::whereNull('delivered_at')->count());
        $this->assertSame(300, count($driver->checkCalls));
        $this->assertSame(600, array_sum($driver->checkCalls));
        $this->assertSame([], array_filter($driver->checkCalls, fn (int $calls) => $calls !== 2));
        $this->assertSame(300, PushAttempt::where('status', 'delivered')->count());
    }
}
