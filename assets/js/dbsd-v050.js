(function () {
  'use strict';

  async function api(path, data, method) {
    const response = await fetch(DBSD_V05.restUrl + path, {
      method: method || 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DBSD_V05.nonce },
      body: (method || 'POST') === 'GET' ? undefined : JSON.stringify(data || {})
    });
    const json = await response.json();
    if (!response.ok) throw new Error(json.message || 'SafeDate request failed.');
    return json;
  }

  function setStatus(root, message) {
    const el = root.querySelector('[data-dbsd-v05-status]');
    if (el) el.textContent = message;
  }

  function output(root, data) {
    const el = root.querySelector('[data-dbsd-v05-output]');
    if (el) el.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
  }

  async function readBattery() {
    try {
      if (navigator.getBattery) {
        const battery = await navigator.getBattery();
        return { battery_level: battery.level, charging: battery.charging };
      }
    } catch (e) {}
    return { battery_level: null, charging: null };
  }

  async function permissionState() {
    try {
      if (navigator.permissions && navigator.permissions.query) {
        const res = await navigator.permissions.query({ name: 'geolocation' });
        return res.state || '';
      }
    } catch (e) {}
    return '';
  }

  async function sendDeviceHealth(sessionId) {
    if (!sessionId) return;
    const battery = await readBattery();
    const perm = await permissionState();
    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection || {};
    try {
      await api('/device/health', {
        session_id: sessionId,
        online_status: navigator.onLine ? 'online' : 'offline',
        battery_level: battery.battery_level,
        charging: battery.charging,
        permission_state: perm,
        network_type: conn.effectiveType || conn.type || ''
      });
    } catch (e) {}
  }

  async function latestPendingCheckin(sessionId) {
    const data = await api('/session/' + sessionId + '/safety-status', null, 'GET');
    const pending = (data.checkins || []).filter(function (c) { return c.status === 'pending' || c.status === 'overdue'; });
    return pending.length ? pending[0] : null;
  }

  document.addEventListener('click', async function (event) {
    const action = event.target && event.target.dataset ? event.target.dataset.dbsdV05 : '';
    if (!action) return;
    const root = event.target.closest('.dbsd-v05-safety, .dbsd-v05-privacy');
    if (!root) return;
    const sessionId = parseInt(root.dataset.dbsdV05Session || '0', 10);
    try {
      if (action === 'refresh-status') {
        if (!sessionId) throw new Error('Add a session id to the shortcode, e.g. [db_safedate_safety_center id="123"].');
        await sendDeviceHealth(sessionId);
        const data = await api('/session/' + sessionId + '/safety-status', null, 'GET');
        setStatus(root, 'Safety status refreshed.');
        output(root, data);
      }
      if (action === 'request-self-checkin') {
        if (!sessionId) throw new Error('Missing session id.');
        const userId = window.DBSD && DBSD.currentUserId ? parseInt(DBSD.currentUserId, 10) : 0;
        const data = await api('/checkin/request', { session_id: sessionId, requested_for: userId, due_in_minutes: 10, prompt: 'Manual safety check: please confirm you are safe.' });
        setStatus(root, 'Check-in created.');
        output(root, data);
      }
      if (action === 'respond-safe' || action === 'respond-help') {
        if (!sessionId) throw new Error('Missing session id.');
        const checkin = await latestPendingCheckin(sessionId);
        if (!checkin) throw new Error('No pending check-in found for this session.');
        const payload = { checkin_id: checkin.id, safe: action === 'respond-safe', message: action === 'respond-safe' ? 'I am safe.' : 'I need help.' };
        if (navigator.geolocation) {
          try {
            const pos = await new Promise(function (resolve, reject) {
              navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 });
            });
            payload.lat = pos.coords.latitude;
            payload.lng = pos.coords.longitude;
          } catch (e) {}
        }
        const data = await api('/checkin/respond', payload);
        setStatus(root, data.status === 'safe' ? 'Marked safe.' : 'Help request sent.');
        output(root, data);
      }
      if (action === 'privacy-request') {
        const typeEl = root.querySelector('[data-dbsd-privacy-type]');
        const msgEl = root.querySelector('[data-dbsd-privacy-message]');
        const data = await api('/privacy/request', { request_type: typeEl ? typeEl.value : 'export', message: msgEl ? msgEl.value : '' });
        setStatus(root, 'Privacy request submitted.');
        output(root, data);
      }
      if (action === 'privacy-my-data') {
        const data = await api('/privacy/my-data', null, 'GET');
        setStatus(root, 'SafeDate data summary loaded.');
        output(root, data);
      }
    } catch (err) {
      setStatus(root, err.message);
    }
  });

  window.addEventListener('online', function () {
    const active = localStorage.getItem('dbsd_active_session_id');
    if (active) sendDeviceHealth(parseInt(active, 10));
  });

  setInterval(function () {
    const active = localStorage.getItem('dbsd_active_session_id');
    if (active) sendDeviceHealth(parseInt(active, 10));
  }, 60000);
})();
