<?php

namespace Tests\Feature;

use App\Dto\PushSubscriptionData;
use App\Models\PushSubscription;
use App\Services\Push\PushSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /**
     * Проверяем, что сервис создаёт новую подписку с провайдером webpush.
     */
    public function test_service_creates_a_new_subscription(): void
    {
        $subscription = app(PushSubscriptionService::class)->upsert(
            new PushSubscriptionData(
                endpoint: self::ENDPOINT,
                p256dh: 'fake-p256dh-key',
                auth: 'fake-auth-token',
            )
        );

        $this->assertTrue($subscription->wasRecentlyCreated);
        $this->assertSame('webpush', $subscription->provider);
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame('fake-p256dh-key', $subscription->public_key);
        $this->assertSame('fake-auth-token', $subscription->auth_token);
        $this->assertNull($subscription->user_id);
    }

    /**
     * Проверяем, что сервис обновляет существующую подписку с тем же endpoint.
     */
    public function test_service_updates_existing_subscription_with_same_endpoint(): void
    {
        PushSubscription::create([
            'endpoint' => self::ENDPOINT,
            'provider' => 'webpush',
            'public_key' => 'old-p256dh-key',
            'auth_token' => 'old-auth-token',
        ]);

        $subscription = app(PushSubscriptionService::class)->upsert(
            new PushSubscriptionData(
                endpoint: self::ENDPOINT,
                p256dh: 'new-p256dh-key',
                auth: 'new-auth-token',
            )
        );

        $this->assertFalse($subscription->wasRecentlyCreated);
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame('new-p256dh-key', $subscription->public_key);
        $this->assertSame('new-auth-token', $subscription->auth_token);
    }

    /**
     * Проверяем, что сервис деактивирует подписку по endpoint.
     */
    public function test_service_unsubscribes_by_endpoint(): void
    {
        $subscription = PushSubscription::create([
            'endpoint' => self::ENDPOINT,
            'provider' => 'webpush',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
        ]);

        $result = app(PushSubscriptionService::class)->unsubscribe(self::ENDPOINT);

        $this->assertTrue($result);
        $this->assertFalse($subscription->fresh()->is_active);
    }

    /**
     * Проверяем, что отписка от несуществующего endpoint возвращает false.
     */
    public function test_service_unsubscribe_returns_false_for_unknown_endpoint(): void
    {
        $this->assertFalse(
            app(PushSubscriptionService::class)->unsubscribe('https://unknown.example/endpoint')
        );
    }

    /**
     * Проверяем, что повторная подписка реактивирует деактивированную запись.
     */
    public function test_service_reactivates_unsubscribed_subscription_on_upsert(): void
    {
        PushSubscription::create([
            'endpoint' => self::ENDPOINT,
            'provider' => 'webpush',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
            'is_active' => false,
        ]);

        $subscription = app(PushSubscriptionService::class)->upsert(
            new PushSubscriptionData(
                endpoint: self::ENDPOINT,
                p256dh: 'new-p256dh-key',
                auth: 'new-auth-token',
            )
        );

        $this->assertTrue($subscription->is_active);
        $this->assertSame(1, PushSubscription::count());
    }
}
