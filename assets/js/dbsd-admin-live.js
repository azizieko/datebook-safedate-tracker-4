(function () {
  'use strict';
  const root = document.getElementById('dbsd-live-root');
  if (!root) return;

  async function api(path) {
    const response = await fetch(DBSD_ADMIN_LIVE.restUrl + path, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': DBSD_ADMIN_LIVE.nonce }
    });
    const json = await response.json();
    if (!response.ok) throw new Error(json.message || 'Unable to load live monitor.');
    return json;
  }

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>\"]/g, function (ch) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[ch];
    });
  }

  function table(rows, cols, empty) {
    if (!rows || !rows.length) return '<p>' + esc(empty || 'No records.') + '</p>';
    return '<table class="widefat striped"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.map(function (r) {
      return '<tr>' + cols.map(function (c) { return '<td>' + esc(r[c.key]) + '</td>'; }).join('') + '</tr>';
    }).join('') + '</tbody></table>';
  }

  async function refresh() {
    try {
      const data = await api('/admin/live');
      root.innerHTML = '<div class="dbsd-live-cards">' +
        '<div class="dbsd-live-card"><strong>' + esc(data.counts.active_sessions) + '</strong><span>Recent sessions</span></div>' +
        '<div class="dbsd-live-card"><strong>' + esc(data.counts.active_alerts) + '</strong><span>Active alerts</span></div>' +
        '<div class="dbsd-live-card"><strong>' + esc(data.counts.open_incidents) + '</strong><span>Open incidents</span></div>' +
        '<div class="dbsd-live-card"><strong>' + esc(data.counts.push_subscriptions) + '</strong><span>Push devices</span></div>' +
        '</div><p><small>Last refreshed: ' + esc(data.generated_at) + '</small></p>' +
        '<h2>Active Alerts</h2>' + table(data.alerts, [
          {key:'id', label:'Session'}, {key:'alert_level', label:'Alert'}, {key:'status', label:'Status'}, {key:'host_user_id', label:'Host'}, {key:'traveler_user_id', label:'Traveler'}, {key:'last_location_at', label:'Last location'}, {key:'updated_at', label:'Updated'}
        ], 'No active alerts.') +
        '<h2>Open Incidents</h2>' + table(data.incidents, [
          {key:'id', label:'ID'}, {key:'session_id', label:'Session'}, {key:'severity', label:'Severity'}, {key:'incident_type', label:'Type'}, {key:'admin_status', label:'Status'}, {key:'created_at', label:'Created'}
        ], 'No open incidents.') +
        '<h2>Recent Audit Events</h2>' + table(data.events, [
          {key:'id', label:'ID'}, {key:'session_id', label:'Session'}, {key:'actor_user_id', label:'Actor'}, {key:'event_type', label:'Event'}, {key:'created_at', label:'Created'}
        ], 'No events yet.') +
        '<h2>Recent Sessions</h2>' + table(data.sessions, [
          {key:'id', label:'ID'}, {key:'status', label:'Status'}, {key:'alert_level', label:'Alert'}, {key:'host_user_id', label:'Host'}, {key:'traveler_user_id', label:'Traveler'}, {key:'updated_at', label:'Updated'}
        ], 'No sessions yet.');
    } catch (err) {
      root.innerHTML = '<div class="notice notice-error"><p>' + esc(err.message) + '</p></div>';
    }
  }

  refresh();
  setInterval(refresh, Math.max(5, DBSD_ADMIN_LIVE.refreshSeconds || 15) * 1000);
})();
