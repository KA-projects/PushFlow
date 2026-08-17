#!/usr/bin/env bash
#
# Оркестратор live-стресс-теста PushFlow (k6 + Docker-стек).
# Идемпотентен: перезапуск полностью пересоздаёт нагрузочные данные.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

EXPECTED="${EXPECTED:-1000}"
PROVIDER="${PROVIDER:-fcm}"

echo "==> [1/7] Поднимаем стек и применяем миграции"
docker compose up -d
docker compose exec -T app php artisan migrate --force

echo "==> [2/7] Масштабируем воркеров очереди (3)"
docker compose up -d --scale queue=3

echo "==> [3/7] Сидим активные подписки (${EXPECTED}, провайдер ${PROVIDER})"
docker compose exec -T app php artisan push:stress:seed --count="${EXPECTED}" --provider="${PROVIDER}"

echo "==> [4/7] Детектируем Docker-сеть"
NET="$(docker network ls --format '{{.Name}}' | grep '^pushflow' | head -n1)"
if [[ -z "$NET" ]]; then
    echo "Ошибка: Docker-сеть pushflow не найдена." >&2
    exit 1
fi
echo "    сеть: ${NET}"

echo "==> [5/7] Запускаем k6-нагрузку"
docker run --rm -i \
    --network "$NET" \
    -e "APP_URL=http://app:8000" \
    -v "$ROOT/stress/k6:/scripts" \
    grafana/k6 run /scripts/send.js

echo "==> [6/7] Ожидаем полного слива очереди"
for i in $(seq 1 120); do
    JOBS="$(docker compose exec -T app php artisan tinker --execute="echo DB::table('jobs')->count();" 2>/dev/null | grep -Eo '[0-9]+' | tail -n1 | tr -d '[:space:]' || true)"

    if [[ "${JOBS:-}" == "0" ]]; then
        echo "    очередь пуста."
        break
    fi

    if [[ "$i" -eq 120 ]]; then
        echo "Ошибка: очередь не слилась за отведённое время (${JOBS:-?} job'ов)." >&2
        exit 1
    fi

    echo "    ждём: ${JOBS:-?} job'ов в очереди..."
    sleep 3
done

echo "==> [7/7] Отчёт по целостности данных"
docker compose exec -T app php artisan push:stress:report --expected="${EXPECTED}"

echo "Готово."