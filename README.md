# PushFlow

Сервис push-уведомлений на Laravel 12 (PHP 8.2, PostgreSQL, Redis). Запускается в Docker, отправка — асинхронно через очередь. Провайдеры: `webpush` (VAPID) и `fcm` (мок).

## Запуск

```bash
docker compose up -d                                    # сервисы: app, queue, postgres, redis
docker compose exec app php artisan migrate --force
docker compose exec app php artisan push:vapid:generate # сгенерировать VAPID-ключи в .env
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
docker compose exec app ./vendor/bin/pint              # стиль кода
docker compose exec app php artisan queue:work redis   # воркер очереди вручную
docker compose up -d --scale queue=3                   # масштабировать воркеры
```

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
