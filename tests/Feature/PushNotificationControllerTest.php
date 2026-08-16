<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use App\Services\Push\PushNotificationManager;
use App\Services\Push\PushProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PushNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT_ONE = 'https://fcm.googleapis.com/fcm/send/endpoint-one';

    private const ENDPOINT_TWO = 'https://fcm.googleapis.com/fcm/send/endpoint-two';

    private function makeSubscription(string $endpoint): PushSubscription
    {
        return PushSubscription::create([
            'endpoint' => $endpoint,
            'provider' => 'webpush',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
        ]);
    }

    /**
     * Проверяем, что POST /api/push/send ставит Job в очередь для всех подписок, если endpoint не передан.
     */
    public function test_send_dispatches_job_for_all_subscriptions_without_endpoint(): void
    {
        Queue::fake();

        $this->makeSubscription(self::ENDPOINT_ONE);
        $this->makeSubscription(self::ENDPOINT_TWO);

        $response = $this->postJson('/api/push/send', [
            'title' => 'Заголовок',
            'body' => 'Текст',
        ]);

        $response->assertOk()->assertJson([
            'queued' => 2,
        ]);

        Queue::assertPushed(SendPushNotification::class, 2);
    }

    /**
     * Проверяем, что при передаче endpoint Job ставится в очередь только для этой подписки.
     */
    public function test_send_dispatches_job_only_for_matching_endpoint(): void
    {
        Queue::fake();

        $this->makeSubscription(self::ENDPOINT_ONE);
        $this->makeSubscription(self::ENDPOINT_TWO);

        $response = $this->postJson('/api/push/send', [
            'endpoint' => self::ENDPOINT_ONE,
            'title' => 'Заголовок',
            'body' => 'Текст',
        ]);

        $response->assertOk()->assertJson([
            'queued' => 1,
        ]);

        Queue::assertPushed(SendPushNotification::class, 1);
    }

    /**
     * Проверяем, что POST /api/push/send возвращает 404, если подписок в БД нет.
     */
    public function test_send_returns_404_when_no_subscriptions_exist(): void
    {
        $response = $this->postJson('/api/push/send', [
            'title' => 'Заголовок',
            'body' => 'Текст',
        ]);

        $response->assertNotFound();
    }

    /**
     * Проверяем, что валидация требует title и body.
     */
    public function test_send_requires_title_and_body(): void
    {
        $this->postJson('/api/push/send', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'body']);
    }

    /**
     * Сквозной тест: POST /api/push/send доставляет уведомления через драйвер,
     * выбранный по полю provider каждой подписки — webpush через браузерный драйвер,
     * fcm через MockFcmProvider (запись в логи).
     */
    public function test_send_end_to_end_routes_to_provider_per_subscription(): void
    {
        Log::spy();

        $webPushMock = Mockery::mock(PushProviderInterface::class);
        $webPushMock->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (PushSubscription $s) => $s->endpoint === self::ENDPOINT_ONE),
                'Заголовок',
                'Текст',
                []
            );

        app(PushNotificationManager::class)->registerDriver('webpush', $webPushMock);

        $this->makeSubscription(self::ENDPOINT_ONE);

        PushSubscription::create([
            'endpoint' => self::ENDPOINT_TWO,
            'provider' => 'fcm',
            'public_key' => 'fake-p256dh-key',
            'auth_token' => 'fake-auth-token',
        ]);

        $response = $this->postJson('/api/push/send', [
            'title' => 'Заголовок',
            'body' => 'Текст',
        ]);

        $response->assertOk()->assertJson([
            'queued' => 2,
        ]);

        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'Sending FCM notification via Google API...',
                Mockery::on(fn (array $context) => $context['endpoint'] === self::ENDPOINT_TWO)
            );
    }
}
