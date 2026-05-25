<?php
if (!defined('ABSPATH')) exit;

class DBSD_REST {
    public static function init() { add_action('rest_api_init', array(__CLASS__, 'routes')); }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        $post = array('methods' => 'POST', 'permission_callback' => array(__CLASS__, 'logged_in'));
        register_rest_route($ns, '/session/create', $post + array('callback' => array(__CLASS__, 'create_session')));
        register_rest_route($ns, '/session/consent', $post + array('callback' => array(__CLASS__, 'consent')));
        register_rest_route($ns, '/journey/start', $post + array('callback' => array(__CLASS__, 'journey_start')));
        register_rest_route($ns, '/journey/stop', $post + array('callback' => array(__CLASS__, 'journey_stop')));
        register_rest_route($ns, '/location/ping', $post + array('callback' => array(__CLASS__, 'location_ping')));
        register_rest_route($ns, '/arrival/claim', $post + array('callback' => array(__CLASS__, 'arrival_claim')));
        register_rest_route($ns, '/arrival/respond', $post + array('callback' => array(__CLASS__, 'arrival_respond')));
        register_rest_route($ns, '/departure/claim', $post + array('callback' => array(__CLASS__, 'departure_claim')));
        register_rest_route($ns, '/departure/respond', $post + array('callback' => array(__CLASS__, 'departure_respond')));
        register_rest_route($ns, '/emergency/sos', $post + array('callback' => array(__CLASS__, 'sos')));
        register_rest_route($ns, '/contacts/save', $post + array('callback' => array(__CLASS__, 'save_contact')));
        register_rest_route($ns, '/notifications/read', $post + array('callback' => array(__CLASS__, 'mark_notification_read')));
        register_rest_route($ns, '/session/(?P<id>\d+)', array('methods' => 'GET', 'callback' => array(__CLASS__, 'session'), 'permission_callback' => array(__CLASS__, 'can_view_session')));
        register_rest_route($ns, '/session/(?P<id>\d+)/audit', array('methods' => 'GET', 'callback' => array(__CLASS__, 'audit'), 'permission_callback' => array(__CLASS__, 'can_view_session')));
        register_rest_route($ns, '/session/(?P<id>\d+)/locations', array('methods' => 'GET', 'callback' => array(__CLASS__, 'locations'), 'permission_callback' => array(__CLASS__, 'can_view_session')));
        register_rest_route($ns, '/me/notifications', array('methods' => 'GET', 'callback' => array(__CLASS__, 'my_notifications'), 'permission_callback' => array(__CLASS__, 'logged_in')));
    }

    public static function logged_in() { return is_user_logged_in(); }
    private static function json($request) { $params = $request->get_json_params(); return is_array($params) ? $params : array(); }
    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }
    private static function get_session($session_id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('sessions') . " WHERE id = %d", absint($session_id))); }
    private static function is_participant($session) { $uid = get_current_user_id(); return $session && ((int)$session->host_user_id === $uid || (int)$session->traveler_user_id === $uid || current_user_can('dbsd_manage_safety')); }
    public static function can_view_session($request) { return is_user_logged_in() && self::is_participant(self::get_session(absint($request['id']))); }
    private static function require_participant($session_id) { $session = self::get_session($session_id); if (!self::is_participant($session)) return new WP_Error('dbsd_forbidden', __('You cannot access this SafeDate session.', 'datebook-safedate'), array('status' => 403)); return $session; }
    private static function mysql_datetime($value) { $value = sanitize_text_field((string) $value); if (!$value) return null; return str_replace('T', ' ', substr($value, 0, 19)); }

    private static function transition_guard($session, $action) {
        if (!class_exists('DBSD_State')) return true;
        $role = DBSD_State::role($session);
        return DBSD_State::assert_transition($session, $role, $action);
    }

    private static function audit_rows_for_viewer($session_id, $session) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, actor_user_id, event_type, event_payload, event_hash, previous_event_hash, ip_address, user_agent, created_at FROM " . self::table('events') . " WHERE session_id=%d ORDER BY id ASC", $session_id));
        $mode = class_exists('DBSD_State') ? DBSD_State::viewer_location_mode($session) : 'approximate';
        foreach ($rows as $row) {
            if ($mode !== 'exact') {
                $payload = json_decode((string)$row->event_payload, true);
                if (json_last_error() === JSON_ERROR_NONE && class_exists('DBSD_State')) {
                    $row->event_payload = wp_json_encode(DBSD_State::redact_location_payload($payload, 'approximate'));
                }
                unset($row->ip_address, $row->user_agent);
            }
        }
        return $rows;
    }

    private static function create_notification($session_id, $recipient_user_id, $type, $message) {
        global $wpdb;
        $wpdb->insert(self::table('notifications'), array(
            'session_id' => $session_id,
            'recipient_user_id' => $recipient_user_id,
            'notification_type' => sanitize_key($type),
            'status' => 'pending',
            'message' => sanitize_textarea_field($message),
            'created_at' => current_time('mysql', true),
            'sent_at' => current_time('mysql', true),
        ));
        DBSD_Audit::log_event($session_id, 0, 'notification_created', array('recipient_user_id' => $recipient_user_id, 'type' => $type));
    }

    public static function create_session($request) {
        global $wpdb;
        $p = self::json($request);
        $host_user_id = absint($p['host_user_id'] ?? 0);
        $traveler_user_id = absint($p['traveler_user_id'] ?? 0);
        if (!$host_user_id || !$traveler_user_id || $host_user_id === $traveler_user_id) return new WP_Error('dbsd_bad_users', __('Host and traveler must be two valid users.', 'datebook-safedate'), array('status' => 400));
        $current = get_current_user_id();
        if ($current !== $host_user_id && $current !== $traveler_user_id && !current_user_can('dbsd_manage_safety')) return new WP_Error('dbsd_forbidden', __('Only a participant can create this session.', 'datebook-safedate'), array('status' => 403));
        $now = current_time('mysql', true);
        $wpdb->insert(self::table('sessions'), array(
            'host_user_id' => $host_user_id,
            'traveler_user_id' => $traveler_user_id,
            'meeting_address' => sanitize_textarea_field($p['meeting_address'] ?? ''),
            'meeting_lat' => isset($p['meeting_lat']) && $p['meeting_lat'] !== '' ? floatval($p['meeting_lat']) : null,
            'meeting_lng' => isset($p['meeting_lng']) && $p['meeting_lng'] !== '' ? floatval($p['meeting_lng']) : null,
            'planned_start_at' => self::mysql_datetime($p['planned_start_at'] ?? ''),
            'planned_end_at' => self::mysql_datetime($p['planned_end_at'] ?? ''),
            'expected_arrival_at' => self::mysql_datetime($p['expected_arrival_at'] ?? ''),
            'expected_departure_at' => self::mysql_datetime($p['expected_departure_at'] ?? ''),
            'status' => 'pending_consent',
            'alert_level' => 'normal',
            'created_by' => $current,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $session_id = $wpdb->insert_id;
        DBSD_Audit::log_event($session_id, $current, 'session_created', array('host_user_id' => $host_user_id, 'traveler_user_id' => $traveler_user_id));
        self::create_notification($session_id, $host_user_id === $current ? $traveler_user_id : $host_user_id, 'consent_requested', 'A SafeDate session needs your consent.');
        return array('ok' => true, 'session_id' => $session_id);
    }

    public static function session($request) {
        $s = self::get_session(absint($request['id']));
        return array('ok' => true, 'session' => $s, 'role' => ((int)$s->host_user_id === get_current_user_id() ? 'host' : (((int)$s->traveler_user_id === get_current_user_id()) ? 'traveler' : 'admin')));
    }

    public static function consent($request) {
        global $wpdb;
        $p = self::json($request); $session_id = absint($p['session_id'] ?? 0); $session = self::require_participant($session_id); if (is_wp_error($session)) return $session;
        $user_id = get_current_user_id(); $status = !empty($p['accepted']) ? 'accepted' : 'declined';
        $wpdb->replace(self::table('consents'), array('session_id' => $session_id, 'user_id' => $user_id, 'consent_status' => $status, 'consented_at' => current_time('mysql', true), 'ip_address' => DBSD_Audit::client_ip(), 'user_agent' => DBSD_Audit::user_agent()));
        DBSD_Audit::log_event($session_id, $user_id, 'session_consent_' . $status, array());
        $accepted_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('consents') . " WHERE session_id=%d AND consent_status='accepted'", $session_id));
        if ($accepted_count >= 2) { $wpdb->update(self::table('sessions'), array('status' => 'ready', 'updated_at' => current_time('mysql', true)), array('id' => $session_id)); DBSD_Audit::log_event($session_id, 0, 'session_ready', array()); self::create_notification($session_id, (int)$session->traveler_user_id, 'session_ready', 'SafeDate is ready. You may start your journey.'); }
        return array('ok' => true, 'status' => $status);
    }

    public static function journey_start($request) {
        global $wpdb;
        $p = self::json($request); $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id); if (is_wp_error($session)) return $session;
        $guard = self::transition_guard($session, 'journey_start'); if (is_wp_error($guard)) return $guard;
        if ((int)$session->traveler_user_id !== get_current_user_id()) return new WP_Error('dbsd_only_traveler', __('Only the traveler can start journey tracking.', 'datebook-safedate'), array('status' => 403));
        $wpdb->update(self::table('sessions'), array('status' => 'journey_started', 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'journey_started', array());
        self::create_notification($session_id, (int)$session->host_user_id, 'journey_started', 'The traveler has started journey tracking.');
        return array('ok' => true);
    }
    public static function journey_stop($request) {
        $p = self::json($request); $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id); if (is_wp_error($session)) return $session;
        $guard = self::transition_guard($session, 'journey_stop'); if (is_wp_error($guard)) return $guard;
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'tracking_stopped', array('reason' => sanitize_text_field($p['reason'] ?? 'manual')));
        return array('ok' => true);
    }

    public static function location_ping($request) {
        global $wpdb; $p = self::json($request); $session_id = absint($p['session_id'] ?? 0); $session = self::require_participant($session_id); if (is_wp_error($session)) return $session; $guard = self::transition_guard($session, 'location_ping'); if (is_wp_error($guard)) return $guard; if ((int)$session->traveler_user_id !== get_current_user_id()) return new WP_Error('dbsd_only_traveler', __('Only the traveler location can be recorded.', 'datebook-safedate'), array('status' => 403));
        $lat = isset($p['lat']) ? floatval($p['lat']) : null; $lng = isset($p['lng']) ? floatval($p['lng']) : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return new WP_Error('dbsd_bad_location', __('Invalid location.', 'datebook-safedate'), array('status' => 400));
        $recorded_at = self::mysql_datetime($p['recorded_at'] ?? current_time('mysql', true));
        $wpdb->insert(self::table('locations'), array('session_id' => $session_id, 'user_id' => get_current_user_id(), 'lat' => $lat, 'lng' => $lng, 'accuracy' => isset($p['accuracy']) ? floatval($p['accuracy']) : null, 'speed' => isset($p['speed']) ? floatval($p['speed']) : null, 'heading' => isset($p['heading']) ? floatval($p['heading']) : null, 'battery_level' => isset($p['battery_level']) ? floatval($p['battery_level']) : null, 'recorded_at' => $recorded_at, 'created_at' => current_time('mysql', true)));
        $wpdb->update(self::table('sessions'), array('last_location_at' => $recorded_at, 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'location_ping', array('lat' => $lat, 'lng' => $lng, 'accuracy' => $p['accuracy'] ?? null)); return array('ok' => true);
    }

    public static function arrival_claim($request) {
        global $wpdb; $p = self::json($request); $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id); if (is_wp_error($session)) return $session;
        $guard = self::transition_guard($session, 'arrival_claim'); if (is_wp_error($guard)) return $guard;
        if ((int)$session->traveler_user_id !== get_current_user_id()) return new WP_Error('dbsd_only_traveler', __('Only the traveler can claim arrival.', 'datebook-safedate'), array('status' => 403));
        $wpdb->update(self::table('sessions'), array('status' => 'arrival_claimed', 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'arrival_claimed_by_traveler', DBSD_State::redact_location_payload($p, 'approximate'));
        self::create_notification($session_id, (int)$session->host_user_id, 'arrival_claimed', 'Traveler says they have arrived. Please confirm or reject.');
        return array('ok' => true);
    }
    public static function arrival_respond($request) {
        global $wpdb; $p = self::json($request); $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id); if (is_wp_error($session)) return $session;
        $guard = self::transition_guard($session, 'arrival_respond'); if (is_wp_error($guard)) return $guard;
        if ((int)$session->host_user_id !== get_current_user_id()) return new WP_Error('dbsd_only_host', __('Only the host can respond to arrival.', 'datebook-safedate'), array('status' => 403));
        $accepted = !empty($p['accepted']);
        $wpdb->update(self::table('sessions'), array('status' => $accepted ? 'arrival_confirmed' : 'arrival_disputed', 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, get_current_user_id(), $accepted ? 'arrival_confirmed_by_host' : 'arrival_rejected_by_host', array('accepted' => $accepted));
        self::create_notification($session_id, (int)$session->traveler_user_id, $accepted ? 'arrival_confirmed' : 'arrival_rejected', $accepted ? 'Host confirmed your arrival.' : 'Host rejected your arrival claim.');
        return array('ok' => true);
    }
    public static function departure_claim($request) {
        global $wpdb; $p = self::json($request); $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id); if (is_wp_error($session)) return $session;
        $guard = self::transition_guard($session, 'departure_claim'); if (is_wp_error($guard)) return $guard;
        if ((int)$session->host_user_id !== get_current_user_id()) return new WP_Error('dbsd_only_host', __('Only the host can claim departure.', 'datebook-safedate'), array('status' => 403));
        $wpdb->update(self::table('sessions'), array('status' => 'departure_claimed', 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, get_current_user_id(), 'departure_claimed_by_host', array('claimed' => true));
        self::create_notification($session_id, (int)$session->traveler_user_id, 'departure_claimed', 'Host says you have left. Please confirm or reject.');
        return array('ok' => true);
    }
    public static function departure_respond($request) {
        global $wpdb; $p = self::json($request); $session_id = absint($p['session_id'] ?? 0);
        $session = self::require_participant($session_id); if (is_wp_error($session)) return $session;
        $guard = self::transition_guard($session, 'departure_respond'); if (is_wp_error($guard)) return $guard;
        if ((int)$session->traveler_user_id !== get_current_user_id()) return new WP_Error('dbsd_only_traveler', __('Only the traveler can confirm departure.', 'datebook-safedate'), array('status' => 403));
        $accepted = !empty($p['accepted']);
        $wpdb->update(self::table('sessions'), array('status' => $accepted ? 'completed' : 'departure_disputed', 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, get_current_user_id(), $accepted ? 'departure_confirmed_by_traveler' : 'departure_rejected_by_traveler', array('accepted' => $accepted));
        self::create_notification($session_id, (int)$session->host_user_id, $accepted ? 'departure_confirmed' : 'departure_rejected', $accepted ? 'Traveler confirmed departure.' : 'Traveler rejected the departure claim.');
        return array('ok' => true);
    }

    public static function sos($request) { $p = self::json($request); $session_id = absint($p['session_id'] ?? 0); $session = self::require_participant($session_id); if (is_wp_error($session)) return $session; $payload = array('message' => sanitize_textarea_field($p['message'] ?? ''), 'lat' => isset($p['lat']) ? floatval($p['lat']) : null, 'lng' => isset($p['lng']) ? floatval($p['lng']) : null); DBSD_Monitor::raise_alert($session_id, get_current_user_id(), 'sos', $payload); self::create_notification($session_id, ((int)$session->host_user_id === get_current_user_id()) ? (int)$session->traveler_user_id : (int)$session->host_user_id, 'sos_alert', 'An SOS alert was triggered in this SafeDate session.'); return array('ok' => true); }

    public static function save_contact($request) { global $wpdb; $p = self::json($request); $uid = get_current_user_id(); $name = sanitize_text_field($p['contact_name'] ?? ''); $email = sanitize_email($p['contact_email'] ?? ''); $phone = sanitize_text_field($p['contact_phone'] ?? ''); if (!$name || (!$email && !$phone)) return new WP_Error('dbsd_bad_contact', __('Enter a contact name plus email or phone.', 'datebook-safedate'), array('status' => 400)); $now = current_time('mysql', true); $wpdb->insert(self::table('emergency_contacts'), array('user_id' => $uid, 'contact_name' => $name, 'contact_email' => $email, 'contact_phone' => $phone, 'can_receive_alerts' => 1, 'created_at' => $now, 'updated_at' => $now)); return array('ok' => true, 'contact_id' => $wpdb->insert_id); }

    public static function my_notifications() { global $wpdb; $uid = get_current_user_id(); $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('notifications') . " WHERE recipient_user_id=%d ORDER BY id DESC LIMIT 20", $uid)); return array('ok' => true, 'notifications' => $rows); }
    public static function mark_notification_read($request) { global $wpdb; $p = self::json($request); $id = absint($p['notification_id'] ?? 0); if (!$id) return new WP_Error('dbsd_bad_notification', __('Missing notification ID.', 'datebook-safedate'), array('status' => 400)); $wpdb->update(self::table('notifications'), array('status' => 'read', 'read_at' => current_time('mysql', true)), array('id' => $id, 'recipient_user_id' => get_current_user_id())); return array('ok' => true); }

    public static function audit($request) {
        $session_id = absint($request['id']);
        $session = self::get_session($session_id);
        if (!self::is_participant($session)) return new WP_Error('dbsd_forbidden', __('Forbidden.', 'datebook-safedate'), array('status' => 403));
        return array('ok' => true, 'events' => self::audit_rows_for_viewer($session_id, $session), 'redaction_mode' => class_exists('DBSD_State') ? DBSD_State::viewer_location_mode($session) : 'approximate');
    }
    public static function locations($request) { global $wpdb; $session_id = absint($request['id']); $session = self::get_session($session_id); if (!self::is_participant($session)) return new WP_Error('dbsd_forbidden', __('Forbidden.', 'datebook-safedate'), array('status' => 403)); $show_exact = get_option('dbsd_show_exact_location_to_host', 'no') === 'yes'; if ((int)$session->host_user_id === get_current_user_id() && !$show_exact && !current_user_can('dbsd_manage_safety')) { $rows = $wpdb->get_results($wpdb->prepare("SELECT id, ROUND(lat, 2) AS lat, ROUND(lng, 2) AS lng, CASE WHEN accuracy IS NULL THEN NULL WHEN accuracy <= 100 THEN 100 WHEN accuracy <= 1000 THEN 1000 ELSE 10000 END AS accuracy, recorded_at FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at ASC", $session_id)); } else { $rows = $wpdb->get_results($wpdb->prepare("SELECT id, lat, lng, accuracy, speed, heading, battery_level, recorded_at FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at ASC", $session_id)); } return array('ok' => true, 'locations' => $rows); }
}
