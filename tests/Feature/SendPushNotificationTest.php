<?php

namespace Tests\Feature;

use App\Contracts\PushProviderInterface;
use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use App\Services\Push\PushNotificationManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SendPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Проверяем, что Job вызывает драйвер, выбранный по полю provider подписки.
     */
    public function test_job_sends_notification_via_subscription_provider(): void
    {
        $subscription = PushSubscription::create([
            'endpoint' => 'https://example.com/push',
            'provider' => 'test-provider',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ]);

        $provider = Mockery::mock(PushProviderInterface::class);
        $provider->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (PushSubscription $s) => $s->is($subscription)),
                'Заголовок',
                'Текст',
                ['link' => 'https://example.com/x']
            );

        $manager = app(PushNotificationManager::class);
        $manager->registerDriver('test-provider', $provider);

        SendPushNotification::dispatch($subscription->id, 'Заголовок', 'Текст', ['link' => 'https://example.com/x']);
    }

    /**
     * Проверяем, что Job выбрасывает исключение, если подписка не найдена в БД.
     */
    public function test_job_throws_when_subscription_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        SendPushNotification::dispatch(99999, 'Заголовок', 'Текст');
    }

    /**
     * Проверяем, что Job уходит в очередь, а не выполняется сразу.
     */
    public function test_job_is_queued(): void
    {
        Queue::fake();

        SendPushNotification::dispatch(1, 'Заголовок', 'Текст');

        Queue::assertPushed(SendPushNotification::class);
    }
}
