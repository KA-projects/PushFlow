<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Push Test</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 640px;
            margin: 0 auto;
            padding: 2rem 1rem;
            color: #1b1b18;
        }

        button {
            padding: 0.6rem 1.25rem;
            border: 1px solid #1b1b18;
            border-radius: 6px;
            background: #1b1b18;
            color: #fff;
            font-size: 1rem;
            cursor: pointer;
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        #status {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            background: #f4f4f2;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <h1>Push-уведомления</h1>
    <p>Нажмите кнопку, чтобы подписаться на push-уведомления.</p>
    <button id="subscribe" type="button">Подписаться</button>
    <p id="status">Инициализация...</p>

    <script>
        const applicationServerKey = @json(config('webpush.vapid.public_key'));
        const subscribeButton = document.getElementById('subscribe');
        const status = document.getElementById('status');

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = atob(base64);
            const outputArray = new Uint8Array(rawData.length);

            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }

            return outputArray;
        }

        async function saveSubscription(subscription) {
            const response = await fetch('/api/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription),
            });

            if (!response.ok) {
                throw new Error('Не удалось сохранить подписку (HTTP ' + response.status + ')');
            }
        }

        async function subscribe() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                status.textContent = 'Браузер не поддерживает push-уведомления.';
                return;
            }

            if (!applicationServerKey) {
                status.textContent = 'VAPID public key не настроен в конфигурации (webpush.vapid.public_key).';
                return;
            }

            try {
                const registration = await navigator.serviceWorker.register('/service-worker.js');
                await navigator.serviceWorker.ready;

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(applicationServerKey),
                });

                await saveSubscription(subscription);
                status.textContent = 'Подписка успешно сохранена.';
            } catch (error) {
                status.textContent = 'Ошибка: ' + error.message;
            }
        }

        subscribeButton.addEventListener('click', subscribe);

        (async () => {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                status.textContent = 'Браузер не поддерживает push-уведомления.';
                subscribeButton.disabled = true;
                return;
            }

            const registration = await navigator.serviceWorker.getRegistration();
            const existing = registration ? await registration.pushManager.getSubscription() : null;

            if (existing) {
                status.textContent = 'Вы уже подписаны на push-уведомления.';
            } else {
                status.textContent = 'Готово к подписке. Нажмите кнопку выше.';
            }
        })();
    </script>
</body>
</html>
