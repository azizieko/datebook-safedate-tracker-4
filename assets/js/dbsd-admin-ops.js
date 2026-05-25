(function () {
  'use strict';
  const root = document.getElementById('dbsd-ops-root');
  if (!root) return;

  async function api(path) {
    const response = await fetch(DBSD_ADMIN_OPS.restUrl + path, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': DBSD_ADMIN_OPS.nonce }
    });
    const json = await response.json();
    if (!response.ok) throw new Error(json.message || 'SafeDate admin request failed.');
    return json;
  }

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>\"]/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;' }[ch];
    });
  }

  function rows(items, columns) {
    if (!items || !items.length) return '<p>No records.</p>';
    return '<table class="widefat striped"><thead><tr>' + columns.map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead><tbody>' + items.map(function (item) {
      return '<tr>' + columns.map(function (c) { return '<td>' + esc(item[c.key]) + '</td>'; }).join('') + '</tr>';
    }).join('') + '</tbody></table>';
  }

  async function refresh() {
    try {
      const data = await api('/admin/ops');
      root.innerHTML = '<div class="dbsd-grid">' +
        Object.keys(data.counts || {}).map(function (k) { return '<div class="dbsd-card"><strong>' + esc(k.replace(/_/g, ' ')) + '</strong><div style="font-size:28px">' + esc(data.counts[k]) + '</div></div>'; }).join('') +
        '</div>' +
        '<h2>Overdue / Pending Check-ins</h2>' + rows(data.checkins, [{key:'id',label:'ID'}, {key:'session_id',label:'Session'}, {key:'requested_for',label:'User'}, {key:'status',label:'Status'}, {key:'due_at',label:'Due'}]) +
        '<h2>Recent Device Health</h2>' + rows(data.device_health, [{key:'session_id',label:'Session'}, {key:'user_id',label:'User'}, {key:'online_status',label:'Online'}, {key:'battery_level',label:'Battery'}, {key:'permission_state',label:'Permission'}, {key:'recorded_at',label:'Recorded'}]) +
        '<h2>Open Privacy Requests</h2>' + rows(data.privacy_requests, [{key:'id',label:'ID'}, {key:'user_id',label:'User'}, {key:'request_type',label:'Type'}, {key:'status',label:'Status'}, {key:'created_at',label:'Created'}]) +
        '<p class="description">Generated at ' + esc(data.generated_at) + '</p>';
    } catch (err) {
      root.textContent = err.message;
    }
  }

  refresh();
  setInterval(refresh, 15000);
})();
