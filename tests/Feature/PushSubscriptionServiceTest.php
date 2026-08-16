<?php

namespace Tests\Feature;

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
        $subscription = app(PushSubscriptionService::class)->upsert([
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => 'fake-p256dh-key',
                'auth' => 'fake-auth-token',
            ],
        ]);

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

        $subscription = app(PushSubscriptionService::class)->upsert([
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => 'new-p256dh-key',
                'auth' => 'new-auth-token',
            ],
        ]);

        $this->assertFalse($subscription->wasRecentlyCreated);
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame('new-p256dh-key', $subscription->public_key);
        $this->assertSame('new-auth-token', $subscription->auth_token);
    }
}
