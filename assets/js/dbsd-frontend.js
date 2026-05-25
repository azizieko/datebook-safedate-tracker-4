(function () {
  'use strict';

  let watchId = null;
  let activeSessionId = null;
  let pingQueue = [];

  async function api(path, data, method = 'POST') {
    const response = await fetch(DBSD.restUrl + path, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DBSD.nonce },
      body: method === 'GET' ? undefined : JSON.stringify(data || {})
    });
    const json = await response.json();
    if (!response.ok) throw new Error(json.message || DBSD.strings.error);
    return json;
  }

  function setStatus(root, message) {
    const el = root && root.querySelector ? root.querySelector('.dbsd-status, .dbsd-result') : null;
    if (el) el.textContent = message;
  }

  function collectFields(root) {
    const data = {};
    root.querySelectorAll('[data-field]').forEach((field) => { data[field.dataset.field] = field.value; });
    return data;
  }

  function esc(text) {
    return String(text == null ? '' : text).replace(/[&<>\"]/g, function (ch) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[ch];
    });
  }

  async function readBattery() {
    try { if (navigator.getBattery) { const b = await navigator.getBattery(); return b && typeof b.level === 'number' ? b.level : null; } } catch (e) {}
    return null;
  }

  function formatPosition(pos, battery) {
    return {
      session_id: activeSessionId,
      lat: pos.coords.latitude,
      lng: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
      speed: pos.coords.speed,
      heading: pos.coords.heading,
      battery_level: battery,
      recorded_at: new Date(pos.timestamp).toISOString().slice(0, 19).replace('T', ' ')
    };
  }

  async function flushQueue(root) {
    if (!navigator.onLine || !pingQueue.length) return;
    const pending = pingQueue.slice();
    pingQueue = [];
    for (const payload of pending) {
      try { await api('/location/ping-enhanced', payload); }
      catch (err) { pingQueue.push(payload); setStatus(root, 'Location queued until connection improves.'); break; }
    }
  }

  async function sendPosition(root, sessionId, pos) {
    const battery = await readBattery();
    activeSessionId = sessionId;
    const payload = formatPosition(pos, battery);
    if (!navigator.onLine) { pingQueue.push(payload); setStatus(root, 'Offline: location queued.'); return; }
    try { await api('/location/ping-enhanced', payload); await flushQueue(root); renderLocations(root, sessionId); }
    catch (err) { pingQueue.push(payload); setStatus(root, 'Location queued: ' + err.message); }
  }

  function startTracking(root, sessionId) {
    if (!navigator.geolocation) { setStatus(root, DBSD.strings.geoUnsupported); return; }
    activeSessionId = sessionId;
    localStorage.setItem('dbsd_active_session_id', String(sessionId));
    watchId = navigator.geolocation.watchPosition(
      function (pos) { sendPosition(root, sessionId, pos); },
      function () { setStatus(root, DBSD.strings.geoDenied); },
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 }
    );
    setStatus(root, DBSD.strings.started);
  }

  function stopTracking(root) {
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    if (activeSessionId) api('/journey/stop', { session_id: activeSessionId, reason: 'manual' }).catch(function () {});
    localStorage.removeItem('dbsd_active_session_id');
    activeSessionId = null;
    setStatus(root, DBSD.strings.stopped);
  }

  async function refreshAudit(root, sessionId) {
    const pre = root.querySelector('.dbsd-audit');
    if (!pre) return;
    try {
      const result = await api('/session/' + sessionId + '/audit', null, 'GET');
      pre.textContent = result.events.map(function (e) {
        return '[' + e.created_at + '] ' + e.event_type + ' by user ' + (e.actor_user_id || 'system');
      }).join('\n') || 'No events yet.';
    } catch (err) { pre.textContent = err.message; }
  }

  async function renderSession(root, sessionId) {
    try {
      const result = await api('/session/' + sessionId, null, 'GET');
      const s = result.session;
      setStatus(root, 'Status: ' + s.status + ' | Alert: ' + s.alert_level + ' | Your role: ' + result.role);
    } catch (err) { setStatus(root, err.message); }
  }

  function osmLink(lat, lng) {
    return 'https://www.openstreetmap.org/?mlat=' + encodeURIComponent(lat) + '&mlon=' + encodeURIComponent(lng) + '#map=16/' + encodeURIComponent(lat) + '/' + encodeURIComponent(lng);
  }

  async function renderLocations(root, sessionId) {
    const box = root.querySelector('[data-dbsd-map]');
    if (!box) return;
    try {
      const result = await api('/session/' + sessionId + '/locations', null, 'GET');
      const rows = result.locations || [];
      if (!rows.length) { box.textContent = 'No movement pings yet.'; return; }
      const latest = rows[rows.length - 1];
      box.innerHTML = '<div class="dbsd-location-latest"><strong>Latest:</strong> ' + latest.lat + ', ' + latest.lng + ' at ' + latest.recorded_at + ' <a target="_blank" rel="noopener" href="' + osmLink(latest.lat, latest.lng) + '">Open map</a></div>' +
        '<ol class="dbsd-location-list">' + rows.slice(-10).reverse().map(function (r) { return '<li>' + r.recorded_at + ' — ' + r.lat + ', ' + r.lng + ' accuracy ' + (r.accuracy || 'n/a') + 'm</li>'; }).join('') + '</ol>';
    } catch (err) { box.textContent = err.message; }
  }

  async function renderNotifications() {
    document.querySelectorAll('[data-dbsd-notifications]').forEach(async function (box) {
      try {
        const result = await api('/me/notifications', null, 'GET');
        const rows = result.notifications || [];
        box.innerHTML = rows.length ? rows.map(function (n) {
          return '<div class="dbsd-note"><strong>' + n.notification_type + '</strong><br>' + (n.message || '') + '<br><small>Session #' + n.session_id + ' · ' + n.status + ' · ' + n.created_at + '</small></div>';
        }).join('') : 'No notifications.';
      } catch (err) { box.textContent = err.message; }
    });
  }

  async function getCurrentPositionPayload(sessionId) {
    if (!navigator.geolocation) return { session_id: sessionId };
    return new Promise(function (resolve) {
      navigator.geolocation.getCurrentPosition(function (pos) {
        resolve({ session_id: sessionId, lat: pos.coords.latitude, lng: pos.coords.longitude });
      }, function () { resolve({ session_id: sessionId }); }, { enableHighAccuracy: true, timeout: 10000 });
    });
  }

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    const action = button.dataset.action;
    const createRoot = button.closest('[data-dbsd-create]');
    const sessionRoot = button.closest('[data-dbsd-session]');
    const contactRoot = button.closest('[data-dbsd-contact-form]');
    const shareRoot = button.closest('[data-dbsd-share-form]');
    const incidentRoot = button.closest('[data-dbsd-incident-form]');
    const pairingRoot = button.closest('.dbsd-card');

    try {
      if (createRoot && action === 'create-session') {
        const result = await api('/session/create', collectFields(createRoot));
        setStatus(createRoot, 'Created SafeDate session #' + result.session_id + '. Add shortcode: [db_safedate_session id="' + result.session_id + '"]');
        renderNotifications();
        return;
      }
      if (contactRoot && action === 'save-contact') {
        const result = await api('/contacts/save', collectFields(contactRoot));
        setStatus(contactRoot, result.ok ? 'Emergency contact saved.' : DBSD.strings.error);
        return;
      }
      if (shareRoot && action === 'create-share') {
        const result = await api('/share/create', collectFields(shareRoot));
        const box = shareRoot.querySelector('.dbsd-result');
        if (box) box.innerHTML = result.ok ? 'Share link created. Copy this temporary link:<br><input readonly class=\"dbsd-copy\" value=\"' + esc(result.share_url) + '\"><br><small>Expires UTC: ' + esc(result.expires_at) + '</small>' : DBSD.strings.error;
        return;
      }
      if (incidentRoot && action === 'report-incident') {
        const result = await api('/incident/report', collectFields(incidentRoot));
        setStatus(incidentRoot, result.ok ? 'Incident report submitted #' + result.incident_id + '.' : DBSD.strings.error);
        return;
      }

      if (action === 'create-pairing-code') {
        const result = await api('/mobile/pairing-code', {});
        const box = (pairingRoot || document).querySelector('[data-dbsd-pairing-result]');
        if (box) box.textContent = result.ok ? ('Pairing ID: ' + result.pairing_id + '\nPairing code: ' + result.code + '\nExpires UTC: ' + result.expires_at + '\nEnter both the Pairing ID and code in the SafeDate native app.') : DBSD.strings.error;
        return;
      }
      if (!sessionRoot) return;
      const sessionId = parseInt(sessionRoot.dataset.dbsdSession, 10);
      let result;
      switch (action) {
        case 'consent-accept': result = await api('/session/consent', { session_id: sessionId, accepted: true }); break;
        case 'consent-decline': result = await api('/session/consent', { session_id: sessionId, accepted: false }); break;
        case 'journey-start': await api('/journey/start', { session_id: sessionId }); startTracking(sessionRoot, sessionId); await refreshAudit(sessionRoot, sessionId); await renderLocations(sessionRoot, sessionId); return;
        case 'journey-stop': stopTracking(sessionRoot); await refreshAudit(sessionRoot, sessionId); return;
        case 'arrival-claim': result = await api('/arrival/claim', { session_id: sessionId }); break;
        case 'arrival-confirm': result = await api('/arrival/respond', { session_id: sessionId, accepted: true }); break;
        case 'arrival-reject': result = await api('/arrival/respond', { session_id: sessionId, accepted: false }); break;
        case 'departure-claim': result = await api('/departure/claim', { session_id: sessionId }); break;
        case 'departure-confirm': result = await api('/departure/respond', { session_id: sessionId, accepted: true }); break;
        case 'departure-reject': result = await api('/departure/respond', { session_id: sessionId, accepted: false }); break;
        case 'sos': result = await api('/emergency/sos', await getCurrentPositionPayload(sessionId)); break;
        case 'export-session':
          result = await api('/session/' + sessionId + '/export', null, 'GET');
          const exportBox = sessionRoot.querySelector('.dbsd-export');
          if (exportBox) {
            exportBox.innerHTML = '<textarea readonly class=\"dbsd-export-json\">' + esc(JSON.stringify(result.data, null, 2)) + '</textarea>';
          }
          break;
      }
      if (result && result.ok) setStatus(sessionRoot, DBSD.strings.saved);
      await renderSession(sessionRoot, sessionId); await refreshAudit(sessionRoot, sessionId); await renderLocations(sessionRoot, sessionId); renderNotifications();
    } catch (err) { setStatus(createRoot || sessionRoot || contactRoot || shareRoot || incidentRoot || document.body, err.message || DBSD.strings.error); }
  });

  window.addEventListener('online', function () {
    document.querySelectorAll('[data-dbsd-session]').forEach(function (root) { if (activeSessionId) flushQueue(root); });
  });

  document.querySelectorAll('[data-dbsd-session]').forEach(function (root) {
    const sessionId = parseInt(root.dataset.dbsdSession, 10);
    renderSession(root, sessionId); refreshAudit(root, sessionId); renderLocations(root, sessionId);
  });
  renderNotifications();
})();
