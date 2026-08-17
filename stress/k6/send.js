import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    stages: [
        { duration: '30s', target: 50 },
        { duration: '60s', target: 50 },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<500'],
        checks: ['rate>0.99'],
    },
};

const BASE_URL = __ENV.APP_URL || 'http://app:8000';

export default function () {
    const res = http.post(
        `${BASE_URL}/api/push/send`,
        JSON.stringify({
            title: 'Стресс-тест',
            body: 'Нагрузочное тестирование PushFlow',
        }),
        { headers: { 'Content-Type': 'application/json' } },
    );

    check(res, {
        'status is 200': (r) => r.status === 200,
        'queued > 0': (r) => r.json('queued') > 0,
    });
}