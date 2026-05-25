<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.6 native mobile API hardening layer.
 * Adds mobile device registration, bearer access/refresh tokens, HMAC-signed payloads,
 * replay protection, per-device rate limiting, and Android/iOS integration documentation hooks.
 */
class DBSD_V060 {
    const DB_VERSION = '0.7.4';
    const ACCESS_TTL = 86400; // 24 hours.
    const REFRESH_TTL = 2592000; // 30 days.
    const SIGNATURE_WINDOW = 300; // 5 minutes.

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 50);
        add_action('admin_init', array(__CLASS__, 'settings'));
        add_shortcode('db_safedate_mobile_pairing', array(__CLASS__, 'mobile_pairing_shortcode'));
        add_action('dbsd_v060_cleanup', array(__CLASS__, 'cleanup'));
        if (!wp_next_scheduled('dbsd_v060_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dbsd_v060_cleanup');
        }
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }
    private static function json($request) { $params = $request->get_json_params(); return is_array($params) ? $params : array(); }
    public static function logged_in() { return is_user_logged_in(); }
    public static function public_permission() { return true; }
    public static function admin_only() { return current_user_can('dbsd_manage_devices') || current_user_can('dbsd_manage_safety'); }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v060_db_version', '0');
        if (version_compare($current, self::DB_VERSION, '>=')) return;
        self::install_tables();
        update_option('dbsd_v060_db_version', self::DB_VERSION);
        add_option('dbsd_mobile_api_enabled', 'yes');
        add_option('dbsd_mobile_rate_limit_per_minute', 60);
        add_option('dbsd_mobile_location_rate_limit_per_minute', 30);
        add_option('dbsd_mobile_signature_window_seconds', self::SIGNATURE_WINDOW);
        add_option('dbsd_mobile_replay_retention_minutes', 20);
        add_option('dbsd_mobile_signed_refresh_required', 'yes');
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::table('mobile_devices') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            device_uuid VARCHAR(128) NOT NULL,
            platform VARCHAR(40) NULL,
            device_name VARCHAR(190) NULL,
            app_version VARCHAR(60) NULL,
            access_token_hash CHAR(64) NOT NULL,
            refresh_token_hash CHAR(64) NOT NULL,
            signing_secret_sealed LONGTEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            access_expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL,
            last_ip VARCHAR(100) NULL,
            last_user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_device (user_id, device_uuid),
            KEY access_token_hash (access_token_hash),
            KEY refresh_token_hash (refresh_token_hash),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('mobile_nonces') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id BIGINT UNSIGNED NOT NULL,
            nonce_hash CHAR(64) NOT NULL,
            request_timestamp BIGINT UNSIGNED NOT NULL,
            route VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY device_nonce (device_id, nonce_hash),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('mobile_rate_limits') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identity_hash CHAR(64) NOT NULL,
            route VARCHAR(190) NOT NULL,
            window_start BIGINT UNSIGNED NOT NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY identity_route_window (identity_hash, route, window_start),
            KEY updated_at (updated_at)
        ) $charset;");


        dbDelta("CREATE TABLE " . self::table('mobile_refresh_history') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            refresh_token_hash CHAR(64) NOT NULL,
            replaced_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            reused_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY refresh_token_hash (refresh_token_hash),
            KEY device_id (device_id),
            KEY expires_at (expires_at)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('mobile_security_events') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            session_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(80) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            event_payload LONGTEXT NULL,
            ip_address VARCHAR(100) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY device_id (device_id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY severity (severity),
            KEY created_at (created_at)
        ) $charset;");
    }

    public static function settings() {
        register_setting('dbsd_settings', 'dbsd_mobile_api_enabled', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'yes'));
        register_setting('dbsd_settings', 'dbsd_mobile_rate_limit_per_minute', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 60));
        register_setting('dbsd_settings', 'dbsd_mobile_location_rate_limit_per_minute', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30));
        register_setting('dbsd_settings', 'dbsd_mobile_signature_window_seconds', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => self::SIGNATURE_WINDOW));
        register_setting('dbsd_settings', 'dbsd_mobile_replay_retention_minutes', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 20));
        register_setting('dbsd_settings', 'dbsd_mobile_signed_refresh_required', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'yes'));
        register_setting('dbsd_settings', 'dbsd_trusted_proxy_cidrs', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => ''));
    }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/mobile/register-device', array('methods' => 'POST', 'callback' => array(__CLASS__, 'register_device'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/mobile/refresh-token', array('methods' => 'POST', 'callback' => array(__CLASS__, 'refresh_token'), 'permission_callback' => array(__CLASS__, 'public_permission')));
        register_rest_route($ns, '/mobile/revoke-device', array('methods' => 'POST', 'callback' => array(__CLASS__, 'revoke_device'), 'permission_callback' => array(__CLASS__, 'public_permission')));
        register_rest_route($ns, '/mobile/whoami', array('methods' => 'GET', 'callback' => array(__CLASS__, 'mobile_whoami'), 'permission_callback' => array(__CLASS__, 'public_permission')));
        register_rest_route($ns, '/mobile/location/signed', array('methods' => 'POST', 'callback' => array(__CLASS__, 'signed_location_ping'), 'permission_callback' => array(__CLASS__, 'public_permission')));
        register_rest_route($ns, '/mobile/device-health/signed', array('methods' => 'POST', 'callback' => array(__CLASS__, 'signed_device_health'), 'permission_callback' => array(__CLASS__, 'public_permission')));
        register_rest_route($ns, '/mobile/session/(?P<id>\d+)/safety-status', array('methods' => 'GET', 'callback' => array(__CLASS__, 'mobile_safety_status'), 'permission_callback' => array(__CLASS__, 'public_permission')));
        register_rest_route($ns, '/admin/mobile/security', array('methods' => 'GET', 'callback' => array(__CLASS__, 'admin_mobile_security'), 'permission_callback' => array(__CLASS__, 'admin_only')));
        register_rest_route($ns, '/admin/mobile/revoke-device', array('methods' => 'POST', 'callback' => array(__CLASS__, 'admin_revoke_device'), 'permission_callback' => array(__CLASS__, 'admin_only')));
    }

    private static function api_enabled() {
        return get_option('dbsd_mobile_api_enabled', 'yes') === 'yes';
    }

    private static function client_ip() {
        if (class_exists('DBSD_Audit')) return DBSD_Audit::client_ip();
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function user_agent() {
        if (class_exists('DBSD_Audit')) return DBSD_Audit::user_agent();
        return sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    private static function random_token($bytes = 32) {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function token_hash($token) {
        return hash('sha256', (string)$token . '|' . wp_salt('auth'));
    }

    private static function crypto_key() {
        return hash('sha256', wp_salt('secure_auth') . '|' . wp_salt('auth'), true);
    }

    private static function seal_secret($secret) {
        $key = self::crypto_key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox((string)$secret, $nonce, $key);
            return 'sodium_secretbox:' . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt((string)$secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher !== false) return 'aes256gcm:' . base64_encode($iv . $tag . $cipher);
        }
        return new WP_Error('dbsd_crypto_unavailable', __('Authenticated encryption is unavailable. Enable Sodium or OpenSSL AES-256-GCM before registering mobile devices.', 'datebook-safedate'), array('status' => 500));
    }

    private static function unseal_secret($sealed) {
        $sealed = (string)$sealed;
        $key = self::crypto_key();
        if (strpos($sealed, 'sodium_secretbox:') === 0 && function_exists('sodium_crypto_secretbox_open')) {
            $raw = base64_decode(substr($sealed, 17), true);
            if ($raw && strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
                if ($plain !== false) return $plain;
            }
        }
        if (strpos($sealed, 'aes256gcm:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($sealed, 10), true);
            if ($raw && strlen($raw) > 28) {
                $iv = substr($raw, 0, 12);
                $tag = substr($raw, 12, 16);
                $cipher = substr($raw, 28);
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($plain !== false) return $plain;
            }
        }
        if (strpos($sealed, 'aes256cbc:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($sealed, 10), true);
            if ($raw && strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $cipher = substr($raw, 16);
                $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                if ($plain !== false) return $plain;
            }
        }
        return '';
    }

    public static function security_event($device_id, $user_id, $session_id, $type, $severity, $payload = array()) {
        global $wpdb;
        $wpdb->insert(self::table('mobile_security_events'), array(
            'device_id' => $device_id ? absint($device_id) : null,
            'user_id' => $user_id ? absint($user_id) : null,
            'session_id' => $session_id ? absint($session_id) : null,
            'event_type' => sanitize_key($type),
            'severity' => sanitize_key($severity),
            'event_payload' => wp_json_encode($payload),
            'ip_address' => self::client_ip(),
            'user_agent' => self::user_agent(),
            'created_at' => current_time('mysql', true),
        ));
    }

    private static function get_bearer_token($request) {
        $auth = $request->get_header('authorization');
        if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) $auth = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        if (preg_match('/Bearer\s+(.+)/i', (string)$auth, $m)) return trim($m[1]);
        return '';
    }

    private static function find_device_by_access_token($token) {
        global $wpdb;
        if (!$token) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('mobile_devices') . " WHERE access_token_hash=%s AND status='active' LIMIT 1", self::token_hash($token)));
    }

    private static function find_device_by_refresh_token($token) {
        global $wpdb;
        if (!$token) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('mobile_devices') . " WHERE refresh_token_hash=%s AND status='active' LIMIT 1", self::token_hash($token)));
    }

    private static function find_device_by_bearer_or_refresh($request) {
        $device = self::find_device_by_access_token(self::get_bearer_token($request));
        if ($device) return $device;
        $p = self::json($request);
        $refresh = sanitize_text_field($p['refresh_token'] ?? '');
        return self::find_device_by_refresh_token($refresh);
    }

    private static function authenticate_mobile_for_revoke($request) {
        if (!self::api_enabled()) return new WP_Error('dbsd_mobile_disabled', __('Mobile API is disabled.', 'datebook-safedate'), array('status' => 503));
        if (!self::ip_rate_limit('/mobile/revoke-device', max(20, absint(get_option('dbsd_mobile_rate_limit_per_minute', 60))))) {
            self::security_event(null, null, null, 'ip_rate_limited', 'warning', array('route' => '/mobile/revoke-device'));
            return new WP_Error('dbsd_rate_limited', __('Too many requests from this network. Please slow down.', 'datebook-safedate'), array('status' => 429));
        }
        $device = self::find_device_by_bearer_or_refresh($request);
        if (!$device) {
            self::security_event(null, null, null, 'invalid_revoke_token', 'warning', array());
            return new WP_Error('dbsd_mobile_unauthorized', __('Invalid mobile revoke credentials.', 'datebook-safedate'), array('status' => 401));
        }
        $p = self::json($request);
        if (!empty($p['refresh_token']) && !hash_equals((string)$device->refresh_token_hash, (string)self::token_hash(sanitize_text_field($p['refresh_token'])))) {
            self::security_event((int)$device->id, (int)$device->user_id, null, 'revoke_refresh_mismatch', 'critical', array());
            return new WP_Error('dbsd_bad_refresh', __('Invalid refresh token for revoke.', 'datebook-safedate'), array('status' => 401));
        }
        $sig = self::verify_signature($request, $device, 0);
        if (is_wp_error($sig)) return $sig;
        return $device;
    }

    private static function rate_limit($identity, $route, $limit) {
        global $wpdb;
        $limit = max(1, absint($limit));
        $window = floor(time() / 60) * 60;
        $identity_hash = hash('sha256', (string)$identity . '|' . wp_salt('nonce'));
        $table = self::table('mobile_rate_limits');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE identity_hash=%s AND route=%s AND window_start=%d", $identity_hash, $route, $window));
        if ($row) {
            if ((int)$row->request_count >= $limit) return false;
            $wpdb->update($table, array('request_count' => (int)$row->request_count + 1, 'updated_at' => current_time('mysql', true)), array('id' => (int)$row->id));
        } else {
            $wpdb->insert($table, array('identity_hash' => $identity_hash, 'route' => sanitize_text_field(substr($route, 0, 190)), 'window_start' => $window, 'request_count' => 1, 'updated_at' => current_time('mysql', true)));
        }
        return true;
    }


    private static function ip_rate_limit($route, $limit = 30) {
        $ip = self::client_ip();
        if (!$ip) $ip = 'unknown';
        return self::rate_limit('ip:' . $ip, $route, $limit);
    }

    private static function authenticate_mobile($request, $require_signature = false, $session_id = 0) {
        global $wpdb;
        if (!self::api_enabled()) return new WP_Error('dbsd_mobile_disabled', __('Mobile API is disabled.', 'datebook-safedate'), array('status' => 503));
        if (!self::ip_rate_limit($request->get_route(), max(20, absint(get_option('dbsd_mobile_rate_limit_per_minute', 60))))) {
            self::security_event(null, null, $session_id, 'ip_rate_limited', 'warning', array('route' => $request->get_route()));
            return new WP_Error('dbsd_rate_limited', __('Too many requests from this network. Please slow down.', 'datebook-safedate'), array('status' => 429));
        }
        $token = self::get_bearer_token($request);
        $device = self::find_device_by_access_token($token);
        if (!$device) {
            self::security_event(null, null, $session_id, 'invalid_access_token', 'warning', array('route' => $request->get_route()));
            return new WP_Error('dbsd_mobile_unauthorized', __('Invalid mobile access token.', 'datebook-safedate'), array('status' => 401));
        }
        if (strtotime($device->access_expires_at . ' UTC') < time()) {
            self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'expired_access_token', 'info', array());
            return new WP_Error('dbsd_mobile_token_expired', __('Mobile access token expired. Refresh required.', 'datebook-safedate'), array('status' => 401));
        }
        $route = $request->get_route();
        $limit = strpos($route, '/location/') !== false ? get_option('dbsd_mobile_location_rate_limit_per_minute', 30) : get_option('dbsd_mobile_rate_limit_per_minute', 60);
        if (!self::rate_limit('device:' . $device->id, $route, $limit)) {
            self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'rate_limited', 'warning', array('route' => $route));
            return new WP_Error('dbsd_rate_limited', __('Too many mobile API requests. Please slow down.', 'datebook-safedate'), array('status' => 429));
        }
        if ($require_signature) {
            $sig = self::verify_signature($request, $device, $session_id);
            if (is_wp_error($sig)) return $sig;
        }
        $wpdb->update(self::table('mobile_devices'), array('last_seen_at' => current_time('mysql', true), 'last_ip' => self::client_ip(), 'last_user_agent' => self::user_agent(), 'updated_at' => current_time('mysql', true)), array('id' => (int)$device->id));
        return $device;
    }

    private static function verify_signature($request, $device, $session_id = 0) {
        global $wpdb;
        $timestamp = $request->get_header('x-dbsd-timestamp');
        $nonce = $request->get_header('x-dbsd-nonce');
        $signature = $request->get_header('x-dbsd-signature');
        $device_header = $request->get_header('x-dbsd-device-id');
        if (!$timestamp || !$nonce || !$signature || !$device_header) {
            self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'missing_signature_headers', 'warning', array());
            return new WP_Error('dbsd_signature_missing', __('Missing mobile signature headers.', 'datebook-safedate'), array('status' => 401));
        }
        if (!hash_equals((string)$device->device_uuid, (string)$device_header)) {
            self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'device_id_mismatch', 'warning', array());
            return new WP_Error('dbsd_device_mismatch', __('Mobile device header mismatch.', 'datebook-safedate'), array('status' => 401));
        }
        $timestamp_int = (int)$timestamp;
        $window = max(60, absint(get_option('dbsd_mobile_signature_window_seconds', self::SIGNATURE_WINDOW)));
        if (abs(time() - $timestamp_int) > $window) {
            self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'signature_timestamp_out_of_window', 'warning', array('timestamp' => $timestamp_int));
            return new WP_Error('dbsd_signature_stale', __('Mobile signature timestamp is outside the accepted window.', 'datebook-safedate'), array('status' => 401));
        }
        $nonce_hash = hash('sha256', (string)$nonce . '|' . (int)$device->id . '|' . wp_salt('nonce'));
        $nonce_exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('mobile_nonces') . " WHERE device_id=%d AND nonce_hash=%s", (int)$device->id, $nonce_hash));
        if ($nonce_exists) {
            self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'replay_detected', 'critical', array('nonce_hash' => $nonce_hash));
            return new WP_Error('dbsd_replay_detected', __('Replay protection rejected this request.', 'datebook-safedate'), array('status' => 409));
        }
        $body = (string)$request->get_body();
        $body_hash = hash('sha256', $body);
        $canonical = strtoupper($request->get_method()) . "\n" . $request->get_route() . "\n" . $timestamp_int . "\n" . $nonce . "\n" . $body_hash;
        $secret = self::unseal_secret($device->signing_secret_sealed);
        $expected = base64_encode(hash_hmac('sha256', $canonical, $secret, true));
        if (!$secret || !hash_equals($expected, (string)$signature)) {
            self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'bad_signature', 'critical', array('route' => $request->get_route()));
            return new WP_Error('dbsd_bad_signature', __('Invalid mobile request signature.', 'datebook-safedate'), array('status' => 401));
        }
        $wpdb->insert(self::table('mobile_nonces'), array('device_id' => (int)$device->id, 'nonce_hash' => $nonce_hash, 'request_timestamp' => $timestamp_int, 'route' => sanitize_text_field(substr($request->get_route(), 0, 190)), 'created_at' => current_time('mysql', true)));
        return true;
    }

    private static function get_session($session_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('sessions') . " WHERE id=%d", absint($session_id)));
    }

    private static function mobile_can_access_session($device, $session) {
        if (!$device || !$session) return false;
        return (int)$session->host_user_id === (int)$device->user_id || (int)$session->traveler_user_id === (int)$device->user_id;
    }

    private static function mysql_datetime($value) {
        $value = sanitize_text_field((string)$value);
        if (!$value) return current_time('mysql', true);
        return str_replace('T', ' ', substr($value, 0, 19));
    }

    public static function register_device($request) {
        global $wpdb;
        if (!self::api_enabled()) return new WP_Error('dbsd_mobile_disabled', __('Mobile API is disabled.', 'datebook-safedate'), array('status' => 503));
        $p = self::json($request);
        $user_id = get_current_user_id();
        if (!self::ip_rate_limit('/mobile/register-device', 20)) return new WP_Error('dbsd_rate_limited', __('Too many device registration attempts from this network.', 'datebook-safedate'), array('status' => 429));
        $device_uuid = sanitize_text_field($p['device_uuid'] ?? '');
        if (!$device_uuid || strlen($device_uuid) < 8) return new WP_Error('dbsd_bad_device_uuid', __('A stable device UUID is required.', 'datebook-safedate'), array('status' => 400));
        if (!self::rate_limit('user:' . $user_id, '/mobile/register-device', 10)) return new WP_Error('dbsd_rate_limited', __('Too many device registration attempts.', 'datebook-safedate'), array('status' => 429));
        $access = self::random_token(32);
        $refresh = self::random_token(40);
        $secret = self::random_token(32);
        $sealed_secret = self::seal_secret($secret);
        if (is_wp_error($sealed_secret)) return $sealed_secret;
        $now = current_time('mysql', true);
        $data = array(
            'user_id' => $user_id,
            'device_uuid' => $device_uuid,
            'platform' => sanitize_key($p['platform'] ?? 'unknown'),
            'device_name' => sanitize_text_field($p['device_name'] ?? ''),
            'app_version' => sanitize_text_field($p['app_version'] ?? ''),
            'access_token_hash' => self::token_hash($access),
            'refresh_token_hash' => self::token_hash($refresh),
            'signing_secret_sealed' => $sealed_secret,
            'status' => 'active',
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL),
            'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL),
            'last_seen_at' => $now,
            'last_ip' => self::client_ip(),
            'last_user_agent' => self::user_agent(),
            'created_at' => $now,
            'updated_at' => $now,
            'revoked_at' => null,
        );
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM " . self::table('mobile_devices') . " WHERE user_id=%d AND device_uuid=%s", $user_id, $device_uuid));
        if ($existing) {
            $wpdb->update(self::table('mobile_devices'), $data, array('id' => (int)$existing->id));
            $device_id = (int)$existing->id;
        } else {
            $wpdb->insert(self::table('mobile_devices'), $data);
            $device_id = (int)$wpdb->insert_id;
        }
        self::security_event($device_id, $user_id, null, 'device_registered', 'info', array('platform' => $data['platform'], 'app_version' => $data['app_version']));
        return array('ok' => true, 'device_id' => $device_id, 'device_uuid' => $device_uuid, 'access_token' => $access, 'refresh_token' => $refresh, 'signing_secret' => $secret, 'access_expires_at' => $data['access_expires_at'], 'refresh_expires_at' => $data['refresh_expires_at'], 'signature_algorithm' => 'HMAC-SHA256-Base64', 'canonical_format' => "METHOD\\nROUTE\\nTIMESTAMP\\nNONCE\\nSHA256_RAW_BODY");
    }


    private static function refresh_history_lookup($refresh_token_hash) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('mobile_refresh_history') . " WHERE refresh_token_hash=%s LIMIT 1", $refresh_token_hash));
    }

    private static function remember_refresh_hash($device, $refresh_token_hash) {
        global $wpdb;
        if (!$device || !$refresh_token_hash) return;
        $wpdb->replace(self::table('mobile_refresh_history'), array(
            'device_id' => (int)$device->id,
            'user_id' => (int)$device->user_id,
            'refresh_token_hash' => $refresh_token_hash,
            'replaced_at' => current_time('mysql', true),
            'expires_at' => $device->refresh_expires_at,
            'reused_at' => null,
        ));
    }

    public static function refresh_token($request) {
        global $wpdb;
        $p = self::json($request);
        if (!self::ip_rate_limit('/mobile/refresh-token', 30)) return new WP_Error('dbsd_rate_limited', __('Too many refresh attempts from this network.', 'datebook-safedate'), array('status' => 429));
        $refresh = sanitize_text_field($p['refresh_token'] ?? '');
        $refresh_hash = self::token_hash($refresh);
        $device_uuid = sanitize_text_field($p['device_uuid'] ?? $request->get_header('x-dbsd-device-id'));
        $device = self::find_device_by_refresh_token($refresh);
        if (!$device) {
            $old = self::refresh_history_lookup($refresh_hash);
            if ($old) {
                $wpdb->update(self::table('mobile_refresh_history'), array('reused_at' => current_time('mysql', true)), array('id' => (int)$old->id));
                self::security_event((int)$old->device_id, (int)$old->user_id, null, 'refresh_token_reuse_detected', 'critical', array());
                return new WP_Error('dbsd_refresh_reused', __('Refresh token reuse detected. Re-pair this device.', 'datebook-safedate'), array('status' => 401));
            }
            return new WP_Error('dbsd_bad_refresh', __('Invalid refresh token.', 'datebook-safedate'), array('status' => 401));
        }
        if (!$device_uuid || !hash_equals((string)$device->device_uuid, (string)$device_uuid)) {
            self::security_event((int)$device->id, (int)$device->user_id, null, 'refresh_device_mismatch', 'critical', array());
            return new WP_Error('dbsd_refresh_device_mismatch', __('Refresh token is not bound to this device.', 'datebook-safedate'), array('status' => 401));
        }
        if (get_option('dbsd_mobile_signed_refresh_required', 'yes') === 'yes') {
            $sig = self::verify_signature($request, $device, 0);
            if (is_wp_error($sig)) return $sig;
        }
        if (strtotime($device->refresh_expires_at . ' UTC') < time()) return new WP_Error('dbsd_refresh_expired', __('Refresh token expired. Please re-pair the device.', 'datebook-safedate'), array('status' => 401));
        if (!self::rate_limit('device:' . $device->id, '/mobile/refresh-token', 10)) return new WP_Error('dbsd_rate_limited', __('Too many refresh attempts.', 'datebook-safedate'), array('status' => 429));
        $access = self::random_token(32);
        $new_refresh = self::random_token(40);
        self::remember_refresh_hash($device, $refresh_hash);
        $wpdb->update(self::table('mobile_devices'), array('access_token_hash' => self::token_hash($access), 'refresh_token_hash' => self::token_hash($new_refresh), 'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL), 'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL), 'last_seen_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)), array('id' => (int)$device->id));
        self::security_event((int)$device->id, (int)$device->user_id, null, 'token_refreshed', 'info', array());
        return array('ok' => true, 'access_token' => $access, 'refresh_token' => $new_refresh, 'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL), 'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL));
    }

    public static function revoke_device($request) {
        global $wpdb;
        $device = self::authenticate_mobile_for_revoke($request);
        if (is_wp_error($device)) return $device;
        $wpdb->update(self::table('mobile_devices'), array('status' => 'revoked', 'revoked_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)), array('id' => (int)$device->id));
        self::security_event((int)$device->id, (int)$device->user_id, null, 'device_revoked', 'info', array('accepted_expired_access_token_with_valid_signature' => true));
        return array('ok' => true, 'device_id' => (int)$device->id, 'status' => 'revoked');
    }

    public static function mobile_whoami($request) {
        $device = self::authenticate_mobile($request, false);
        if (is_wp_error($device)) return $device;
        return array('ok' => true, 'user_id' => (int)$device->user_id, 'device_id' => (int)$device->id, 'device_uuid' => $device->device_uuid, 'platform' => $device->platform, 'access_expires_at' => $device->access_expires_at);
    }

    public static function signed_location_ping($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $device = self::authenticate_mobile($request, true, $session_id);
        if (is_wp_error($device)) return $device;
        $session = self::get_session($session_id);
        if (!self::mobile_can_access_session($device, $session)) return new WP_Error('dbsd_forbidden', __('This mobile device cannot access the SafeDate session.', 'datebook-safedate'), array('status' => 403));
        if ((int)$session->traveler_user_id !== (int)$device->user_id) return new WP_Error('dbsd_only_traveler', __('Only the traveler device can submit signed location.', 'datebook-safedate'), array('status' => 403));
        if (class_exists('DBSD_State')) { $guard = DBSD_State::assert_transition($session, DBSD_State::role($session, (int)$device->user_id), 'location_ping'); if (is_wp_error($guard)) return $guard; }
        $lat = isset($p['lat']) ? floatval($p['lat']) : null;
        $lng = isset($p['lng']) ? floatval($p['lng']) : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return new WP_Error('dbsd_bad_location', __('Invalid location.', 'datebook-safedate'), array('status' => 400));
        $recorded_at = self::mysql_datetime($p['recorded_at'] ?? current_time('mysql', true));
        $wpdb->insert(self::table('locations'), array(
            'session_id' => $session_id,
            'user_id' => (int)$device->user_id,
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
        DBSD_Audit::log_event($session_id, (int)$device->user_id, 'mobile_signed_location_ping', array('device_id' => (int)$device->id, 'accuracy' => $p['accuracy'] ?? null, 'location_recorded' => true));
        self::security_event((int)$device->id, (int)$device->user_id, $session_id, 'signed_location_accepted', 'info', array('accuracy' => $p['accuracy'] ?? null));
        if (class_exists('DBSD_V040')) DBSD_V040::live_event($session_id, (int)$device->user_id, 'mobile_location_ping', 'info', 'Signed native location ping received.');
        return array('ok' => true, 'location_id' => (int)$wpdb->insert_id, 'recorded_at' => $recorded_at);
    }

    public static function signed_device_health($request) {
        global $wpdb;
        $p = self::json($request);
        $session_id = absint($p['session_id'] ?? 0);
        $device = self::authenticate_mobile($request, true, $session_id);
        if (is_wp_error($device)) return $device;
        $session = self::get_session($session_id);
        if (!self::mobile_can_access_session($device, $session)) return new WP_Error('dbsd_forbidden', __('This mobile device cannot access the SafeDate session.', 'datebook-safedate'), array('status' => 403));
        $wpdb->insert(self::table('device_health'), array(
            'session_id' => $session_id,
            'user_id' => (int)$device->user_id,
            'online_status' => sanitize_key($p['online_status'] ?? 'online'),
            'battery_level' => isset($p['battery_level']) ? floatval($p['battery_level']) : null,
            'charging' => isset($p['charging']) ? (int)(bool)$p['charging'] : null,
            'permission_state' => sanitize_key($p['permission_state'] ?? ''),
            'network_type' => sanitize_text_field($p['network_type'] ?? ''),
            'user_agent' => self::user_agent(),
            'recorded_at' => self::mysql_datetime($p['recorded_at'] ?? current_time('mysql', true)),
        ));
        DBSD_Audit::log_event($session_id, (int)$device->user_id, 'mobile_signed_device_health', array('device_id' => (int)$device->id, 'permission_state' => $p['permission_state'] ?? null));
        return array('ok' => true, 'health_id' => (int)$wpdb->insert_id);
    }

    public static function mobile_safety_status($request) {
        $session_id = absint($request['id']);
        $device = self::authenticate_mobile($request, false, $session_id);
        if (is_wp_error($device)) return $device;
        $session = self::get_session($session_id);
        if (!self::mobile_can_access_session($device, $session)) return new WP_Error('dbsd_forbidden', __('This mobile device cannot access the SafeDate session.', 'datebook-safedate'), array('status' => 403));
        if (class_exists('DBSD_V050')) {
            $fake = new WP_REST_Request('GET', '/datebook-safedate/v1/session/' . $session_id . '/safety-status');
            $fake->set_url_params(array('id' => $session_id));
        }
        global $wpdb;
        $latest_location = $wpdb->get_row($wpdb->prepare("SELECT recorded_at, accuracy, speed, heading FROM " . self::table('locations') . " WHERE session_id=%d ORDER BY recorded_at DESC LIMIT 1", $session_id));
        $pending_checkins = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('checkins') . " WHERE session_id=%d AND requested_for=%d AND status IN ('pending','overdue')", $session_id, (int)$device->user_id));
        return array('ok' => true, 'session_id' => $session_id, 'status' => $session ? $session->status : null, 'alert_level' => $session ? $session->alert_level : null, 'latest_location_meta' => $latest_location, 'pending_checkins_for_me' => $pending_checkins, 'server_time' => current_time('mysql', true));
    }

    public static function admin_mobile_security() {
        global $wpdb;
        $devices = $wpdb->get_results("SELECT id, user_id, device_uuid, platform, device_name, app_version, status, access_expires_at, refresh_expires_at, last_seen_at, last_ip, created_at, revoked_at FROM " . self::table('mobile_devices') . " ORDER BY updated_at DESC LIMIT 50");
        $events = $wpdb->get_results("SELECT * FROM " . self::table('mobile_security_events') . " ORDER BY id DESC LIMIT 100");
        $counts = array(
            'active_devices' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('mobile_devices') . " WHERE status='active'"),
            'revoked_devices' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('mobile_devices') . " WHERE status='revoked'"),
            'critical_security_events_24h' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('mobile_security_events') . " WHERE severity='critical' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"),
            'rate_limited_24h' => (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('mobile_security_events') . " WHERE event_type='rate_limited' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"),
        );
        return array('ok' => true, 'counts' => $counts, 'devices' => $devices, 'events' => $events);
    }


    public static function admin_revoke_device($request) {
        global $wpdb;
        $p = self::json($request);
        $device_id = absint($p['device_id'] ?? 0);
        $reason = sanitize_textarea_field($p['reason'] ?? 'admin_revocation');
        if (!$device_id) return new WP_Error('dbsd_missing_device', __('Missing device_id.', 'datebook-safedate'), array('status' => 400));
        $device = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('mobile_devices') . " WHERE id=%d", $device_id));
        if (!$device) return new WP_Error('dbsd_missing_device', __('Mobile device not found.', 'datebook-safedate'), array('status' => 404));
        $wpdb->update(self::table('mobile_devices'), array('status' => 'revoked', 'revoked_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)), array('id' => $device_id));
        self::security_event($device_id, (int)$device->user_id, null, 'device_revoked_by_admin', 'warning', array('reason' => $reason));
        return array('ok' => true, 'device_id' => $device_id, 'status' => 'revoked');
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'Mobile API Security', 'Mobile API v0.6', 'dbsd_manage_devices', 'dbsd-mobile-security', array(__CLASS__, 'admin_page'));
    }

    public static function admin_page() {
        if (!current_user_can('dbsd_manage_devices') && !current_user_can('dbsd_manage_safety')) return;
        if (!empty($_POST['dbsd_admin_revoke_device_id']) && check_admin_referer('dbsd_admin_revoke_device')) {
            $req = new WP_REST_Request('POST', '/datebook-safedate/v1/admin/mobile/revoke-device');
            $req->set_body_params(array('device_id' => absint($_POST['dbsd_admin_revoke_device_id']), 'reason' => sanitize_textarea_field($_POST['dbsd_revoke_reason'] ?? 'admin_ui')));
            $result = self::admin_revoke_device($req);
            echo '<div class="notice notice-success"><p>' . esc_html(is_wp_error($result) ? $result->get_error_message() : __('Device revoked.', 'datebook-safedate')) . '</p></div>';
        }
        global $wpdb;
        $data = self::admin_mobile_security();
        echo '<div class="wrap"><h1>SafeDate Mobile API Security v0.6</h1>';
        echo '<p>Native app API hardening: mobile tokens, signed payloads, replay protection, and rate limiting.</p>';
        echo '<h2>Counts</h2><table class="widefat striped"><tbody>';
        foreach ($data['counts'] as $k => $v) echo '<tr><th>' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
        echo '</tbody></table><h2>Recent devices</h2><table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Platform</th><th>Device</th><th>Status</th><th>Last seen</th><th>Admin action</th></tr></thead><tbody>';
        foreach ($data['devices'] as $d) {
            $action = '—';
            if ($d->status === 'active') {
                $action = '<form method="post" style="display:flex;gap:6px;align-items:center">' . wp_nonce_field('dbsd_admin_revoke_device', '_wpnonce', true, false) . '<input type="hidden" name="dbsd_admin_revoke_device_id" value="' . esc_attr($d->id) . '"><input type="text" name="dbsd_revoke_reason" placeholder="Reason" class="small-text" style="width:120px"><button class="button button-small button-link-delete" type="submit">Revoke</button></form>';
            }
            echo '<tr><td>' . esc_html($d->id) . '</td><td>' . esc_html($d->user_id) . '</td><td>' . esc_html($d->platform) . '</td><td>' . esc_html($d->device_name ?: $d->device_uuid) . '</td><td>' . esc_html($d->status) . '</td><td>' . esc_html($d->last_seen_at) . '</td><td>' . $action . '</td></tr>';
        }
        echo '</tbody></table><h2>Recent security events</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Severity</th><th>Type</th><th>User</th><th>Device</th><th>Session</th></tr></thead><tbody>';
        foreach ($data['events'] as $e) echo '<tr><td>' . esc_html($e->created_at) . '</td><td>' . esc_html($e->severity) . '</td><td>' . esc_html($e->event_type) . '</td><td>' . esc_html($e->user_id) . '</td><td>' . esc_html($e->device_id) . '</td><td>' . esc_html($e->session_id) . '</td></tr>';
        echo '</tbody></table></div>';
    }

    public static function mobile_pairing_shortcode() {
        if (!is_user_logged_in()) return '<p>Please log in to pair a native SafeDate mobile app.</p>';
        $nonce = wp_create_nonce('wp_rest');
        $rest = esc_url_raw(rest_url('datebook-safedate/v1'));
        return '<div class="dbsd-card"><h3>Native App Pairing</h3><p>Use the native app to log in through this WordPress account, then register the device against the mobile API.</p><p><strong>REST base:</strong> <code>' . esc_html($rest) . '</code></p><p><strong>Web nonce for device registration:</strong> <code>' . esc_html($nonce) . '</code></p><p class="dbsd-muted">The native app must store returned tokens and signing secret in Android Keystore or iOS Keychain.</p></div>';
    }

    public static function cleanup() {
        global $wpdb;
        $retention = max(5, absint(get_option('dbsd_mobile_replay_retention_minutes', 20)));
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::table('mobile_nonces') . " WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)", $retention));
        $wpdb->query("DELETE FROM " . self::table('mobile_rate_limits') . " WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)");
        $wpdb->query("DELETE FROM " . self::table('mobile_devices') . " WHERE status='revoked' AND revoked_at IS NOT NULL AND revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)");
        $wpdb->query("DELETE FROM " . self::table('mobile_refresh_history') . " WHERE expires_at < UTC_TIMESTAMP()");
    }
}
