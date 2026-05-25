<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.3 production-hardening layer for DateBook SafeDate Tracker.
 * Adds trusted-contact sharing, incident reports, audit export, alert resolution,
 * contact listing, map-view shortcode, retention cleanup hooks, and admin review pages.
 */
class DBSD_V030 {
    const DB_VERSION = '0.3.0';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 20);
        add_shortcode('db_safedate_trusted_share', array(__CLASS__, 'trusted_share_shortcode'));
        add_shortcode('db_safedate_incident_report', array(__CLASS__, 'incident_report_shortcode'));
        add_shortcode('db_safedate_map', array(__CLASS__, 'map_shortcode'));
        add_action('dbsd_retention_cleanup', array(__CLASS__, 'retention_cleanup'));
        if (!wp_next_scheduled('dbsd_retention_cleanup')) {
            wp_schedule_event(time() + 3600, 'daily', 'dbsd_retention_cleanup');
        }
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }
    private static function json($request) { $params = $request->get_json_params(); return is_array($params) ? $params : array(); }
    private static function logged_in() { return is_user_logged_in(); }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v030_db_version', '0');
        if (version_compare($current, self::DB_VERSION, '>=')) return;
        self::install_tables();
        update_option('dbsd_v030_db_version', self::DB_VERSION);
        add_option('dbsd_trusted_share_default_hours', 24);
        add_option('dbsd_contact_alerts_enabled', 'no');
        add_option('dbsd_public_share_exact_location', 'no');
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $shares = self::table('trusted_shares');
        $incidents = self::table('incidents');
        $exports = self::table('exports');

        dbDelta("CREATE TABLE $shares (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NULL,
            contact_name VARCHAR(190) NULL,
            contact_email VARCHAR(190) NULL,
            token_hash CHAR(64) NOT NULL,
            access_scope VARCHAR(40) NOT NULL DEFAULT 'status_latest',
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            last_viewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY session_id (session_id),
            KEY owner_user_id (owner_user_id),
            KEY expires_at (expires_at)
        ) $charset;");

        dbDelta("CREATE TABLE $incidents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            reporter_user_id BIGINT UNSIGNED NOT NULL,
            incident_type VARCHAR(60) NOT NULL,
            severity VARCHAR(30) NOT NULL DEFAULT 'medium',
            narrative LONGTEXT NULL,
            admin_status VARCHAR(30) NOT NULL DEFAULT 'open',
            admin_notes LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY reporter_user_id (reporter_user_id),
            KEY admin_status (admin_status),
            KEY severity (severity)
        ) $charset;");

        dbDelta("CREATE TABLE $exports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            export_type VARCHAR(40) NOT NULL DEFAULT 'json',
            export_payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY requested_by (requested_by)
        ) $charset;");
    }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/contacts/list', array('methods' => 'GET', 'callback' => array(__CLASS__, 'contacts_list'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/share/create', array('methods' => 'POST', 'callback' => array(__CLASS__, 'create_share'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/share/revoke', array('methods' => 'POST', 'callback' => array(__CLASS__, 'revoke_share'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/share/(?P<token>[A-Za-z0-9_\-]{20,120})', array('methods' => 'GET', 'callback' => array(__CLASS__, 'public_share'), 'permission_callback' => '__return_true'));
        register_rest_route($ns, '/incident/report', array('methods' => 'POST', 'callback' => array(__CLASS__, 'report_incident'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/session/(?P<id>\d+)/incidents', array('methods' => 'GET', 'callback' => array(__CLASS__, 'session_incidents'), 'permission_callback' => array(__CLASS__, 'can_view_session')));
        register_rest_route($ns, '/session/(?P<id>\d+)/export', array('methods' => 'GET', 'callback' => array(__CLASS__, 'export_session'), 'permission_callback' => array(__CLASS__, 'can_view_session')));
        register_rest_route($ns, '/admin/alert/resolve', array('methods' => 'POST', 'callback' => array(__CLASS__, 'resolve_alert'), 'permission_callback' => function(){ return (current_user_can('dbsd_manage_incidents') || current_user_can('dbsd_manage_safety')); }));
        register_rest_route($ns, '/admin/incident/update', array('methods' => 'POST', 'callback' => array(__CLASS__, 'admin_update_incident'), 'permission_callback' => function(){ return (current_user_can('dbsd_manage_incidents') || current_user_can('dbsd_manage_safety')); }));
    }

    private static function get_session($session_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('sessions') . " WHERE id=%d", absint($session_id)));
    }

    public static function can_view_session($request) {
        if (!is_user_logged_in()) return false;
        $s = self::get_session(absint($request['id']));
        if (!$s) return false;
        $uid = get_current_user_id();
        return current_user_can('dbsd_manage_safety') || (int)$s->host_user_id === $uid || (int)$s->traveler_user_id === $uid;
    }

    private static function require_session_participant($session_id) {
        $s = self::get_session($session_id);
        if (!$s) return new WP_Error('dbsd_missing_session', __('SafeDate session not found.', 'datebook-safedate'), array('status' => 404));
        $uid = get_current_user_id();
        if (!current_user_can('dbsd_manage_safety') && (int)$s->host_user_id !== $uid && (int)$s->traveler_user_id !== $uid) {
            return new WP_Error('dbsd_forbidden', __('You cannot access this SafeDate session.', 'datebook-safedate'), array('status' => 403));
        }
        return $s;
    }

    public static function contacts_list() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, contact_name, contact_email, contact_phone, can_receive_alerts, created_at FROM " . self::table('emergency_contacts') . " WHERE user_id=%d ORDER BY id DESC", get_current_user_id()));
        return array('ok' => true, 'contacts' => $rows);
    }

    public static function create_share($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_session_participant($session_id);
        if (is_wp_error($session)) return $session;
        $scope = sanitize_key($p['access_scope'] ?? 'status_latest');
        if (!in_array($scope, array('status_only', 'status_latest', 'movement_summary'), true)) $scope = 'status_latest';
        $hours = min(168, max(1, absint($p['expires_in_hours'] ?? get_option('dbsd_trusted_share_default_hours', 24))));
        $token = wp_generate_password(48, false, false);
        $token_hash = hash('sha256', $token);
        $contact_id = absint($p['contact_id'] ?? 0);
        $contact = null;
        if ($contact_id) {
            $contact = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('emergency_contacts') . " WHERE id=%d AND user_id=%d", $contact_id, get_current_user_id()));
        }
        $name = $contact ? $contact->contact_name : sanitize_text_field($p['contact_name'] ?? 'Trusted contact');
        $email = $contact ? $contact->contact_email : sanitize_email($p['contact_email'] ?? '');
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + ($hours * HOUR_IN_SECONDS));
        $wpdb->insert(self::table('trusted_shares'), array(
            'session_id' => $session_id,
            'owner_user_id' => get_current_user_id(),
            'contact_id' => $contact_id ?: null,
            'contact_name' => $name,
            'contact_email' => $email,
            'token_hash' => $token_hash,
            'access_scope' => $scope,
            'expires_at' => $expires,
            'created_at' => $now,
        ));
        $url = rest_url('datebook-safedate/v1/share/' . rawurlencode($token));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'trusted_share_created', array('share_id' => $wpdb->insert_id, 'scope' => $scope, 'expires_at' => $expires));
        if ($email && get_option('dbsd_contact_alerts_enabled', 'no') === 'yes') {
            wp_mail($email, '[SafeDate] Trusted contact access', "A SafeDate participant shared temporary safety access with you.\n\nAccess link:\n" . $url . "\n\nExpires UTC: " . $expires);
        }
        return array('ok' => true, 'share_id' => $wpdb->insert_id, 'share_url' => $url, 'expires_at' => $expires);
    }

    public static function revoke_share($request) {
        global $wpdb;
        $p = self::json($request);
        $id = absint($p['share_id'] ?? 0);
        $wpdb->update(self::table('trusted_shares'), array('revoked_at' => current_time('mysql', true)), array('id' => $id, 'owner_user_id' => get_current_user_id()));
        return array('ok' => true);
    }

    public static function public_share($request) {
        global $wpdb;
        if (class_exists('DBSD_V074') && !DBSD_V074::public_ip_rate_limit('/share/public')) {
            return new WP_Error('dbsd_rate_limited', __('Too many trusted-share views from this network.', 'datebook-safedate'), array('status' => 429));
        }
        $token = sanitize_text_field($request['token']);
        $hash = hash('sha256', $token);
        $share = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('trusted_shares') . " WHERE token_hash=%s", $hash));
        if (!$share || $share->revoked_at || strtotime($share->expires_at . ' UTC') < time()) {
            return new WP_Error('dbsd_share_expired', __('This trusted-contact access link is expired or revoked.', 'datebook-safedate'), array('status' => 403));
        }
        $session = self::get_session((int)$share->session_id);
        if (!$session) return new WP_Error('dbsd_missing_session', __('SafeDate session not found.', 'datebook-safedate'), array('status' => 404));
        $wpdb->update(self::table('trusted_shares'), array('last_viewed_at' => current_time('mysql', true)), array('id' => (int)$share->id));
        $payload = array(
            'ok' => true,
            'scope' => $share->access_scope,
            'session' => array(
                'id' => (int)$session->id,
                'status' => $session->status,
                'alert_level' => $session->alert_level,
                'planned_start_at' => $session->planned_start_at,
                'expected_arrival_at' => $session->expected_arrival_at,
                'expected_departure_at' => $session->expected_departure_at,
                'last_location_at' => $session->last_location_at,
            ),
        );
        if ($share->access_scope !== 'status_only') {
            $exact = get_option('dbsd_public_share_exact_location', 'no') === 'yes';
            $select = $exact ? 'lat, lng' : 'ROUND(lat, 2) AS lat, ROUND(lng, 2) AS lng';
            $accuracy_select = $exact ? 'accuracy' : 'CASE WHEN accuracy IS NULL THEN NULL WHEN accuracy <= 100 THEN 100 WHEN accuracy <= 1000 THEN 1000 ELSE 10000 END';
            $latest = $wpdb->get_row($wpdb->prepare("SELECT id, $select, $accuracy_select AS accuracy, recorded_at FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at DESC LIMIT 1", (int)$session->id));
            $payload['latest_location'] = $latest;
        }
        if ($share->access_scope === 'movement_summary') {
            $payload['movement_summary'] = $wpdb->get_results($wpdb->prepare("SELECT recorded_at, ROUND(lat, 2) AS lat, ROUND(lng, 2) AS lng, CASE WHEN accuracy IS NULL THEN NULL WHEN accuracy <= 100 THEN 100 WHEN accuracy <= 1000 THEN 1000 ELSE 10000 END AS accuracy FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at DESC LIMIT 25", (int)$session->id));
        }
        DBSD_Audit::log_event((int)$session->id, 0, 'trusted_share_viewed', array('share_id' => (int)$share->id));
        return $payload;
    }

    public static function report_incident($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_session_participant($session_id);
        if (is_wp_error($session)) return $session;
        $type = sanitize_key($p['incident_type'] ?? 'general');
        $severity = sanitize_key($p['severity'] ?? 'medium');
        if (!in_array($severity, array('low','medium','high','critical'), true)) $severity = 'medium';
        $narrative = sanitize_textarea_field($p['narrative'] ?? '');
        if (!$narrative) return new WP_Error('dbsd_incident_missing', __('Please enter incident details.', 'datebook-safedate'), array('status' => 400));
        $now = current_time('mysql', true);
        $wpdb->insert(self::table('incidents'), array(
            'session_id' => $session_id,
            'reporter_user_id' => get_current_user_id(),
            'incident_type' => $type,
            'severity' => $severity,
            'narrative' => $narrative,
            'admin_status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $incident_id = $wpdb->insert_id;
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'incident_reported', array('incident_id' => $incident_id, 'type' => $type, 'severity' => $severity));
        if (class_exists('DBSD_Monitor') && in_array($severity, array('high','critical'), true)) {
            DBSD_Monitor::raise_alert($session_id, get_current_user_id(), 'incident_' . $severity, array('incident_id' => $incident_id, 'type' => $type));
        }
        return array('ok' => true, 'incident_id' => $incident_id);
    }

    public static function session_incidents($request) {
        global $wpdb;
        $session_id = absint($request['id']);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, reporter_user_id, incident_type, severity, narrative, admin_status, created_at, updated_at FROM " . self::table('incidents') . " WHERE session_id=%d ORDER BY id DESC", $session_id));
        return array('ok' => true, 'incidents' => $rows);
    }

    public static function export_session($request) {
        global $wpdb;
        $session_id = absint($request['id']);
        $session = self::require_session_participant($session_id);
        if (is_wp_error($session)) return $session;
        $location_mode = class_exists('DBSD_State') ? DBSD_State::viewer_location_mode($session) : 'approximate';
        $events = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('events') . " WHERE session_id=%d ORDER BY id ASC", $session_id));
        foreach ($events as $event) {
            if ($location_mode !== 'exact') {
                $payload = json_decode((string)$event->event_payload, true);
                if (json_last_error() === JSON_ERROR_NONE && class_exists('DBSD_State')) $event->event_payload = wp_json_encode(DBSD_State::redact_location_payload($payload, 'approximate'));
                unset($event->ip_address, $event->user_agent);
            }
        }
        if ($location_mode === 'exact') {
            $locations = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at ASC", $session_id));
        } else {
            $locations = $wpdb->get_results($wpdb->prepare("SELECT id, session_id, user_id, ROUND(lat,2) AS lat, ROUND(lng,2) AS lng, CASE WHEN accuracy IS NULL THEN NULL WHEN accuracy <= 100 THEN 100 WHEN accuracy <= 1000 THEN 1000 ELSE 10000 END AS accuracy, recorded_at, created_at FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at ASC", $session_id));
        }
        $data = array(
            'generated_at_utc' => current_time('mysql', true),
            'redaction_mode' => $location_mode,
            'session' => $session,
            'events' => $events,
            'locations' => $locations,
            'incidents' => $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('incidents') . " WHERE session_id=%d ORDER BY id ASC", $session_id)),
            'notifications' => $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('notifications') . " WHERE session_id=%d ORDER BY id ASC", $session_id)),
        );
        $wpdb->insert(self::table('exports'), array('session_id' => $session_id, 'requested_by' => get_current_user_id(), 'export_type' => 'json', 'export_payload' => wp_json_encode($data), 'created_at' => current_time('mysql', true)));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'session_exported', array('export_id' => $wpdb->insert_id));
        return array('ok' => true, 'export_id' => $wpdb->insert_id, 'data' => $data);
    }

    public static function resolve_alert($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $note = sanitize_textarea_field($p['note'] ?? '');
        $wpdb->update(self::table('sessions'), array('alert_level' => 'normal', 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'alert_resolved_by_admin', array('note' => $note));
        return array('ok' => true);
    }

    public static function admin_update_incident($request) {
        global $wpdb;
        $p = self::json($request);
        $incident_id = absint($p['incident_id'] ?? 0);
        $status = sanitize_key($p['admin_status'] ?? 'open');
        if (!in_array($status, array('open','reviewing','resolved','dismissed'), true)) $status = 'open';
        $note = sanitize_textarea_field($p['admin_notes'] ?? '');
        $incident = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('incidents') . " WHERE id=%d", $incident_id));
        if (!$incident) return new WP_Error('dbsd_missing_incident', __('Incident not found.', 'datebook-safedate'), array('status' => 404));
        $wpdb->update(self::table('incidents'), array('admin_status' => $status, 'admin_notes' => $note, 'updated_at' => current_time('mysql', true)), array('id' => $incident_id));
        DBSD_Audit::log_event((int)$incident->session_id, get_current_user_id(), 'incident_admin_updated', array('incident_id' => $incident_id, 'status' => $status));
        return array('ok' => true);
    }

    public static function retention_cleanup() {
        global $wpdb;
        $days = max(30, absint(get_option('dbsd_data_retention_days', 180)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        foreach (array('trusted_shares', 'exports') as $table) {
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::table($table) . " WHERE created_at < %s", $cutoff));
        }
    }

    private static function enqueue() { wp_enqueue_style('dbsd-frontend'); wp_enqueue_script('dbsd-frontend'); }

    public static function trusted_share_shortcode($atts) {
        if (!is_user_logged_in()) return '<p>Please log in to create a trusted-contact share.</p>';
        $atts = shortcode_atts(array('id' => 0), $atts);
        $session_id = absint($atts['id']);
        self::enqueue();
        ob_start(); ?>
        <div class="dbsd-card" data-dbsd-share-form data-session-id="<?php echo esc_attr($session_id); ?>">
            <h3>Trusted Contact Share</h3>
            <p class="dbsd-muted">Create a temporary safety link for a trusted contact. Exact location is not shared unless the admin enables it.</p>
            <?php if (!$session_id): ?><label>SafeDate session ID <input type="number" data-field="session_id"></label><?php else: ?><input type="hidden" data-field="session_id" value="<?php echo esc_attr($session_id); ?>"><?php endif; ?>
            <label>Contact name <input type="text" data-field="contact_name"></label>
            <label>Contact email <input type="email" data-field="contact_email"></label>
            <label>Access scope <select data-field="access_scope"><option value="status_latest">Status + latest approximate location</option><option value="status_only">Status only</option><option value="movement_summary">Movement summary, approximate</option></select></label>
            <label>Expires in hours <input type="number" min="1" max="168" value="24" data-field="expires_in_hours"></label>
            <button type="button" class="dbsd-btn" data-action="create-share">Create Share Link</button>
            <div class="dbsd-result"></div>
        </div>
        <?php return ob_get_clean();
    }

    public static function incident_report_shortcode($atts) {
        if (!is_user_logged_in()) return '<p>Please log in to report an incident.</p>';
        $atts = shortcode_atts(array('id' => 0), $atts);
        $session_id = absint($atts['id']);
        self::enqueue();
        ob_start(); ?>
        <div class="dbsd-card" data-dbsd-incident-form data-session-id="<?php echo esc_attr($session_id); ?>">
            <h3>Report SafeDate Incident</h3>
            <p class="dbsd-muted">This report is added to the secure audit trail and visible to platform admins.</p>
            <?php if (!$session_id): ?><label>SafeDate session ID <input type="number" data-field="session_id"></label><?php else: ?><input type="hidden" data-field="session_id" value="<?php echo esc_attr($session_id); ?>"><?php endif; ?>
            <label>Incident type <select data-field="incident_type"><option value="general">General concern</option><option value="no_show">No show</option><option value="unsafe_behavior">Unsafe behavior</option><option value="location_issue">Location/tracking issue</option><option value="harassment">Harassment</option></select></label>
            <label>Severity <select data-field="severity"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></label>
            <label>Details <textarea rows="5" data-field="narrative"></textarea></label>
            <button type="button" class="dbsd-btn dbsd-danger" data-action="report-incident">Submit Incident Report</button>
            <div class="dbsd-result"></div>
        </div>
        <?php return ob_get_clean();
    }

    public static function map_shortcode($atts) {
        if (!is_user_logged_in()) return '<p>Please log in to view the SafeDate map.</p>';
        $atts = shortcode_atts(array('id' => 0), $atts);
        $session_id = absint($atts['id']);
        if (!$session_id) return '<p>Missing SafeDate session ID.</p>';
        self::enqueue();
        return '<div class="dbsd-card" data-dbsd-session="' . esc_attr($session_id) . '"><h3>SafeDate Movement Map</h3><div class="dbsd-map dbsd-map-large" data-dbsd-map>Loading movement log...</div></div>';
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'SafeDate Incidents', 'Incidents', 'dbsd_manage_incidents', 'dbsd-incidents', array(__CLASS__, 'admin_incidents_page'));
        add_submenu_page('dbsd', 'SafeDate Tools', 'Tools', 'dbsd_export_safety', 'dbsd-tools', array(__CLASS__, 'admin_tools_page'));
    }

    public static function admin_incidents_page() {
        if (!current_user_can('dbsd_manage_safety')) return;
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table('incidents') . " ORDER BY id DESC LIMIT 100");
        echo '<div class="wrap"><h1>SafeDate Incidents</h1><table class="widefat striped"><thead><tr><th>ID</th><th>Session</th><th>Reporter</th><th>Type</th><th>Severity</th><th>Status</th><th>Created</th><th>Details</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="8">No incident reports.</td></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r->id) . '</td><td>' . esc_html($r->session_id) . '</td><td>' . esc_html($r->reporter_user_id) . '</td><td>' . esc_html($r->incident_type) . '</td><td><strong>' . esc_html($r->severity) . '</strong></td><td>' . esc_html($r->admin_status) . '</td><td>' . esc_html($r->created_at) . '</td><td>' . esc_html(wp_trim_words($r->narrative, 24)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function admin_tools_page() {
        if (!current_user_can('dbsd_manage_safety')) return;
        global $wpdb;
        $exports = $wpdb->get_results("SELECT id, session_id, requested_by, export_type, created_at FROM " . self::table('exports') . " ORDER BY id DESC LIMIT 50");
        echo '<div class="wrap"><h1>SafeDate Tools</h1><p>v0.3 adds trusted-contact shares, incident reports, JSON audit exports, and retention cleanup.</p>';
        echo '<h2>Recent Exports</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Session</th><th>Requested by</th><th>Type</th><th>Created</th></tr></thead><tbody>';
        if (!$exports) echo '<tr><td colspan="5">No exports yet.</td></tr>';
        foreach ($exports as $e) echo '<tr><td>' . esc_html($e->id) . '</td><td>' . esc_html($e->session_id) . '</td><td>' . esc_html($e->requested_by) . '</td><td>' . esc_html($e->export_type) . '</td><td>' . esc_html($e->created_at) . '</td></tr>';
        echo '</tbody></table></div>';
    }
}
