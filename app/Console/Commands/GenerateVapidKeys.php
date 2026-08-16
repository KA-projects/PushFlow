<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid:generate';

    protected $description = 'Генерирует пару VAPID ключей и записывает их в файл .env';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->updateEnv('VAPID_PUBLIC_KEY', $keys['publicKey']);
        $this->updateEnv('VAPID_PRIVATE_KEY', $keys['privateKey']);

        $this->info('VAPID ключи сгенерированы и сохранены в .env');

        return self::SUCCESS;
    }

    /**
     * Обновляет (или добавляет) переменную в файле .env.
     */
    private function updateEnv(string $key, string $value): void
    {
        $path = base_path('.env');

        $content = file_get_contents($path);
        $pattern = "/^{$key}=.*$/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= PHP_EOL.$replacement.PHP_EOL;
        }

        file_put_contents($path, $content);
    }
}
