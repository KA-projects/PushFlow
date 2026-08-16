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
