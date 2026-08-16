self.addEventListener('push', event => {
    const data = event.data ? event.data.json() : { title: 'Notification', body: '' };
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon || '/icon.png',
            data: data.data || {}
        })
    );
});
