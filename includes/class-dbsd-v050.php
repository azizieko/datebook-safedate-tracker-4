<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.5 safety-automation layer.
 * Adds enhanced location pings with geofence awareness, check-in requests, escalation actions,
 * privacy/export helpers, device health telemetry, and a stronger admin operations monitor.
 */
class DBSD_V050 {
    const DB_VERSION = '0.5.0';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 40);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin'));
        add_action('admin_init', array(__CLASS__, 'settings'));
        add_shortcode('db_safedate_safety_center', array(__CLASS__, 'safety_center_shortcode'));
        add_shortcode('db_safedate_privacy_tools', array(__CLASS__, 'privacy_tools_shortcode'));
        add_action('dbsd_v050_monitor', array(__CLASS__, 'monitor'));
        if (!wp_next_scheduled('dbsd_v050_monitor')) {
            wp_schedule_event(time() + 180, 'five_minutes', 'dbsd_v050_monitor');
        }
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }
    private static function json($request) { $params = $request->get_json_params(); return is_array($params) ? $params : array(); }
    public static function logged_in() { return is_user_logged_in(); }
    public static function admin_only() { return current_user_can('dbsd_manage_safety'); }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v050_db_version', '0');
        if (version_compare($current, self::DB_VERSION, '>=')) return;
        self::install_tables();
        update_option('dbsd_v050_db_version', self::DB_VERSION);
        add_option('dbsd_geofence_enabled', 'yes');
        add_option('dbsd_arrival_geofence_radius_meters', 180);
        add_option('dbsd_auto_prompt_arrival_in_geofence', 'yes');
        add_option('dbsd_checkin_overdue_minutes', 10);
        add_option('dbsd_post_departure_check_minutes', 20);
        add_option('dbsd_device_stale_minutes', 25);
        add_option('dbsd_privacy_export_retention_days', 30);
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::table('checkins') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            requested_by BIGINT UNSIGNED NULL,
            requested_for BIGINT UNSIGNED NOT NULL,
            checkin_type VARCHAR(60) NOT NULL DEFAULT 'safety_check',
            prompt TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            due_at DATETIME NOT NULL,
            responded_at DATETIME NULL,
            response_payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY requested_for (requested_for),
            KEY status_due (status, due_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('admin_actions') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NULL,
            incident_id BIGINT UNSIGNED NULL,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            action_type VARCHAR(80) NOT NULL,
            action_note LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY incident_id (incident_id),
            KEY action_type (action_type)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('privacy_requests') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            request_type VARCHAR(40) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            request_payload LONGTEXT NULL,
            admin_notes LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY request_type (request_type),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('device_health') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            online_status VARCHAR(30) NULL,
            battery_level FLOAT NULL,
            charging TINYINT(1) NULL,
            permission_state VARCHAR(30) NULL,
            network_type VARCHAR(80) NULL,
            user_agent TEXT NULL,
            recorded_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_user_time (session_id, user_id, recorded_at)
        ) $charset;");
    }

    public static function settings() {
        register_setting('dbsd_settings', 'dbsd_geofence_enabled', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'yes'));
        register_setting('dbsd_settings', 'dbsd_arrival_geofence_radius_meters', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 180));
        register_setting('dbsd_settings', 'dbsd_auto_prompt_arrival_in_geofence', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'yes'));
        register_setting('dbsd_settings', 'dbsd_checkin_overdue_minutes', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 10));
        register_setting('dbsd_settings', 'dbsd_post_departure_check_minutes', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 20));
        register_setting('dbsd_settings', 'dbsd_device_stale_minutes', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 25));
        register_setting('dbsd_settings', 'dbsd_privacy_export_retention_days', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30));
    }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/location/ping-enhanced', array('methods' => 'POST', 'callback' => array(__CLASS__, 'location_ping_enhanced'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/device/health', array('methods' => 'POST', 'callback' => array(__CLASS__, 'device_health'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/checkin/request', array('methods' => 'POST', 'callback' => array(__CLASS__, 'request_checkin'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/checkin/respond', array('methods' => 'POST', 'callback' => array(__CLASS__, 'respond_checkin'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/session/(?P<id>\d+)/safety-status', array('methods' => 'GET', 'callback' => array(__CLASS__, 'safety_status'), 'permission_callback' => array(__CLASS__, 'can_view_session')));
        register_rest_route($ns, '/privacy/request', array('methods' => 'POST', 'callback' => array(__CLASS__, 'privacy_request'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/privacy/my-data', array('methods' => 'GET', 'callback' => array(__CLASS__, 'privacy_my_data'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/admin/ops', array('methods' => 'GET', 'callback' => array(__CLASS__, 'admin_ops'), 'permission_callback' => array(__CLASS__, 'admin_only')));
        register_rest_route($ns, '/admin/action', array('methods' => 'POST', 'callback' => array(__CLASS__, 'admin_action'), 'permission_callback' => array(__CLASS__, 'admin_only')));
    }

    private static function get_session($session_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('sessions') . " WHERE id=%d", absint($session_id)));
    }

    private static function is_participant($session) {
        $uid = get_current_user_id();
        return $session && (current_user_can('dbsd_manage_safety') || (int)$session->host_user_id === $uid || (int)$session->traveler_user_id === $uid);
    }

    public static function can_view_session($request) {
        return is_user_logged_in() && self::is_participant(self::get_session(absint($request['id'])));
    }

    private static function require_participant($session_id) {
        $session = self::get_session($session_id);
        if (!self::is_participant($session)) return new WP_Error('dbsd_forbidden', __('You cannot access this SafeDate session.', 'datebook-safedate'), array('status' => 403));
        return $session;
    }

    private static function mysql_datetime($value) {
        $value = sanitize_text_field((string)$value);
        if (!$value) return current_time('mysql', true);
        return str_replace('T', ' ', substr($value, 0, 19));
    }

    private static function distance_meters($lat1, $lng1, $lat2, $lng2) {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function create_notification($session_id, $recipient_user_id, $type, $message) {
        global $wpdb;
        $wpdb->insert(self::table('notifications'), array(
            'session_id' => absint($session_id),
            'recipient_user_id' => absint($recipient_user_id),
            'notification_type' => sanitize_key($type),
            'status' => 'pending',
            'message' => sanitize_textarea_field($message),
            'created_at' => current_time('mysql', true),
            'sent_at' => current_time('mysql', true),
        ));
        if (class_exists('DBSD_V040')) {
            DBSD_V040::send_push_to_user(absint($recipient_user_id), array(
                'title' => 'SafeDate: ' . sanitize_key($type),
                'body' => $message,
                'url' => home_url('/'),
                'tag' => 'dbsd-session-' . absint($session_id),
                'session_id' => absint($session_id),
            ));
            DBSD_V040::live_event(absint($session_id), 0, $type, in_array($type, array('checkin_overdue','geofence_arrival_prompt','privacy_request'), true) ? 'warning' : 'info', $message);
        }
    }

    public static function location_ping_enhanced($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id);
        if (is_wp_error($session)) return $session;
        if ((int)$session->traveler_user_id !== get_current_user_id()) return new WP_Error('dbsd_only_traveler', __('Only the traveler location can be recorded.', 'datebook-safedate'), array('status' => 403));
        if (class_exists('DBSD_State')) { $guard = DBSD_State::assert_transition($session, DBSD_State::role($session), 'location_ping'); if (is_wp_error($guard)) return $guard; }

        $lat = isset($p['lat']) ? floatval($p['lat']) : null;
        $lng = isset($p['lng']) ? floatval($p['lng']) : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return new WP_Error('dbsd_bad_location', __('Invalid location.', 'datebook-safedate'), array('status' => 400));
        }
        $recorded_at = self::mysql_datetime($p['recorded_at'] ?? current_time('mysql', true));
        $wpdb->insert(self::table('locations'), array(
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => isset($p['accuracy']) ? floatval($p['accuracy']) : null,
            'speed' => isset($p['speed']) ? floatval($p['speed']) : null,
            'heading' => isset($p['heading']) ? floatval($p['heading']) : null,
            'battery_level' => isset($p['battery_level']) ? floatval($p['battery_level']) : null,
            'recorded_at' => $recorded_at,
            'created_at' => current_time('mysql', true),
        ));
        $wpdb->update(self::table('sessions'), array('last_location_at' => $recorded_at, 'updated_at' => current_time('mysql', true)), array('id' => $session_id));

        $distance = null;
        $inside = false;
        if (get_option('dbsd_geofence_enabled', 'yes') === 'yes' && $session->meeting_lat !== null && $session->meeting_lng !== null) {
            $distance = self::distance_meters((float)$lat, (float)$lng, (float)$session->meeting_lat, (float)$session->meeting_lng);
            $inside = $distance <= max(25, absint(get_option('dbsd_arrival_geofence_radius_meters', 180)));
            if ($inside && get_option('dbsd_auto_prompt_arrival_in_geofence', 'yes') === 'yes' && in_array($session->status, array('journey_started','ready'), true)) {
                $recent = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('events') . " WHERE session_id=%d AND event_type='geofence_arrival_detected' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)", $session_id));
                if (!$recent) {
                    DBSD_Audit::log_event($session_id, get_current_user_id(), 'geofence_arrival_detected', array('distance_meters' => round($distance), 'radius_meters' => absint(get_option('dbsd_arrival_geofence_radius_meters', 180))));
                    self::create_notification($session_id, (int)$session->traveler_user_id, 'geofence_arrival_prompt', 'You appear to be near the meeting location. Tap “I have arrived” only when you are safely there.');
                }
            }
        }

        DBSD_Audit::log_event($session_id, get_current_user_id(), 'location_ping', array('accuracy' => $p['accuracy'] ?? null, 'distance_to_meeting_meters' => $distance === null ? null : round($distance), 'location_recorded' => true));
        return array('ok' => true, 'geofence' => array('distance_meters' => $distance === null ? null : round($distance), 'inside_arrival_radius' => $inside));
    }

    public static function device_health($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id);
        if (is_wp_error($session)) return $session;
        $wpdb->insert(self::table('device_health'), array(
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'online_status' => sanitize_key($p['online_status'] ?? ''),
            'battery_level' => isset($p['battery_level']) ? floatval($p['battery_level']) : null,
            'charging' => isset($p['charging']) ? (int)!!$p['charging'] : null,
            'permission_state' => sanitize_key($p['permission_state'] ?? ''),
            'network_type' => sanitize_text_field($p['network_type'] ?? ''),
            'user_agent' => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'recorded_at' => current_time('mysql', true),
        ));
        return array('ok' => true);
    }

    public static function request_checkin($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id);
        if (is_wp_error($session)) return $session;
        $target = absint($p['requested_for'] ?? 0);
        if (!in_array($target, array((int)$session->host_user_id, (int)$session->traveler_user_id), true)) {
            return new WP_Error('dbsd_bad_target', __('Check-in target must be a session participant.', 'datebook-safedate'), array('status' => 400));
        }
        $minutes = min(120, max(2, absint($p['due_in_minutes'] ?? get_option('dbsd_checkin_overdue_minutes', 10))));
        $now = current_time('mysql', true);
        $due = gmdate('Y-m-d H:i:s', time() + ($minutes * MINUTE_IN_SECONDS));
        $prompt = sanitize_textarea_field($p['prompt'] ?? 'Please confirm you are safe.');
        $wpdb->insert(self::table('checkins'), array(
            'session_id' => $session_id,
            'requested_by' => get_current_user_id(),
            'requested_for' => $target,
            'checkin_type' => sanitize_key($p['checkin_type'] ?? 'safety_check'),
            'prompt' => $prompt,
            'status' => 'pending',
            'due_at' => $due,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'checkin_requested', array('checkin_id' => $wpdb->insert_id, 'requested_for' => $target, 'due_at' => $due));
        self::create_notification($session_id, $target, 'checkin_requested', $prompt);
        return array('ok' => true, 'checkin_id' => $wpdb->insert_id, 'due_at' => $due);
    }

    public static function respond_checkin($request) {
        global $wpdb;
        $p = self::json($request);
        $id = absint($p['checkin_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('checkins') . " WHERE id=%d", $id));
        if (!$row || (int)$row->requested_for !== get_current_user_id()) return new WP_Error('dbsd_bad_checkin', __('Check-in not found.', 'datebook-safedate'), array('status' => 404));
        $status = !empty($p['safe']) ? 'safe' : 'needs_help';
        $payload = array('safe' => !empty($p['safe']), 'message' => sanitize_textarea_field($p['message'] ?? ''), 'lat' => isset($p['lat']) ? floatval($p['lat']) : null, 'lng' => isset($p['lng']) ? floatval($p['lng']) : null);
        $wpdb->update(self::table('checkins'), array('status' => $status, 'responded_at' => current_time('mysql', true), 'response_payload' => wp_json_encode($payload), 'updated_at' => current_time('mysql', true)), array('id' => $id));
        DBSD_Audit::log_event((int)$row->session_id, get_current_user_id(), 'checkin_' . $status, array('checkin_id' => $id, 'message' => $payload['message']));
        if ($status === 'needs_help' && class_exists('DBSD_Monitor')) DBSD_Monitor::raise_alert((int)$row->session_id, get_current_user_id(), 'checkin_needs_help', $payload);
        return array('ok' => true, 'status' => $status);
    }

    public static function safety_status($request) {
        global $wpdb;
        $session_id = absint($request['id']);
        $session = self::get_session($session_id);
        $pending = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('checkins') . " WHERE session_id=%d ORDER BY id DESC LIMIT 20", $session_id));
        $latest_health = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('device_health') . " WHERE session_id=%d ORDER BY recorded_at DESC LIMIT 10", $session_id));
        $latest_location = $wpdb->get_row($wpdb->prepare("SELECT id, lat, lng, accuracy, recorded_at FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at DESC LIMIT 1", $session_id));
        return array('ok' => true, 'session' => $session, 'checkins' => $pending, 'device_health' => $latest_health, 'latest_location' => $latest_location);
    }

    public static function privacy_request($request) {
        global $wpdb;
        $p = self::json($request);
        $type = sanitize_key($p['request_type'] ?? 'export');
        if (!in_array($type, array('export','delete_review','correction'), true)) $type = 'export';
        $wpdb->insert(self::table('privacy_requests'), array(
            'user_id' => get_current_user_id(),
            'request_type' => $type,
            'status' => 'open',
            'request_payload' => wp_json_encode(array('message' => sanitize_textarea_field($p['message'] ?? ''))),
            'created_at' => current_time('mysql', true),
        ));
        DBSD_Audit::log_event(0, get_current_user_id(), 'privacy_request_created', array('request_id' => $wpdb->insert_id, 'type' => $type));
        if (class_exists('DBSD_V040')) DBSD_V040::live_event(null, get_current_user_id(), 'privacy_request', 'info', 'A user submitted a SafeDate privacy request.');
        return array('ok' => true, 'request_id' => $wpdb->insert_id);
    }

    public static function privacy_my_data() {
        global $wpdb;
        $uid = get_current_user_id();
        $sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('sessions') . " WHERE host_user_id=%d OR traveler_user_id=%d ORDER BY id DESC LIMIT 100", $uid, $uid));
        $requests = $wpdb->get_results($wpdb->prepare("SELECT id, request_type, status, created_at, completed_at FROM " . self::table('privacy_requests') . " WHERE user_id=%d ORDER BY id DESC LIMIT 50", $uid));
        return array('ok' => true, 'user_id' => $uid, 'sessions' => $sessions, 'privacy_requests' => $requests);
    }

    public static function monitor() {
        global $wpdb;
        $now = current_time('mysql', true);
        $overdue = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('checkins') . " WHERE status='pending' AND due_at < %s LIMIT 50", $now));
        foreach ($overdue as $c) {
            $wpdb->update(self::table('checkins'), array('status' => 'overdue', 'updated_at' => $now), array('id' => (int)$c->id));
            DBSD_Audit::log_event((int)$c->session_id, 0, 'checkin_overdue', array('checkin_id' => (int)$c->id, 'requested_for' => (int)$c->requested_for));
            if (class_exists('DBSD_Monitor')) DBSD_Monitor::raise_alert((int)$c->session_id, (int)$c->requested_for, 'checkin_overdue', array('checkin_id' => (int)$c->id));
            self::create_notification((int)$c->session_id, (int)$c->requested_for, 'checkin_overdue', 'Your SafeDate check-in is overdue. Please confirm you are safe.');
        }

        $stale_minutes = max(5, absint(get_option('dbsd_device_stale_minutes', 25)));
        $stale = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('sessions') . " WHERE status IN ('journey_started','arrival_claimed','arrival_confirmed','departure_claimed') AND (last_location_at IS NULL OR last_location_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)) LIMIT 50", $stale_minutes));
        foreach ($stale as $s) {
            $recent = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('events') . " WHERE session_id=%d AND event_type='device_stale_alert' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 MINUTE)", (int)$s->id));
            if (!$recent) {
                DBSD_Audit::log_event((int)$s->id, 0, 'device_stale_alert', array('stale_minutes' => $stale_minutes));
                if (class_exists('DBSD_Monitor')) DBSD_Monitor::raise_alert((int)$s->id, (int)$s->traveler_user_id, 'device_stale', array('stale_minutes' => $stale_minutes));
                self::create_notification((int)$s->id, (int)$s->traveler_user_id, 'device_stale', 'SafeDate has not received your location recently. Please check in.');
            }
        }

        $post_minutes = max(5, absint(get_option('dbsd_post_departure_check_minutes', 20)));
        $completed = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('sessions') . " WHERE status='completed' AND updated_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) LIMIT 50"));
        foreach ($completed as $s) {
            $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('checkins') . " WHERE session_id=%d AND checkin_type='post_departure'", (int)$s->id));
            if (!$exists) {
                $due = gmdate('Y-m-d H:i:s', strtotime($s->updated_at . ' UTC') + ($post_minutes * MINUTE_IN_SECONDS));
                $wpdb->insert(self::table('checkins'), array('session_id' => (int)$s->id, 'requested_by' => 0, 'requested_for' => (int)$s->traveler_user_id, 'checkin_type' => 'post_departure', 'prompt' => 'Post-date safety check: please confirm you are safe after leaving.', 'status' => 'pending', 'due_at' => $due, 'created_at' => $now, 'updated_at' => $now));
                self::create_notification((int)$s->id, (int)$s->traveler_user_id, 'post_departure_check', 'Post-date safety check: please confirm you are safe after leaving.');
            }
        }
    }

    public static function admin_ops() {
        global $wpdb;
        $counts = array(
            'critical_alerts' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE alert_level='critical'"),
            'warning_alerts' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('sessions') . " WHERE alert_level IN ('warning','high')"),
            'overdue_checkins' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('checkins') . " WHERE status='overdue'"),
            'open_privacy_requests' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('privacy_requests') . " WHERE status='open'"),
        );
        $checkins = $wpdb->get_results("SELECT * FROM " . self::table('checkins') . " WHERE status IN ('pending','overdue') ORDER BY due_at ASC LIMIT 30");
        $health = $wpdb->get_results("SELECT * FROM " . self::table('device_health') . " ORDER BY recorded_at DESC LIMIT 30");
        $privacy = $wpdb->get_results("SELECT * FROM " . self::table('privacy_requests') . " WHERE status='open' ORDER BY id DESC LIMIT 20");
        return array('ok' => true, 'generated_at' => current_time('mysql', true), 'counts' => $counts, 'checkins' => $checkins, 'device_health' => $health, 'privacy_requests' => $privacy);
    }

    public static function admin_action($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $incident_id = absint($p['incident_id'] ?? 0);
        $type = sanitize_key($p['action_type'] ?? 'note');
        $note = sanitize_textarea_field($p['action_note'] ?? '');
        $wpdb->insert(self::table('admin_actions'), array('session_id' => $session_id ?: null, 'incident_id' => $incident_id ?: null, 'admin_user_id' => get_current_user_id(), 'action_type' => $type, 'action_note' => $note, 'created_at' => current_time('mysql', true)));
        if ($session_id) DBSD_Audit::log_event($session_id, get_current_user_id(), 'admin_action_' . $type, array('note' => $note));
        if ($type === 'resolve_session_alert' && $session_id) {
            $wpdb->update(self::table('sessions'), array('alert_level' => 'normal', 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        }
        return array('ok' => true, 'action_id' => $wpdb->insert_id);
    }

    public static function enqueue_frontend() {
        wp_enqueue_script('dbsd-v050', DBSD_PLUGIN_URL . 'assets/js/dbsd-v050.js', array(), DBSD_VERSION, true);
        wp_localize_script('dbsd-v050', 'DBSD_V05', array('restUrl' => esc_url_raw(rest_url('datebook-safedate/v1')), 'nonce' => wp_create_nonce('wp_rest')));
    }

    public static function enqueue_admin($hook) {
        if (strpos($hook, 'dbsd-ops') === false) return;
        wp_enqueue_style('dbsd-frontend');
        wp_enqueue_script('dbsd-admin-ops', DBSD_PLUGIN_URL . 'assets/js/dbsd-admin-ops.js', array(), DBSD_VERSION, true);
        wp_localize_script('dbsd-admin-ops', 'DBSD_ADMIN_OPS', array('restUrl' => esc_url_raw(rest_url('datebook-safedate/v1')), 'nonce' => wp_create_nonce('wp_rest')));
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'SafeDate Operations', 'Operations v0.5', 'dbsd_manage_safety', 'dbsd-ops', array(__CLASS__, 'admin_ops_page'));
    }

    public static function admin_ops_page() {
        echo '<div class="wrap"><h1>SafeDate Operations v0.5</h1><p>Escalations, overdue check-ins, device-health telemetry, and privacy requests.</p><div id="dbsd-ops-root" class="dbsd-admin-live">Loading operations monitor...</div></div>';
    }

    public static function safety_center_shortcode($atts) {
        if (!is_user_logged_in()) return '<p>Please log in to view the SafeDate safety center.</p>';
        $atts = shortcode_atts(array('id' => 0), $atts);
        $id = absint($atts['id']);
        wp_enqueue_script('dbsd-v050');
        return '<div class="dbsd-card dbsd-v05-safety" data-dbsd-v05-session="' . esc_attr($id) . '"><h3>SafeDate Safety Center</h3><p class="dbsd-muted">Request a check-in, respond to check-ins, review device health, and view safety status.</p><div class="dbsd-controls"><button type="button" class="dbsd-btn" data-dbsd-v05="refresh-status">Refresh Safety Status</button><button type="button" class="dbsd-btn" data-dbsd-v05="request-self-checkin">Request My Check-in</button><button type="button" class="dbsd-btn dbsd-btn-danger" data-dbsd-v05="respond-help">I Need Help</button><button type="button" class="dbsd-btn" data-dbsd-v05="respond-safe">I Am Safe</button></div><div class="dbsd-result" data-dbsd-v05-status></div><pre class="dbsd-audit" data-dbsd-v05-output></pre></div>';
    }

    public static function privacy_tools_shortcode() {
        if (!is_user_logged_in()) return '<p>Please log in to use SafeDate privacy tools.</p>';
        wp_enqueue_script('dbsd-v050');
        return '<div class="dbsd-card dbsd-v05-privacy"><h3>SafeDate Privacy Tools</h3><p class="dbsd-muted">Request an export, correction, or deletion review for your SafeDate data.</p><select data-dbsd-privacy-type><option value="export">Data export</option><option value="correction">Correction request</option><option value="delete_review">Deletion review</option></select><textarea data-dbsd-privacy-message placeholder="Optional note"></textarea><button type="button" class="dbsd-btn" data-dbsd-v05="privacy-request">Submit request</button><button type="button" class="dbsd-btn" data-dbsd-v05="privacy-my-data">View my SafeDate data summary</button><div class="dbsd-result" data-dbsd-v05-status></div><pre class="dbsd-audit" data-dbsd-v05-output></pre></div>';
    }
}
