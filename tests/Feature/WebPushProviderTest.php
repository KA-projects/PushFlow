<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Services\Push\Exceptions\DeviceNotRegisteredException;
use App\Services\Push\Exceptions\PermanentPushException;
use App\Services\Push\Providers\WebPushProvider;
use App\Services\Push\PushNotificationManager;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

class WebPushProviderTest extends TestCase
{
    /**
     * Проверяем, что менеджер отдаёт инстанс WebPushProvider для драйвера 'webpush'.
     */
    public function test_manager_returns_webpush_provider(): void
    {
        $manager = app(PushNotificationManager::class);

        $this->assertInstanceOf(WebPushProvider::class, $manager->driver('webpush'));
    }

    /**
     * Проверяем, что WebPush инициализируется с валидными VAPID ключами из конфига.
     */
    public function test_webpush_initializes_with_valid_vapid_keys(): void
    {
        config([
            'webpush.vapid.subject' => 'mailto:admin@example.com',
            'webpush.vapid.public_key' => 'BDAxV-qbBHrmCtKSiQxPcef4-SKlWVIajuNadLSMJ21bj-KUl-X4W2QaqXuLwdDIaezrg-w_hem23pZIxp44aDI',
            'webpush.vapid.private_key' => 'mNL8qt8FEXkhT0tcg2Pf1S0F2gnOQh2zcHw-mimCFdk',
        ]);

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ]);

        $this->assertInstanceOf(WebPush::class, $webPush);
    }

    /**
     * Невалидные ключи подписки — PermanentPushException, а не retryable ошибка.
     */
    public function test_webpush_provider_classifies_invalid_keys_as_permanent(): void
    {
        $webPush = $this->createMock(WebPush::class);
        $webPush->method('queueNotification');
        $webPush->method('flush')->willThrowException(new \InvalidArgumentException('Invalid data provided'));

        $provider = new WebPushProvider($webPush);

        $subscription = new PushSubscription([
            'endpoint' => 'https://example.com/push',
            'public_key' => 'stress-public-key',
            'auth_token' => 'stress-auth-token',
        ]);

        $this->expectException(PermanentPushException::class);

        $provider->send($subscription, 'Заголовок', 'Текст');
    }

    /**
     * Проверяем, что WebPushProvider выбрасывает PermanentPushException при неудачной отправке.
     */
    public function test_webpush_provider_throws_exception_on_failed_send(): void
    {
        $failedReport = new MessageSentReport(
            new Request('POST', 'https://example.com/push'),
            null,
            false,
            'Server error'
        );

        $webPush = $this->createMock(WebPush::class);
        $webPush->method('queueNotification');
        $webPush->method('flush')->willReturn((function () use ($failedReport) {
            yield $failedReport;
        })());

        $provider = new WebPushProvider($webPush);

        $subscription = new PushSubscription([
            'endpoint' => 'https://example.com/push',
            'public_key' => 'valid_public_key_placeholder',
            'auth_token' => 'valid_auth_token_placeholder',
        ]);

        $this->expectException(PermanentPushException::class);

        $provider->send($subscription, 'Заголовок', 'Текст');
    }

    /**
     * Проверяем, что HTTP 410/404 в отчёте WebPush классифицируется как DeviceNotRegistered.
     */
    public function test_webpush_provider_classifies_gone_as_device_not_registered(): void
    {
        $failedReport = new MessageSentReport(
            new Request('POST', 'https://example.com/push'),
            new Response(410),
            false,
            'Subscription no longer exists'
        );

        $webPush = $this->createMock(WebPush::class);
        $webPush->method('queueNotification');
        $webPush->method('flush')->willReturn((function () use ($failedReport) {
            yield $failedReport;
        })());

        $provider = new WebPushProvider($webPush);

        $subscription = new PushSubscription([
            'endpoint' => 'https://example.com/push',
            'public_key' => 'valid_public_key_placeholder',
            'auth_token' => 'valid_auth_token_placeholder',
        ]);

        $this->expectException(DeviceNotRegisteredException::class);

        $provider->send($subscription, 'Заголовок', 'Текст');
    }
}
