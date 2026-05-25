<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.7.8 CI/pairing behavioral hardening support.
 * Adds production native app pairing endpoints, Web Push readiness enforcement,
 * dedicated operational capabilities, service-worker diagnostics, and helper
 * methods that can be verified by behavioral PHPUnit tests.
 */
class DBSD_V075 {
    const VERSION = '0.7.10';
    const PAIR_TTL = 600;
    const ACCESS_TTL = 86400;
    const REFRESH_TTL = 2592000;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 2);
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 70);
        add_action('admin_notices', array(__CLASS__, 'admin_notices'));
        add_shortcode('db_safedate_mobile_pair_code', array(__CLASS__, 'pair_code_shortcode'));
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }
    private static function json($request) { $p = $request->get_json_params(); return is_array($p) ? $p : array(); }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v075_version', '0');
        if (version_compare($current, self::VERSION, '>=')) return;
        self::install_tables();
        self::grant_caps();
        add_option('dbsd_pairing_code_ttl_seconds', self::PAIR_TTL);
        add_option('dbsd_pairing_rate_limit_per_minute', 10);
        add_option('dbsd_pairing_max_attempts', 5);
        add_option('dbsd_pairing_max_codes_per_user_hour', 5);
        add_option('dbsd_pairing_max_codes_per_ip_hour', 20);
        add_option('dbsd_pairing_max_failed_per_device_hour', 10);
        add_option('dbsd_pairing_max_bad_pairing_ids_per_ip_hour', 30);
        add_option('dbsd_require_webpush_library_for_production', 'yes');
        update_option('dbsd_v075_version', self::VERSION);
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('mobile_pairing_codes') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            code_hash CHAR(64) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            platform VARCHAR(40) NULL,
            device_uuid VARCHAR(128) NULL,
            device_name VARCHAR(190) NULL,
            created_ip VARCHAR(100) NULL,
            claimed_ip VARCHAR(100) NULL,
            expires_at DATETIME NOT NULL,
            claimed_at DATETIME NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            locked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code_hash (code_hash),
            KEY user_id (user_id),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY locked_at (locked_at)
        ) $charset;");
    }

    public static function grant_caps() {
        $caps = array('dbsd_view_safety', 'dbsd_manage_incidents', 'dbsd_manage_devices', 'dbsd_export_safety', 'dbsd_manage_settings', 'dbsd_manage_safety');
        $role = get_role('administrator');
        if ($role) foreach ($caps as $cap) if (!$role->has_cap($cap)) $role->add_cap($cap);
    }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/mobile/pairing-code', array('methods' => 'POST', 'callback' => array(__CLASS__, 'create_pairing_code'), 'permission_callback' => function(){ return is_user_logged_in(); }));
        register_rest_route($ns, '/mobile/pair-device', array('methods' => 'POST', 'callback' => array(__CLASS__, 'claim_pairing_code'), 'permission_callback' => '__return_true'));
        register_rest_route($ns, '/mobile/pairing-status/(?P<id>\d+)', array('methods' => 'GET', 'callback' => array(__CLASS__, 'pairing_status'), 'permission_callback' => function(){ return is_user_logged_in(); }));
        register_rest_route($ns, '/pwa/service-worker-status', array('methods' => 'GET', 'callback' => array(__CLASS__, 'service_worker_status'), 'permission_callback' => array(__CLASS__, 'can_manage_settings')));
        register_rest_route($ns, '/push/readiness', array('methods' => 'GET', 'callback' => array(__CLASS__, 'push_readiness'), 'permission_callback' => array(__CLASS__, 'can_manage_settings')));
    }

    public static function can_manage_settings() { return current_user_can('dbsd_manage_settings') || current_user_can('dbsd_manage_safety'); }
    public static function can_manage_devices() { return current_user_can('dbsd_manage_devices') || current_user_can('dbsd_manage_safety'); }

    private static function client_ip() { return class_exists('DBSD_Audit') ? DBSD_Audit::client_ip() : sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''); }
    private static function code_hash($code) { return hash('sha256', strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code)) . '|' . wp_salt('nonce')); }
    private static function token_hash($token) { return hash('sha256', (string)$token . '|' . wp_salt('auth')); }
    private static function crypto_key() { return hash('sha256', wp_salt('secure_auth') . '|' . wp_salt('auth'), true); }
    private static function random_token($bytes = 32) { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }

    private static function normalize_pairing_code($code) {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code));
    }

    private static function increment_pairing_attempt($pairing_id) {
        global $wpdb;
        $max = max(3, min(20, absint(get_option('dbsd_pairing_max_attempts', 5))));
        $table = self::table('mobile_pairing_codes');
        $wpdb->query($wpdb->prepare("UPDATE $table SET attempt_count=attempt_count+1, locked_at=CASE WHEN attempt_count + 1 >= %d THEN %s ELSE locked_at END WHERE id=%d AND status='pending'", $max, current_time('mysql', true), (int)$pairing_id));
    }


    private static function pairing_recent_count($where_sql, array $args) {
        global $wpdb;
        $table = self::table('mobile_pairing_codes');
        $since = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        array_unshift($args, $since);
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE created_at >= %s AND " . $where_sql, $args));
    }

    private static function pairing_creation_allowed_for_user($user_id) {
        $max_user = max(1, min(100, absint(get_option('dbsd_pairing_max_codes_per_user_hour', 5))));
        $max_ip = max(1, min(500, absint(get_option('dbsd_pairing_max_codes_per_ip_hour', 20))));
        $user_count = self::pairing_recent_count('user_id=%d', array((int) $user_id));
        $ip_count = self::pairing_recent_count('created_ip=%s', array(self::client_ip()));
        if ($user_count >= $max_user || $ip_count >= $max_ip) {
            if (class_exists('DBSD_V060')) {
                DBSD_V060::security_event(null, (int) $user_id, null, 'pairing_creation_rate_limited', 'warning', array('user_count' => $user_count, 'ip_count' => $ip_count, 'max_user' => $max_user, 'max_ip' => $max_ip));
            }
            if (class_exists('DBSD_V078')) {
                DBSD_V078::record_pairing_attempt(0, (int) $user_id, '', 'blocked', 'pairing_creation_rate_limited', array('user_count' => $user_count, 'ip_count' => $ip_count, 'ip' => self::client_ip()));
            }
            return false;
        }
        return true;
    }

    private static function pairing_device_abuse_allowed($device_uuid) {
        global $wpdb;
        $device_uuid = sanitize_text_field($device_uuid);
        if (!$device_uuid) return false;
        $max = max(1, min(100, absint(get_option('dbsd_pairing_max_failed_per_device_hour', 10))));
        $since = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        if (class_exists('DBSD_V078')) {
            $attempts = self::table('mobile_pairing_attempts');
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $attempts WHERE created_at >= %s AND device_uuid=%s AND result IN ('failed','rejected','bad_request','blocked')",
                $since,
                $device_uuid
            ));
            return $count < $max;
        }
        $table = self::table('mobile_pairing_codes');
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE created_at >= %s AND device_uuid=%s AND status IN ('failed','locked')", $since, $device_uuid));
        return $count < $max;
    }

    private static function mark_pairing_locked_if_needed($pairing_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT attempt_count, locked_at FROM ' . self::table('mobile_pairing_codes') . ' WHERE id=%d', (int) $pairing_id));
        if ($row && !empty($row->locked_at)) {
            $wpdb->update(self::table('mobile_pairing_codes'), array('status' => 'locked'), array('id' => (int) $pairing_id, 'status' => 'pending'));
            if (class_exists('DBSD_V060')) {
                DBSD_V060::security_event(null, null, null, 'pairing_code_locked', 'warning', array('pairing_id' => (int) $pairing_id, 'attempt_count' => (int) $row->attempt_count));
            }
        }
    }

    private static function claim_pairing_row_atomically($pairing_id, $code_hash, $device_uuid, $platform, $device_name) {
        global $wpdb;
        $table = self::table('mobile_pairing_codes');
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status='claiming', device_uuid=%s, platform=%s, device_name=%s, claimed_ip=%s, claimed_at=%s WHERE id=%d AND code_hash=%s AND status='pending' AND locked_at IS NULL AND expires_at >= %s",
            $device_uuid,
            $platform,
            $device_name,
            self::client_ip(),
            $now,
            (int)$pairing_id,
            $code_hash,
            $now
        ));
        return (int)$wpdb->rows_affected === 1;
    }

    private static function finalize_pairing_claim($pairing_id, $status = 'claimed') {
        global $wpdb;
        $wpdb->update(self::table('mobile_pairing_codes'), array('status' => $status, 'claimed_at' => current_time('mysql', true)), array('id' => (int)$pairing_id, 'status' => 'claiming'));
    }


    private static function seal_secret($secret) {
        $key = self::crypto_key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'sodium_secretbox:' . base64_encode($nonce . sodium_crypto_secretbox((string)$secret, $nonce, $key));
        }
        if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
            $iv = random_bytes(12); $tag = '';
            $cipher = openssl_encrypt((string)$secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher !== false) return 'aes256gcm:' . base64_encode($iv . $tag . $cipher);
        }
        return new WP_Error('dbsd_crypto_unavailable', __('Authenticated encryption is unavailable.', 'datebook-safedate'), array('status' => 500));
    }

    public static function create_pairing_code($request) {
        global $wpdb;
        if (class_exists('DBSD_V074') && !DBSD_V074::public_ip_rate_limit('/mobile/pairing-code', max(1, absint(get_option('dbsd_pairing_rate_limit_per_minute', 10))))) {
            if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt(0, get_current_user_id(), '', 'blocked', 'pairing_creation_ip_rate_limited', array('ip' => self::client_ip()));
            return new WP_Error('dbsd_rate_limited', __('Too many pairing-code requests.', 'datebook-safedate'), array('status' => 429));
        }
        if (!self::pairing_creation_allowed_for_user(get_current_user_id())) {
            return new WP_Error('dbsd_pairing_creation_limited', __('Too many pairing codes requested. Try again later.', 'datebook-safedate'), array('status' => 429));
        }
        $ttl = max(120, min(1800, absint(get_option('dbsd_pairing_code_ttl_seconds', self::PAIR_TTL))));
        $code = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars, 40 bits of entropy for short-lived pairing
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
        $wpdb->insert(self::table('mobile_pairing_codes'), array(
            'user_id' => get_current_user_id(),
            'code_hash' => self::code_hash($code),
            'status' => 'pending',
            'created_ip' => self::client_ip(),
            'expires_at' => $expires,
            'created_at' => $now,
        ));
        $pairing_id = (int)$wpdb->insert_id;
        if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, get_current_user_id(), '', 'created', 'pairing_code_created', array('ip' => self::client_ip()));
        return array('ok' => true, 'pairing_id' => $pairing_id, 'code' => $code, 'expires_at' => $expires, 'ttl_seconds' => $ttl, 'code_format' => '10_HEX_CHARS', 'requires_pairing_id' => true);
    }

    public static function claim_pairing_code($request) {
        global $wpdb;
        if (class_exists('DBSD_V074') && !DBSD_V074::public_ip_rate_limit('/mobile/pair-device', max(1, absint(get_option('dbsd_pairing_rate_limit_per_minute', 10))))) {
            return new WP_Error('dbsd_rate_limited', __('Too many pairing attempts.', 'datebook-safedate'), array('status' => 429));
        }
        $p = self::json($request);
        $pairing_id = absint($p['pairing_id'] ?? 0);
        $code = self::normalize_pairing_code($p['code'] ?? '');
        $device_uuid = sanitize_text_field($p['device_uuid'] ?? '');
        $platform = sanitize_key($p['platform'] ?? 'native');
        $device_name = sanitize_text_field($p['device_name'] ?? 'SafeDate native app');
        if (!$pairing_id || strlen($code) < 10 || !$device_uuid) {
            if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, 0, $device_uuid, 'bad_request', 'missing_pairing_fields', array('has_pairing_id' => (bool)$pairing_id, 'code_length' => strlen($code)));
            return new WP_Error('dbsd_pairing_bad_request', __('Pairing ID, pairing code, and device UUID are required.', 'datebook-safedate'), array('status' => 400));
        }
        if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, 0, $device_uuid, 'attempted', 'pair_device_attempt', array('platform' => $platform));
        if (!self::pairing_device_abuse_allowed($device_uuid)) {
            if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, 0, $device_uuid, 'blocked', 'device_pairing_abuse_limited', array());
            if (class_exists('DBSD_V060')) DBSD_V060::security_event(null, null, null, 'pairing_device_abuse_limited', 'warning', array('pairing_id' => $pairing_id, 'device_uuid_hash' => hash('sha256', $device_uuid . '|' . wp_salt('nonce'))));
            return new WP_Error('dbsd_pairing_device_limited', __('Too many failed pairing attempts for this device. Create a new pairing code or try later.', 'datebook-safedate'), array('status' => 429));
        }
        $table = self::table('mobile_pairing_codes');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1", $pairing_id));
        $now_ts = time();
        if (!$row || $row->status !== 'pending' || strtotime($row->expires_at . ' UTC') < $now_ts || !empty($row->locked_at)) {
            if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, $row ? (int)$row->user_id : 0, $device_uuid, 'rejected', 'invalid_expired_locked_or_used', array('row_status' => $row ? $row->status : 'missing'));
            return new WP_Error('dbsd_pairing_invalid', __('Pairing code is invalid, expired, locked, or already used.', 'datebook-safedate'), array('status' => 403));
        }
        $code_hash = self::code_hash($code);
        if (!hash_equals((string)$row->code_hash, (string)$code_hash)) {
            self::increment_pairing_attempt($pairing_id);
            self::mark_pairing_locked_if_needed($pairing_id);
            if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, (int)$row->user_id, $device_uuid, 'failed', 'wrong_code', array('attempt_count_after' => (int)$row->attempt_count + 1));
            return new WP_Error('dbsd_pairing_invalid', __('Pairing code is invalid, expired, locked, or already used.', 'datebook-safedate'), array('status' => 403));
        }
        if (!self::claim_pairing_row_atomically($pairing_id, $code_hash, $device_uuid, $platform, $device_name)) {
            if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, (int)$row->user_id, $device_uuid, 'rejected', 'race_or_already_claimed', array());
            return new WP_Error('dbsd_pairing_race', __('Pairing code was already claimed. Create a new pairing code.', 'datebook-safedate'), array('status' => 409));
        }
        $access = self::random_token(32);
        $refresh = self::random_token(40);
        $signing_secret = self::random_token(32);
        $sealed = self::seal_secret($signing_secret);
        if (is_wp_error($sealed)) {
            self::finalize_pairing_claim($pairing_id, 'failed');
            return $sealed;
        }
        $now = current_time('mysql', true);
        $device_table = self::table('mobile_devices');
        $wpdb->replace($device_table, array(
            'user_id' => (int)$row->user_id,
            'device_uuid' => $device_uuid,
            'platform' => $platform,
            'device_name' => $device_name,
            'app_version' => sanitize_text_field($p['app_version'] ?? ''),
            'access_token_hash' => self::token_hash($access),
            'refresh_token_hash' => self::token_hash($refresh),
            'signing_secret_sealed' => $sealed,
            'status' => 'active',
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL),
            'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL),
            'last_seen_at' => $now,
            'last_ip' => self::client_ip(),
            'last_user_agent' => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $device_id = (int)$wpdb->insert_id;
        if (!$device_id) {
            $device_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $device_table WHERE user_id=%d AND device_uuid=%s", (int)$row->user_id, $device_uuid));
        }
        self::finalize_pairing_claim($pairing_id, 'claimed');
        if (class_exists('DBSD_V078')) DBSD_V078::record_pairing_attempt($pairing_id, (int)$row->user_id, $device_uuid, 'claimed', 'pairing_claimed', array('device_id' => $device_id));
        return array(
            'ok' => true,
            'device_id' => $device_id,
            'user_id' => (int)$row->user_id,
            'device_uuid' => $device_uuid,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'signing_secret' => $signing_secret,
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL),
            'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL),
            'signature_algorithm' => 'HMAC-SHA256',
            'canonical_route_prefix' => '/datebook-safedate/v1',
        );
    }

    public static function pairing_status($request) {
        global $wpdb;
        $id = absint($request['id']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, user_id, status, platform, device_uuid, device_name, attempt_count, locked_at, expires_at, claimed_at, created_at FROM " . self::table('mobile_pairing_codes') . " WHERE id=%d", $id));
        if (!$row || (int)$row->user_id !== get_current_user_id()) return new WP_Error('dbsd_forbidden', __('Pairing code not found.', 'datebook-safedate'), array('status' => 404));
        return array('ok' => true, 'pairing' => $row);
    }

    public static function service_worker_status() {
        $path = parse_url(home_url('/dbsd-sw.js'), PHP_URL_PATH);
        return array(
            'ok' => true,
            'service_worker_url' => home_url('/dbsd-sw.js'),
            'expected_path' => $path,
            'rewrite_flushed_version' => get_option('dbsd_v040_rewrite_flushed_version', ''),
            'pwa_enabled' => get_option('dbsd_pwa_enabled', 'yes'),
            'asset_readable' => is_readable(DBSD_PLUGIN_DIR . 'assets/js/dbsd-sw.js'),
            'service_worker_allowed_scope' => parse_url(home_url('/'), PHP_URL_PATH) ?: '/',
        );
    }

    public static function push_readiness() {
        $has_vapid = get_option('dbsd_push_vapid_public_key', '') && get_option('dbsd_push_vapid_private_key', '') && get_option('dbsd_push_vapid_subject', '');
        $has_library = class_exists('Minishlink\\WebPush\\WebPush');
        $ready = get_option('dbsd_push_enabled', 'yes') === 'yes' && $has_vapid && $has_library;
        return array(
            'ok' => true,
            'ready_for_background_push' => (bool)$ready,
            'push_enabled' => get_option('dbsd_push_enabled', 'yes'),
            'vapid_keys_configured' => (bool)$has_vapid,
            'webpush_library_loaded' => (bool)$has_library,
            'fallback_mode' => $ready ? 'server_web_push' : 'browser_polling_only',
            'warnings' => array_values(array_filter(array(
                !$has_vapid ? 'VAPID public/private key and subject are required for production Web Push.' : '',
                !$has_library ? 'Install minishlink/web-push with Composer for server-sent background Web Push.' : '',
            ))),
        );
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'QA Hardening v0.7.10', 'QA Hardening v0.7.10', 'dbsd_manage_settings', 'dbsd-v075', array(__CLASS__, 'admin_page'));
    }

    public static function admin_notices() {
        if (!is_admin() || !current_user_can('dbsd_manage_settings')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string)$screen->id, 'dbsd') === false) return;
        $r = self::push_readiness();
        if (empty($r['ready_for_background_push'])) {
            echo '<div class="notice notice-warning"><p><strong>SafeDate Web Push is not production-ready:</strong> ' . esc_html(implode(' ', $r['warnings'])) . '</p></div>';
        }
    }

    public static function admin_page() {
        if (!current_user_can('dbsd_manage_settings')) return;
        echo '<div class="wrap"><h1>SafeDate QA Hardening v0.7.10</h1>';
        echo '<p>This release preserves the v0.7.10 senior QA blocker fixes with atomic pairing claims, pairing attempt lockout, native pairing starter updates, and expanded behavioral test coverage.</p>';
        echo '<h2>Web Push readiness</h2><pre>' . esc_html(wp_json_encode(self::push_readiness(), JSON_PRETTY_PRINT)) . '</pre>';
        echo '<h2>Service worker status</h2><pre>' . esc_html(wp_json_encode(self::service_worker_status(), JSON_PRETTY_PRINT)) . '</pre></div>';
    }

    public static function pair_code_shortcode() {
        if (!is_user_logged_in()) return '<p>Please sign in to pair a SafeDate mobile app.</p>';
        wp_enqueue_script('dbsd-frontend');
        return '<div class="dbsd-card"><h3>Pair Native SafeDate App</h3><p>Create a one-time code on this device, then enter the pairing ID and code in the Android/iOS SafeDate app within 10 minutes.</p><button type="button" class="dbsd-button" data-action="create-pairing-code" data-dbsd-pairing>Create pairing code</button><pre class="dbsd-result" data-dbsd-pairing-result></pre></div>';
    }
}
