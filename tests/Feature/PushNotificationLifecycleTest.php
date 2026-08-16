<?php

namespace Tests\Feature;

use App\Contracts\PushProviderInterface;
use App\Dto\DeliveryReceipt;
use App\Dto\SendResult;
use App\Enums\PushNotificationStatus;
use App\Jobs\CheckPushDelivery;
use App\Jobs\SendPushNotification;
use App\Models\PushAttempt;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use App\Services\Push\Exceptions\DeviceNotRegisteredException;
use App\Services\Push\Exceptions\PermanentPushException;
use App\Services\Push\Exceptions\TemporaryPushException;
use App\Services\Push\PushNotificationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PushNotificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubscription(array $attributes = []): PushSubscription
    {
        return PushSubscription::create(array_merge([
            'endpoint' => 'https://example.com/push',
            'provider' => 'lifecycle-provider',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ], $attributes));
    }

    private function makeNotification(PushSubscription $subscription): PushNotification
    {
        return PushNotification::create([
            'push_subscription_id' => $subscription->id,
            'title' => 'Заголовок',
            'body' => 'Текст',
            'status' => PushNotificationStatus::Pending,
            'provider' => $subscription->provider,
        ]);
    }

    private function registerDriver(PushProviderInterface $driver): void
    {
        app(PushNotificationManager::class)->registerDriver('lifecycle-provider', $driver);
    }

    /**
     * Успешная отправка: pending → processing → accepted → delivered.
     */
    public function test_successful_delivery_full_lifecycle(): void
    {
        Queue::fake();

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->once()->andReturn(new SendResult(ticketId: 'ticket-1'));
        $driver->shouldReceive('checkDelivery')->once()->andReturn(new DeliveryReceipt(status: 'delivered'));

        $this->registerDriver($driver);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Accepted, $notification->status);
        $this->assertSame('ticket-1', $notification->ticket_id);

        (new CheckPushDelivery($notification->id, 1))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Delivered, $notification->status);
        $this->assertNotNull($notification->delivered_at);
    }

    /**
     * Временная ошибка: HTTP 503 → retry → success.
     */
    public function test_temporary_error_then_retry_success(): void
    {
        Queue::fake();

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $calls = 0;
        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->andReturnUsing(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw TemporaryPushException::http(503);
            }

            return new SendResult(ticketId: 'ticket-after-retry');
        });

        $this->registerDriver($driver);

        try {
            (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
            $this->fail('Ожидался retry после HTTP 503.');
        } catch (TemporaryPushException) {
            // Первая попытка упала — повторяем.
        }

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Accepted, $notification->status);
        $this->assertSame('ticket-after-retry', $notification->ticket_id);
        $this->assertSame(2, $notification->attempts);

        $this->assertSame(2, PushAttempt::where('notification_id', $notification->id)->count());
        $this->assertDatabaseHas('push_attempts', [
            'notification_id' => $notification->id,
            'status' => 'error',
            'error_code' => 'HTTP_503',
        ]);
    }

    /**
     * Превышение количества retry: notification → failed.
     */
    public function test_retry_exhaustion_marks_notification_failed(): void
    {
        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->andThrow(TemporaryPushException::http(503));

        $this->registerDriver($driver);

        $job = new SendPushNotification($notification->id);

        // Симулируем все попытки Laravel-очереди ($tries = 5).
        for ($i = 0; $i < 5; $i++) {
            try {
                $job->handle(app(PushNotificationManager::class));
                $this->fail('Ожидалась временная ошибка.');
            } catch (TemporaryPushException) {
                // Каждая попытка падает — очередь выполняет retry.
            }
        }

        $job->failed(new TemporaryPushException('HTTP_503', 'Service unavailable'));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Failed, $notification->status);
        $this->assertSame('MAX_ATTEMPTS', $notification->error_code);
        $this->assertNotNull($notification->failed_at);
    }

    /**
     * Невалидный token: DeviceNotRegistered → failed + деактивация подписки.
     */
    public function test_device_not_registered_deactivates_subscription(): void
    {
        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->andThrow(DeviceNotRegisteredException::create());

        $this->registerDriver($driver);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $subscription->refresh();

        $this->assertSame(PushNotificationStatus::Failed, $notification->status);
        $this->assertSame('DeviceNotRegistered', $notification->error_code);
        $this->assertFalse($subscription->is_active);

        $this->assertDatabaseHas('push_attempts', [
            'notification_id' => $notification->id,
            'status' => 'failed',
            'error_code' => 'DeviceNotRegistered',
        ]);
    }

    /**
     * Постоянная ошибка (InvalidPayload): failed без retry.
     */
    public function test_permanent_error_marks_failed_without_retry(): void
    {
        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->andThrow(PermanentPushException::invalidPayload('Bad payload'));

        $this->registerDriver($driver);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Failed, $notification->status);
        $this->assertSame('InvalidPayload', $notification->error_code);
        $this->assertTrue($subscription->fresh()->is_active);
    }

    /**
     * Receipt ещё не готов: accepted → pending → retry check → delivered.
     */
    public function test_receipt_pending_then_retry_check_delivered(): void
    {
        Queue::fake();

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->once()->andReturn(new SendResult(ticketId: 'ticket-1'));

        $checkCalls = 0;
        $driver->shouldReceive('checkDelivery')->andReturnUsing(function () use (&$checkCalls) {
            $checkCalls++;

            if ($checkCalls === 1) {
                return new DeliveryReceipt(status: 'pending');
            }

            return new DeliveryReceipt(status: 'delivered');
        });

        $this->registerDriver($driver);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Accepted, $notification->status);

        (new CheckPushDelivery($notification->id, 1))->handle(app(PushNotificationManager::class));

        // Первичная отправка (после accepted) + повторная проверка (attempt 2).
        Queue::assertPushed(CheckPushDelivery::class, 2);
        $this->assertTrue(Queue::pushed(CheckPushDelivery::class)->last()->attempt === 2);

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Accepted, $notification->status);

        (new CheckPushDelivery($notification->id, 2))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Delivered, $notification->status);
    }

    /**
     * Превышение числа проверок receipt: status = failed, error_code = DELIVERY_STATUS_UNKNOWN.
     */
    public function test_receipt_checks_exhausted_marks_failed_unknown(): void
    {
        config(['push.receipt.max_attempts' => 2]);

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->once()->andReturn(new SendResult(ticketId: 'ticket-1'));
        $driver->shouldReceive('checkDelivery')->andReturn(new DeliveryReceipt(status: 'pending'));

        $this->registerDriver($driver);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
        $notification->refresh();

        (new CheckPushDelivery($notification->id, 1))->handle(app(PushNotificationManager::class));
        (new CheckPushDelivery($notification->id, 2))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Failed, $notification->status);
        $this->assertSame('DELIVERY_STATUS_UNKNOWN', $notification->error_code);
    }

    /**
     * Проверка receipt возвращает failed с кодом устройства: уведомление → failed, подписка → неактивна.
     */
    public function test_receipt_device_failure_deactivates_subscription(): void
    {
        Queue::fake();

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->once()->andReturn(new SendResult(ticketId: 'ticket-1'));
        $driver->shouldReceive('checkDelivery')->once()->andReturn(
            new DeliveryReceipt(status: 'failed', errorCode: 'DeviceNotRegistered', errorMessage: 'Device gone')
        );

        $this->registerDriver($driver);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));
        (new CheckPushDelivery($notification->id, 1))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $subscription->refresh();

        $this->assertSame(PushNotificationStatus::Failed, $notification->status);
        $this->assertSame('DeviceNotRegistered', $notification->error_code);
        $this->assertFalse($subscription->is_active);
    }

    /**
     * Повторный запуск завершённой Job не повторяет отправку.
     */
    public function test_rerunning_completed_job_does_not_resend(): void
    {
        Queue::fake();

        $subscription = $this->makeSubscription();
        $notification = $this->makeNotification($subscription);

        $driver = Mockery::mock(PushProviderInterface::class);
        $driver->shouldReceive('send')->once()->andReturn(new SendResult(ticketId: 'ticket-1'));
        $driver->shouldReceive('checkDelivery')->andReturn(new DeliveryReceipt(status: 'delivered'));

        $this->registerDriver($driver);

        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        (new CheckPushDelivery($notification->id, 1))->handle(app(PushNotificationManager::class));

        $notification->refresh();
        $this->assertSame(PushNotificationStatus::Delivered, $notification->status);

        // Повторный запуск уже доставленной Job ничего не отправляет.
        (new SendPushNotification($notification->id))->handle(app(PushNotificationManager::class));

        $this->assertSame(1, $notification->refresh()->attempts);
    }
}
