<?php

namespace App\Console\Commands;

use App\Models\PushAttempt;
use App\Models\PushNotification;
use App\Models\PushSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PushStressReport extends Command
{
    protected $signature = 'push:stress:report
        {--expected= : Ожидаемое число notifications (обязательно)}';

    protected $description = 'Сверяет целостность данных после load-прогона и печатает сводку';

    public function handle(): int
    {
        $expected = (int) $this->option('expected');

        if ($expected <= 0) {
            $this->error('Ожидаемое число notifications не задано (--expected=N).');

            return self::FAILURE;
        }

        $failed = false;

        $this->line('');
        $this->line('Проверки целостности');
        $this->line('---------------------');

        // 1. Сид цел: активных подписок ровно столько, сколько заказывали.
        $subscriptions = PushSubscription::count();
        $failed = $this->check(
            $failed,
            "Активных подписок: {$subscriptions} (ожидалось: {$expected})",
            $subscriptions === $expected
        );

        $total = PushNotification::count();

        // 2. Все уведомления в терминальном статусе, ноль «зависших».
        $nonTerminal = PushNotification::whereNotIn('status', ['delivered', 'failed'])->count();
        $failed = $this->check(
            $failed,
            "Зависших уведомлений (pending/processing/accepted): {$nonTerminal}",
            $nonTerminal === 0
        );

        // 3. Фан-аут покрыл все подписки — ни одна не «потеряна».
        $subscriptionsWithoutNotification = PushSubscription::query()
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('notifications')
                    ->whereColumn('notifications.push_subscription_id', 'push_subscriptions.id');
            })
            ->count();
        $failed = $this->check(
            $failed,
            "Подписок без уведомлений: {$subscriptionsWithoutNotification}",
            $subscriptionsWithoutNotification === 0
        );

        // 4. У каждой записи ровно один accepted-попытка (нет дублей отправки).
        $acceptedPerNotification = PushAttempt::query()
            ->where('status', 'accepted')
            ->select('notification_id')
            ->get()
            ->groupBy('notification_id')
            ->map->count();

        $withWrongAccepted = $acceptedPerNotification->filter(fn (int $count) => $count !== 1)->count();
        $acceptedTotal = $acceptedPerNotification->sum();
        $failed = $this->check(
            $failed,
            "Уведомлений с числом accepted-попыток != 1: {$withWrongAccepted}",
            $withWrongAccepted === 0
        );
        $failed = $this->check(
            $failed,
            "Всего accepted-попыток: {$acceptedTotal} (всего уведомлений: {$total})",
            $acceptedTotal === $total
        );

        // 5. Очередь слита полностью.
        $jobs = (int) DB::table('jobs')->count();
        $failed = $this->check($failed, "Jobs в очереди: {$jobs}", $jobs === 0);

        $this->line('');
        $this->line('Сводка');
        $this->line('------');

        // Распределение статусов.
        $this->line('Распределение статусов:');
        $this->distribution(
            PushNotification::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->orderBy('status')
                ->get()
        );

        // Гистограмма попыток.
        $this->line('Гистограмма попыток (attempts):');
        $this->distribution(
            PushNotification::query()
                ->selectRaw('CAST(attempts AS INTEGER) as attempts, count(*) as count')
                ->groupBy('attempts')
                ->orderBy('attempts')
                ->get()
        );

        // Пропускная способность по created_at.
        $this->line('Пропускная способность:');
        $window = PushNotification::query()
            ->selectRaw('MIN(created_at) as first, MAX(created_at) as last')
            ->first();

        $first = $window && $window->first ? Carbon::parse($window->first) : null;
        $last = $window && $window->last ? Carbon::parse($window->last) : null;
        $elapsed = $first && $last ? max(1, (int) $last->diffInSeconds($first)) : 1;
        $rate = $total / $elapsed;

        $this->line(sprintf(
            '  Уведомлений: %d, окно: %s — %s (%ds), скорость: %.1f/с',
            $total,
            $first?->format('H:i:s') ?? '-',
            $last?->format('H:i:s') ?? '-',
            $elapsed,
            $rate
        ));

        // Ошибки.
        $errors = PushNotification::query()
            ->selectRaw('error_code, count(*) as count')
            ->whereNotNull('error_code')
            ->groupBy('error_code')
            ->orderByDesc('count')
            ->get();

        $this->line('Ошибки:');
        if ($errors->isEmpty()) {
            $this->line('  нет');
        } else {
            $this->distribution($errors);
        }

        $this->line('');
        if ($failed) {
            $this->error('Результат: ПРОВАЛ — найдены расхождения в целостности данных.');

            return self::FAILURE;
        }

        $this->info('Результат: УСПЕХ — целостность данных подтверждена.');

        return self::SUCCESS;
    }

    /**
     * Печать проверки и накопление признака провала.
     */
    protected function check(bool $failed, string $label, bool $ok): bool
    {
        $this->line(sprintf('  %-70s [%s]', $label, $ok ? 'OK' : 'FAIL'));

        return $failed || ! $ok;
    }

    /**
     * Печать распределения (метка → количество).
     */
    protected function distribution(Collection $rows): void
    {
        foreach ($rows as $row) {
            $value = $row->status?->value ?? $row->status ?? $row->attempts ?? $row->error_code;

            $this->line(sprintf('  %s: %s', $value, $row->count));
        }
    }
}
