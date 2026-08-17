# PushFlow

Сервис push-уведомлений на Laravel 12 (PHP 8.2, PostgreSQL, Redis). Запускается в Docker, отправка — асинхронно через очередь (Laravel Horizon). Провайдеры: `webpush` (VAPID) и `fcm` (мок).

## Запуск

```bash
cp .env.example .env
docker compose up -d                                    # сервисы: app, queue, postgres, redis
docker compose exec app php artisan migrate --force
docker compose exec app php artisan key:generate
docker compose exec app php artisan push:vapid:generate # сгенерировать VAPID-ключи в .env
docker compose restart app queue
```

Проверка: `curl localhost:8000/api/health` → `{"status":"ok"}`.

Тестовая страница: http://localhost:8000/push-test (подписка + отправка прямо из браузера).

## Возможности

- Сохранение Web Push подписки браузера (endpoint + ключи).
- Отправка уведомления одной подписке (по `endpoint`) или всем — через очередь.
- Сменяемые провайдеры доставки через `PushNotificationManager`.
- Проверка здоровья.

## API

| Метод | Маршрут | Тело | Описание |
|-------|---------|------|----------|
| GET | `/api/health` | — | Проверка |
| POST | `/api/push/subscribe` | `endpoint`, `keys.{p256dh,auth}` | Сохранить подписку |
| POST | `/api/push/send` | `title`, `body`, `endpoint?`, `extra?` | Отправить уведомление |

```bash
curl -X POST localhost:8000/api/push/send \
  -H "Content-Type: application/json" \
  -d '{"endpoint":"https://...","title":"Привет","body":"Тест"}'
```

→ `200`, `{"message":"Уведомления поставлены в очередь.","queued":1}`

## Команды

```bash
docker compose exec app php artisan test               # тесты
docker compose exec app php artisan test --filter=Stress   # только стресс-тесты (in-process)
docker compose exec app ./vendor/bin/pint              # стиль кода
docker compose exec app php artisan horizon            # воркер очереди (Horizon)
docker compose exec app php artisan push:stress:seed --count=1000 --provider=fcm  # сид подписок для live-стресса
docker compose exec app php artisan push:stress:report --expected=1000            # отчёт по целостности после live-стресса
bash stress/run.sh                                     # полный live-стресс (k6)
```

## Мониторинг очереди (Horizon)

Очередью управляет Laravel Horizon: сервис `queue` в compose запускает `php artisan horizon`
(супервизоры с воркерами). Дашборд Horizon доступен на http://localhost:8000/horizon.

Настройки воркеров (`supervisor-1`) задаются переменными в `.env`:

| Переменная | По умолчанию | Описание |
|------------|--------------|----------|
| `HORIZON_TRIES` | `3` | Попытки обработки задачи |
| `HORIZON_TIMEOUT` | `30` | Таймаут задачи, сек |
| `HORIZON_MAX_PROCESSES` | `3` | Число воркеров на супервизор |

Масштабирование — через число воркеров в супервизоре (поднимайте `HORIZON_MAX_PROCESSES`),
а не через число реплик сервиса. В не-local окружениях доступ к дашборду ограничен
гейтом `viewHorizon` в `app/Providers/HorizonServiceProvider.php`.

## Просмотр данных в БД (tinker)

```bash
# Количество записей в каждой таблице
docker compose exec app php artisan tinker --execute="foreach (['users','push_subscriptions','notifications','push_attempts','jobs'] as \$t) echo \$t.': '.DB::table(\$t)->count().PHP_EOL;"

# Список подписок
docker compose exec app php artisan tinker --execute="App\Models\PushSubscription::with('user')->get(['id','user_id','provider','is_active','endpoint'])->each(fn(\$s) => dump(\$s->only(['id','user_id','provider','is_active'])))"

# Список уведомлений (id, подписка, статус, попытки, ошибки)
docker compose exec app php artisan tinker --execute="App\Models\PushNotification::orderBy('id')->get(['id','push_subscription_id','title','status','provider','attempts','error_code','error_message','sent_at','delivered_at','failed_at'])->each(fn(\$n) => dump(\$n->toArray()))"

# Попытки отправки
docker compose exec app php artisan tinker --execute="App\Models\PushAttempt::orderBy('id')->get()->each(fn(\$a) => dump(\$a->toArray()))"

# Задачи в очереди (включая застрявшие)
docker compose exec app php artisan tinker --execute="dump(DB::table('jobs')->count()); DB::table('jobs')->orderBy('id')->get()->each(fn(\$j) => dump(['id'=>\$j->id,'queue'=>\$j->queue,'attempts'=>\$j->attempts,'payload'=>json_decode(\$j->payload,true)['displayName'] ?? null]))"
```

Интерактивно: `docker compose exec app php artisan tinker` (далее `App\Models\PushNotification::all();` и т.п.).

## Стресс-тесты

### In-process (PHPUnit, офлайн)

Массовые сценарии на SQLite `:memory:` + sync queue: фан-аут на 500 подписок без дублей,
атомарный claim при гонке воркеров, маршрутизация по провайдерам, шторм retry, массовая
проверка receipts.

```bash
docker compose exec app php artisan test --filter=Stress
```

### Live (реальный HTTP-нагрузка через k6)

Полный прогон по живому Docker-стеку (`app` + `queue` + `postgres` + `redis`): сид подписок →
k6-нагрузка на `/api/push/send` → ожидание слива очереди → отчёт по целостности данных.

```bash
# 1. Для быстрого слива очереди в .env выставить PUSH_RECEIPT_DELAY=1
# 2. Запустить оркестратор (поднимет стек, мигрирует, засеет подписки, запустит k6, проверит целостность)
bash stress/run.sh
```

```bash
EXPECTED=100 PROVIDER=webpush bash stress/run.sh
```
Параметры (env): `EXPECTED` — число подписок/ожидание отчёта (по умолчанию `1000`), `PROVIDER` — провайдер подписок (`fcm`). Сценарий и пороги k6 — в `stress/k6/send.js` (по умолчанию 0→50 VU за 30s, hold 60s, ramp-down; `http_req_failed < 1%`, `p95 < 500ms`).

Вручную (эквивалент `run.sh`):

```bash
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan push:stress:seed --count=1000 --provider=fcm
NET=$(docker network ls --format '{{.Name}}' | grep pushflow)
docker run --rm -i --network "$NET" -e "APP_URL=http://app:8000" \
  -v "$PWD/stress/k6:/scripts" grafana/k6 run /scripts/send.js
docker compose exec app php artisan push:stress:report --expected=1000   # exit 0/1 для CI
```

Внимание: каждый запрос без `endpoint` создаёт `count` notifications + `count` job'ов, т.е.
полная нагрузка = запросы × число подписок. Для быстрого прогона уменьшайте `EXPECTED`
(например, `EXPECTED=100 bash stress/run.sh`).
