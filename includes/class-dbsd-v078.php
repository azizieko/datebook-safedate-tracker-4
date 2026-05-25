<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.7.8 native signature compatibility and CI proof helpers.
 * Adds canonical route helpers, pairing-attempt telemetry, diagnostics, and
 * runtime-testable metadata for client/server HMAC compatibility.
 */
class DBSD_V078 {
    const VERSION = '0.7.10';
    const REST_NAMESPACE = '/datebook-safedate/v1';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 3);
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 78);
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v078_version', '0');
        if (version_compare($current, self::VERSION, '>=')) return;
        self::install_tables();
        add_option('dbsd_pairing_attempt_retention_days', 90);
        update_option('dbsd_v078_version', self::VERSION);
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('mobile_pairing_attempts') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pairing_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            device_uuid VARCHAR(128) NULL,
            result VARCHAR(40) NOT NULL,
            reason VARCHAR(120) NULL,
            ip_address VARCHAR(100) NULL,
            user_agent TEXT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY pairing_id (pairing_id),
            KEY user_id (user_id),
            KEY device_uuid (device_uuid),
            KEY result (result),
            KEY created_at (created_at)
        ) $charset;");
    }

    public static function canonical_route($short_route) {
        $route = '/' . ltrim((string)$short_route, '/');
        if (strpos($route, self::REST_NAMESPACE . '/') === 0) return $route;
        return self::REST_NAMESPACE . $route;
    }

    public static function canonical_request_string($method, $route, $timestamp, $nonce, $body) {
        return strtoupper((string)$method) . "\n" . self::canonical_route($route) . "\n" . (int)$timestamp . "\n" . (string)$nonce . "\n" . hash('sha256', (string)$body);
    }

    public static function hmac_signature($secret, $method, $route, $timestamp, $nonce, $body) {
        return base64_encode(hash_hmac('sha256', self::canonical_request_string($method, $route, $timestamp, $nonce, $body), (string)$secret, true));
    }

    public static function record_pairing_attempt($pairing_id, $user_id, $device_uuid, $result, $reason, array $metadata = array()) {
        global $wpdb;
        $wpdb->insert(self::table('mobile_pairing_attempts'), array(
            'pairing_id' => $pairing_id ? (int)$pairing_id : null,
            'user_id' => $user_id ? (int)$user_id : null,
            'device_uuid' => sanitize_text_field((string)$device_uuid),
            'result' => sanitize_key((string)$result),
            'reason' => sanitize_key((string)$reason),
            'ip_address' => class_exists('DBSD_Audit') ? DBSD_Audit::client_ip() : sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'metadata' => wp_json_encode($metadata),
            'created_at' => current_time('mysql', true),
        ));
        return (int)$wpdb->insert_id;
    }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/mobile/signature-test-vector', array('methods' => 'GET', 'callback' => array(__CLASS__, 'signature_test_vector'), 'permission_callback' => '__return_true'));
        register_rest_route($ns, '/admin/pairing-attempts', array('methods' => 'GET', 'callback' => array(__CLASS__, 'pairing_attempts'), 'permission_callback' => array(__CLASS__, 'can_manage_devices')));
        register_rest_route($ns, '/ci/proof', array('methods' => 'GET', 'callback' => array(__CLASS__, 'ci_proof'), 'permission_callback' => array(__CLASS__, 'can_manage_settings')));
    }

    public static function can_manage_devices() { return current_user_can('dbsd_manage_devices') || current_user_can('dbsd_manage_safety'); }
    public static function can_manage_settings() { return current_user_can('dbsd_manage_settings') || current_user_can('dbsd_manage_safety'); }

    public static function signature_test_vector() {
        $secret = 'test-signing-secret';
        $method = 'POST';
        $route = '/mobile/refresh-token';
        $timestamp = 1710000000;
        $nonce = 'nonce-12345';
        $body = '{"device_uuid":"device-123","refresh_token":"refresh-abc"}';
        $canonical_route = self::canonical_route($route);
        $canonical = self::canonical_request_string($method, $route, $timestamp, $nonce, $body);
        return array(
            'ok' => true,
            'version' => self::VERSION,
            'method' => $method,
            'short_route' => $route,
            'canonical_route' => $canonical_route,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'body' => $body,
            'body_sha256' => hash('sha256', $body),
            'canonical' => $canonical,
            'signature_base64' => base64_encode(hash_hmac('sha256', $canonical, $secret, true)),
            'test_secret_reference' => 'native/shared/test-vector-v0.7.json only; not returned by public endpoint',
        );
    }

    public static function pairing_attempts($request) {
        global $wpdb;
        $limit = min(200, max(1, absint($request['limit'] ?? 50)));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, pairing_id, user_id, device_uuid, result, reason, ip_address, created_at FROM ' . self::table('mobile_pairing_attempts') . ' ORDER BY id DESC LIMIT %d', $limit));
        return array('ok' => true, 'attempts' => $rows);
    }

    public static function ci_proof() {
        $ci_file = DBSD_PLUGIN_DIR . 'docs/ci-results-v0.7.10.md';
        return array(
            'ok' => true,
            'version' => self::VERSION,
            'phpunit_config' => file_exists(DBSD_PLUGIN_DIR . 'phpunit.xml.dist'),
            'github_actions_workflow' => file_exists(DBSD_PLUGIN_DIR . '.github/workflows/phpunit.yml'),
            'ci_results_document' => file_exists($ci_file) ? 'docs/ci-results-v0.7.10.md' : null,
            'full_ci_run_status' => 'must_be_verified_in_github_actions_or_equivalent_wordpress_test_environment',
        );
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'Native Compatibility v0.7.10', 'Native Compatibility v0.7.10', 'dbsd_manage_devices', 'dbsd-v078', array(__CLASS__, 'admin_page'));
    }

    public static function admin_page() {
        if (!self::can_manage_devices()) return;
        echo '<div class="wrap"><h1>SafeDate Native Signature Compatibility v0.7.10</h1>';
        echo '<p>v0.7.10 keeps Android/iOS starter signing with the WordPress REST canonical route and adds pairing-attempt telemetry.</p>';
        echo '<h2>Signature test vector</h2><pre>' . esc_html(wp_json_encode(self::signature_test_vector(), JSON_PRETTY_PRINT)) . '</pre>';
        echo '<h2>CI proof status</h2><pre>' . esc_html(wp_json_encode(self::ci_proof(), JSON_PRETTY_PRINT)) . '</pre></div>';
    }
}
