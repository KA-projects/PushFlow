<?php

namespace App\Console\Commands;

use App\Models\PushNotification;
use App\Models\PushSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PushStressSeed extends Command
{
    protected $signature = 'push:stress:seed
        {--count=1000 : Количество активных подписок}
        {--provider=fcm : Провайдер подписок}';

    protected $description = 'Создаёт N активных подписок напрямую в БД для нагрузочного тестирования';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $provider = (string) $this->option('provider');

        // Очистка данных предыдущего прогона — прогон идемпотентен.
        // push_attempts удаляются каскадом вместе с notifications.
        PushNotification::query()->delete();
        PushSubscription::query()->delete();

        foreach (collect(range(1, $count))->chunk(500) as $chunk) {
            $rows = $chunk->map(fn (int $i) => [
                'provider' => $provider,
                'endpoint' => "https://stress.example.com/{$provider}-{$i}-".Str::uuid(),
                // Валидные base64url-ключи: без них webpush-провайдер не сможет
                // декодировать p256dh/auth и будет падать при отправке.
                'public_key' => rtrim(strtr(base64_encode(random_bytes(65)), '+/', '-_'), '='),
                'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            PushSubscription::insert($rows);
        }

        $this->info("Создано {$count} активных подписок (провайдер: {$provider}).");

        return self::SUCCESS;
    }
}
