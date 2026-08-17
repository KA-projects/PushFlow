<?php

namespace Tests\Feature;

use App\Contracts\PushProviderInterface;
use App\Dto\SendResult;
use App\Enums\PushNotificationStatus;
use App\Jobs\CheckPushDelivery;
use App\Jobs\SendPushNotification;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use App\Services\Push\Exceptions\TemporaryPushException;
use App\Services\Push\PushNotificationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SendPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubscription(array $attributes = []): PushSubscription
    {
        return PushSubscription::create(array_merge([
            'endpoint' => 'https://example.com/push',
            'provider' => 'test-provider',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ], $attributes));
    }

    private function makeNotification(PushSubscription $subscription, array $attributes = []): PushNotification
    {
        return PushNotification::create(array_merge([
            'push_subscription_id' => $subscription->id,
            'title' => 'Заголовок',
            'body' => 'Текст',
            'payload' => ['link' => 'https://example.com/x'],
            'status' => PushNotificationStatus::Pending,
            'provider' => $subscription->provider,
        ], $attributes));
    }

    /**
     * Проверяем, что Job вызывает драйвер, сохраняет ticket и переводит уведомление в accepted.
     */
    public function test_job_sends_notification_and_marks_accepted(): void
    {
        Queue::fake();

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $provider = Mockery::mock(PushProviderInterface::class);
        $provider->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (PushSubscription $s) => $s->is($subscription)),
                'Заголовок',
                'Текст',
                ['link' => 'https://example.com/x'],
                'notification-'.$notification->id
            )
            ->andReturn(new SendResult(ticketId: 'ticket-123'));

        app(PushNotificationManager::class)->registerDriver('test-provider', $provider);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();

        $this->assertSame(PushNotificationStatus::Accepted, $notification->status);
        $this->assertSame('ticket-123', $notification->ticket_id);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame(1, $notification->attempts);

        Queue::assertPushed(CheckPushDelivery::class);

        $this->assertDatabaseHas('push_attempts', [
            'notification_id' => $notification->id,
            'status' => 'accepted',
            'ticket_id' => 'ticket-123',
        ]);
    }

    /**
     * Проверяем, что Job завершается молча, если уведомление не найдено.
     */
    public function test_job_is_ignored_when_notification_not_found(): void
    {
        (new SendPushNotification(99999))->handle(app(PushNotificationManager::class));

        $this->assertTrue(true);
    }

    /**
     * Проверяем, что Job не отправляет повторно уже доставленное уведомление.
     */
    public function test_job_does_not_resent_delivered_notification(): void
    {
        Queue::fake();

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription, [
            'status' => PushNotificationStatus::Delivered,
            'delivered_at' => now(),
        ]);

        $provider = Mockery::mock(PushProviderInterface::class);
        $provider->shouldReceive('send')->never();

        app(PushNotificationManager::class)->registerDriver('test-provider', $provider);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Delivered, $notification->status);
        Queue::assertNothingPushed();
    }

    /**
     * Проверяем, что при временной ошибке Job пробрасывает исключение (для retry) и возвращает статус в pending.
     */
    public function test_temporary_error_rethrows_and_returns_to_pending(): void
    {
        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $provider = Mockery::mock(PushProviderInterface::class);
        $provider->shouldReceive('send')->andThrow(TemporaryPushException::http(503));

        app(PushNotificationManager::class)->registerDriver('test-provider', $provider);

        try {
            (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
            $this->fail('Ожидалось исключение для retry.');
        } catch (TemporaryPushException $exception) {
            $this->assertSame('HTTP_503', $exception->getErrorCode());
        }

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Pending, $notification->status);

        $this->assertDatabaseHas('push_attempts', [
            'notification_id' => $notification->id,
            'status' => 'error',
            'error_code' => 'HTTP_503',
        ]);
    }

    /**
     * Неожиданное (неклассифицированное) исключение помечается failed без ретрая.
     */
    public function test_unknown_exception_marks_failed_without_retry(): void
    {
        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $provider = Mockery::mock(PushProviderInterface::class);
        $provider->shouldReceive('send')->andThrow(new \RuntimeException('Invalid data provided'));

        app(PushNotificationManager::class)->registerDriver('test-provider', $provider);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();

        $this->assertSame(PushNotificationStatus::Failed, $notification->status);
        $this->assertSame('UNKNOWN_ERROR', $notification->error_code);
        $this->assertSame('Invalid data provided', $notification->error_message);

        $this->assertDatabaseHas('push_attempts', [
            'notification_id' => $notification->id,
            'status' => 'failed',
            'error_code' => 'UNKNOWN_ERROR',
        ]);
    }

    /**
     * Проверяем, что при неактивной подписке уведомление помечается failed.
     */
    public function test_job_fails_notification_when_subscription_inactive(): void
    {
        $subscription = $this->makeSubscription(['is_active' => false]);
        $notification = $this->makeNotification($subscription);

        $provider = Mockery::mock(PushProviderInterface::class);
        $provider->shouldReceive('send')->never();

        app(PushNotificationManager::class)->registerDriver('test-provider', $provider);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Failed, $notification->status);
        $this->assertSame('DEVICE_INACTIVE', $notification->error_code);
    }

    /**
     * Проверяем, что Job уходит в очередь, а не выполняется сразу.
     */
    public function test_job_is_queued(): void
    {
        Queue::fake();

        SendPushNotification::dispatch(1);

        Queue::assertPushed(SendPushNotification::class);
    }
}
