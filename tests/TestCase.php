<?php
/**
 * Shared fixtures for DateBook SafeDate tests.
 */

abstract class DBSD_TestCase extends WP_UnitTestCase {
    protected $host_id;
    protected $traveler_id;

    public function set_up() {
        parent::set_up();
        global $wpdb;

        DBSD_Activator::activate();
        DBSD_V030::maybe_upgrade();
        DBSD_V040::maybe_upgrade();
        DBSD_V050::maybe_upgrade();
        DBSD_V060::maybe_upgrade();
        if (class_exists('DBSD_V074')) DBSD_V074::maybe_upgrade();
        if (class_exists('DBSD_V075')) DBSD_V075::maybe_upgrade();
        if (class_exists('DBSD_V078')) DBSD_V078::maybe_upgrade();
        if (class_exists('DBSD_V079')) DBSD_V079::maybe_upgrade();
        if (class_exists('DBSD_V0710')) DBSD_V0710::maybe_upgrade();

        $tables = array(
            'dbsd_sessions', 'dbsd_consents', 'dbsd_locations', 'dbsd_events', 'dbsd_notifications',
            'dbsd_mobile_devices', 'dbsd_mobile_nonces', 'dbsd_mobile_rate_limits', 'dbsd_mobile_security_events', 'dbsd_mobile_refresh_history',
            'dbsd_push_subscriptions', 'dbsd_mobile_pairing_codes', 'dbsd_mobile_pairing_attempts'
        );
        foreach ($tables as $table) {
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . $table);
        }

        $this->host_id = self::factory()->user->create(array('role' => 'subscriber'));
        $this->traveler_id = self::factory()->user->create(array('role' => 'subscriber'));
        update_option('dbsd_show_exact_location_to_host', 'no');
        update_option('dbsd_mobile_api_enabled', 'yes');
        update_option('dbsd_mobile_rate_limit_per_minute', 60);
        update_option('dbsd_mobile_location_rate_limit_per_minute', 30);
        update_option('dbsd_mobile_signature_window_seconds', 300);
    }

    protected function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'dbsd_' . $name;
    }

    protected function create_session($status = 'ready') {
        global $wpdb;
        $wpdb->insert($this->table('sessions'), array(
            'host_user_id' => $this->host_id,
            'traveler_user_id' => $this->traveler_id,
            'meeting_address' => '123 Test Street',
            'meeting_lat' => 43.653225,
            'meeting_lng' => -79.383186,
            'planned_start_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'planned_end_at' => gmdate('Y-m-d H:i:s', time() + 7200),
            'expected_arrival_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'expected_departure_at' => gmdate('Y-m-d H:i:s', time() + 7200),
            'status' => $status,
            'alert_level' => 'normal',
            'created_by' => $this->host_id,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ));
        return (int) $wpdb->insert_id;
    }

    protected function get_session($session_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('sessions') . ' WHERE id=%d', $session_id));
    }

    protected function call_private_static($class, $method, array $args = array()) {
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    protected function rest_request($method, $route, array $body = array(), array $headers = array()) {
        $request = new WP_REST_Request($method, $route);
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        if (!empty($body)) {
            $request->set_body(wp_json_encode($body));
            $request->set_header('Content-Type', 'application/json');
            $request->set_body_params($body);
        }
        return rest_get_server()->dispatch($request);
    }
}
