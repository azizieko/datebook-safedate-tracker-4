(function () {
  'use strict';

  let deferredInstallPrompt = null;
  let lastNotificationId = parseInt(localStorage.getItem('dbsd_last_notification_id') || '0', 10);

  function status(message) {
    document.querySelectorAll('[data-dbsd-pwa-status]').forEach(function (el) { el.textContent = message; });
  }

  async function api(path, data, method) {
    const response = await fetch(DBSD_V04.restUrl + path, {
      method: method || 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DBSD_V04.nonce },
      body: (method || 'POST') === 'GET' ? undefined : JSON.stringify(data || {})
    });
    const json = await response.json();
    if (!response.ok) throw new Error(json.message || 'SafeDate request failed.');
    return json;
  }

  function base64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return null;
    return navigator.serviceWorker.register(DBSD_V04.swUrl, { updateViaCache: 'none' });
  }

  async function enablePush() {
    if (!DBSD_V04.pushEnabled || !('Notification' in window)) {
      status(DBSD_V04.strings.pushDenied);
      return;
    }
    const registration = await registerServiceWorker();
    if (!registration) { status(DBSD_V04.strings.pushDenied); return; }
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') { status(DBSD_V04.strings.pushDenied); return; }

    if ('PushManager' in window && DBSD_V04.publicKey) {
      const sub = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64ToUint8Array(DBSD_V04.publicKey)
      });
      await api('/push/subscribe', sub.toJSON());
      status(DBSD_V04.strings.pushReady);
    } else {
      // Fallback mode: no VAPID/public key yet. Browser notifications still work while app/page is open.
      status('Browser notifications enabled. Add VAPID keys for true background Web Push.');
    }
  }

  async function showLocalNotification(title, body, url, tag) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const registration = await navigator.serviceWorker.getRegistration(DBSD_V04.swUrl).catch(function () { return null; });
    const payload = { body: body || '', tag: tag || 'dbsd-notification', data: { url: url || window.location.href } };
    if (registration && registration.showNotification) registration.showNotification(title, payload);
    else new Notification(title, payload);
  }

  async function pollNotifications() {
    try {
      const result = await api('/me/notifications', null, 'GET');
      const rows = result.notifications || [];
      const newest = rows.reduce(function (max, n) { return Math.max(max, parseInt(n.id || '0', 10)); }, lastNotificationId);
      rows.slice().reverse().forEach(function (n) {
        const id = parseInt(n.id || '0', 10);
        if (id > lastNotificationId && n.status === 'pending') {
          showLocalNotification('SafeDate: ' + n.notification_type, n.message || 'You have a SafeDate notification.', window.location.href, 'dbsd-session-' + n.session_id);
        }
      });
      if (newest > lastNotificationId) {
        lastNotificationId = newest;
        localStorage.setItem('dbsd_last_notification_id', String(newest));
      }
    } catch (e) {}
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredInstallPrompt = event;
    document.querySelectorAll('[data-dbsd-install-app]').forEach(function (btn) { btn.disabled = false; });
  });

  document.addEventListener('click', async function (event) {
    if (event.target.closest('[data-dbsd-install-app]')) {
      if (!deferredInstallPrompt) {
        status('Use your browser menu to install this site as an app if the install prompt is not available yet.');
        return;
      }
      deferredInstallPrompt.prompt();
      await deferredInstallPrompt.userChoice.catch(function () {});
      deferredInstallPrompt = null;
      status('Install prompt completed.');
    }
    if (event.target.closest('[data-dbsd-enable-push]')) {
      try { await enablePush(); }
      catch (err) { status(err.message || DBSD_V04.strings.pushDenied); }
    }
  });

  registerServiceWorker().catch(function () {});
  setInterval(pollNotifications, Math.max(10, DBSD_V04.pollSeconds || 30) * 1000);
  pollNotifications();
})();
