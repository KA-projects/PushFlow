<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /**
     * Проверяем, что POST /api/push/subscribe создаёт запись с провайдером webpush.
     */
    public function test_subscribe_creates_a_new_subscription(): void
    {
        $response = $this->postJson('/api/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => 'fake-p256dh-key',
                'auth' => 'fake-auth-token',
            ],
        ]);

        $response->assertCreated()->assertJson([
            'provider' => 'webpush',
            'endpoint' => self::ENDPOINT,
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
            'user_id' => null,
        ]);

        $this->assertDatabaseHas('push_subscriptions', [
            'provider' => 'webpush',
            'endpoint' => self::ENDPOINT,
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
            'user_id' => null,
        ]);
    }

    /**
     * Проверяем, что повторная подписка с тем же endpoint обновляет запись, а не создаёт новую.
     */
    public function test_subscribe_updates_existing_subscription_with_same_endpoint(): void
    {
        $this->postJson('/api/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => 'old-p256dh-key',
                'auth' => 'old-auth-token',
            ],
        ]);

        $response = $this->postJson('/api/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => 'new-p256dh-key',
                'auth' => 'new-auth-token',
            ],
        ]);

        $response->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'public_key' => 'new-p256dh-key',
            'auth_token' => 'new-auth-token',
        ]);
    }

    /**
     * Проверяем, что валидация отклоняет запрос без endpoint и ключей.
     */
    public function test_subscribe_requires_endpoint_and_keys(): void
    {
        $this->postJson('/api/push/subscribe', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    /**
     * Проверяем, что валидация отклоняет не URL-подобный endpoint.
     */
    public function test_subscribe_rejects_invalid_endpoint_url(): void
    {
        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'not-a-url',
            'keys' => [
                'p256dh' => 'fake-p256dh-key',
                'auth' => 'fake-auth-token',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    }

    /**
     * Проверяем, что тестовая страница /push-test отдаётся с кодом 200.
     */
    public function test_push_test_page_is_accessible(): void
    {
        $this->get('/push-test')->assertOk();
    }

    /**
     * Проверяем, что Service Worker лежит в корне public/ для максимального scope.
     */
    public function test_service_worker_file_is_in_public_root(): void
    {
        $this->assertFileExists(public_path('service-worker.js'));
    }
}
