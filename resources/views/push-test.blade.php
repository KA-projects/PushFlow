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

        input {
            padding: 0.5rem 0.75rem;
            border: 1px solid #d4d4cf;
            border-radius: 6px;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        #status {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            background: #f4f4f2;
            white-space: pre-wrap;
        }

        .send {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e3e3df;
        }
    </style>
</head>
<body>
    <h1>Push-уведомления</h1>
    <p>Нажмите кнопку, чтобы подписаться на push-уведомления.</p>
    <button id="subscribe" type="button">Подписаться</button>
    <button id="unsubscribe" type="button" disabled>Отписаться</button>
    <p id="status">Инициализация...</p>

    <section class="send">
        <h2>Тестовая отправка</h2>
        <input id="title" type="text" placeholder="Заголовок" value="Тестовое уведомление">
        <input id="body" type="text" placeholder="Текст" value="Привет из PushFlow!">
        <button id="send" type="button" disabled>Отправить</button>
        <p id="send-status">Сначала подпишитесь.</p>
    </section>

    <script>
        const applicationServerKey = @json(config('webpush.vapid.public_key'));
        const subscribeButton = document.getElementById('subscribe');
        const unsubscribeButton = document.getElementById('unsubscribe');
        const status = document.getElementById('status');
        const sendButton = document.getElementById('send');
        const sendStatus = document.getElementById('send-status');
        const titleInput = document.getElementById('title');
        const bodyInput = document.getElementById('body');

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
                updateUiState(true);
            } catch (error) {
                status.textContent = 'Ошибка: ' + error.message;
            }
        }

        async function unsubscribe() {
            const registration = await navigator.serviceWorker.getRegistration();
            const subscription = registration ? await registration.pushManager.getSubscription() : null;

            if (!subscription) {
                status.textContent = 'Вы не подписаны на уведомления.';
                updateUiState(false);
                return;
            }

            unsubscribeButton.disabled = true;
            status.textContent = 'Отписка...';

            try {
                const response = await fetch('/api/push/unsubscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });

                if (!response.ok && response.status !== 404) {
                    throw new Error('Не удалось отписаться (HTTP ' + response.status + ')');
                }

                await subscription.unsubscribe();
                status.textContent = 'Вы отписались от push-уведомлений.';
                updateUiState(false);
            } catch (error) {
                status.textContent = 'Ошибка: ' + error.message;
                unsubscribeButton.disabled = false;
            }
        }

        function updateUiState(subscribed) {
            subscribeButton.disabled = subscribed;
            unsubscribeButton.disabled = !subscribed;
            sendButton.disabled = !subscribed;
            sendStatus.textContent = subscribed
                ? 'Нажмите «Отправить», чтобы получить уведомление.'
                : 'Сначала подпишитесь.';
        }

        async function sendPush() {
            const registration = await navigator.serviceWorker.getRegistration();
            const subscription = registration ? await registration.pushManager.getSubscription() : null;

            if (!subscription) {
                sendStatus.textContent = 'Сначала подпишитесь на уведомления.';
                return;
            }

            sendButton.disabled = true;
            sendStatus.textContent = 'Отправка...';

            try {
                const response = await fetch('/api/push/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        endpoint: subscription.endpoint,
                        title: titleInput.value || 'Тестовое уведомление',
                        body: bodyInput.value || 'Привет из PushFlow!',
                    }),
                });

                const data = await response.json();

                sendStatus.textContent = response.ok
                    ? 'Поставлено в очередь: ' + data.message
                    : 'Ошибка: ' + (data.message || 'HTTP ' + response.status);
            } catch (error) {
                sendStatus.textContent = 'Ошибка: ' + error.message;
            } finally {
                sendButton.disabled = false;
            }
        }

        subscribeButton.addEventListener('click', subscribe);
        unsubscribeButton.addEventListener('click', unsubscribe);
        sendButton.addEventListener('click', sendPush);

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
                updateUiState(true);
            } else {
                status.textContent = 'Готово к подписке. Нажмите кнопку выше.';
                updateUiState(false);
            }
        })();
    </script>
</body>
</html>
