<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use App\Services\Push\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT_ONE = 'https://fcm.googleapis.com/fcm/send/endpoint-one';

    private const ENDPOINT_TWO = 'https://fcm.googleapis.com/fcm/send/endpoint-two';

    /**
     * Проверяем, что сервис ставит Job в очередь для всех подписок, если endpoint не передан.
     */
    public function test_service_queues_jobs_for_all_subscriptions_without_endpoint(): void
    {
        Queue::fake();

        PushSubscription::create([
            'endpoint' => self::ENDPOINT_ONE,
            'provider' => 'webpush',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
        ]);
        PushSubscription::create([
            'endpoint' => self::ENDPOINT_TWO,
            'provider' => 'webpush',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
        ]);

        $queued = app(PushNotificationService::class)
            ->queueForEndpoint('Заголовок', 'Текст', null);

        $this->assertCount(2, $queued);
        Queue::assertPushed(SendPushNotification::class, 2);
    }

    /**
     * Проверяем, что при передаче endpoint сервис ставит Job только для этой подписки.
     */
    public function test_service_queues_job_only_for_matching_endpoint(): void
    {
        Queue::fake();

        PushSubscription::create([
            'endpoint' => self::ENDPOINT_ONE,
            'provider' => 'webpush',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
        ]);
        PushSubscription::create([
            'endpoint' => self::ENDPOINT_TWO,
            'provider' => 'webpush',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
        ]);

        $queued = app(PushNotificationService::class)
            ->queueForEndpoint('Заголовок', 'Текст', self::ENDPOINT_ONE, ['link' => '/']);

        $this->assertCount(1, $queued);
        Queue::assertPushed(SendPushNotification::class, 1);
    }

    /**
     * Проверяем, что сервис возвращает пустую коллекцию, если подписок в БД нет.
     */
    public function test_service_returns_empty_collection_when_no_subscriptions_exist(): void
    {
        Queue::fake();

        $queued = app(PushNotificationService::class)
            ->queueForEndpoint('Заголовок', 'Текст', null);

        $this->assertTrue($queued->isEmpty());
        Queue::assertNothingPushed();
    }
}
