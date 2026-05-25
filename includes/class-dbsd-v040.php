<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.4 PWA, push-subscription, and live monitoring layer.
 * Adds service worker registration helpers, PWA manifest route, browser notification polling,
 * Web Push subscription storage, admin live monitoring, and a provider-ready push adapter.
 */
class DBSD_V040 {
    const DB_VERSION = '0.7.4';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'));
        add_action('init', array(__CLASS__, 'rewrite_rules'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend'));
        add_action('wp_head', array(__CLASS__, 'pwa_head_tags'));
        add_action('template_redirect', array(__CLASS__, 'root_service_worker'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 30);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin'));
        add_action('admin_init', array(__CLASS__, 'settings'));
        add_shortcode('db_safedate_pwa_install', array(__CLASS__, 'pwa_install_shortcode'));
        add_action('dbsd_push_digest', array(__CLASS__, 'push_digest'));
        if (!wp_next_scheduled('dbsd_push_digest')) {
            wp_schedule_event(time() + 120, 'five_minutes', 'dbsd_push_digest');
        }
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }
    private static function json($request) { $params = $request->get_json_params(); return is_array($params) ? $params : array(); }
    public static function logged_in() { return is_user_logged_in(); }
    public static function admin_only() { return current_user_can('dbsd_manage_safety'); }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v040_db_version', '0');
        if (version_compare($current, self::DB_VERSION, '>=')) return;
        self::install_tables();
        update_option('dbsd_v040_db_version', self::DB_VERSION);
        add_option('dbsd_pwa_enabled', 'yes');
        add_option('dbsd_push_enabled', 'yes');
        add_option('dbsd_push_vapid_public_key', '');
        add_option('dbsd_push_vapid_private_key', '');
        add_option('dbsd_push_vapid_subject', 'mailto:' . get_option('admin_email'));
        add_option('dbsd_admin_live_refresh_seconds', 15);
        add_option('dbsd_notify_browser_poll_seconds', 30);
        self::rewrite_rules();
        flush_rewrite_rules(false);
        update_option('dbsd_v040_rewrite_flushed_version', DBSD_VERSION);
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $subs = self::table('push_subscriptions');
        $live = self::table('live_events');

        dbDelta("CREATE TABLE $subs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            p256dh TEXT NULL,
            auth TEXT NULL,
            user_agent TEXT NULL,
            device_label VARCHAR(190) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_hash (endpoint_hash),
            KEY user_id (user_id),
            KEY is_active (is_active)
        ) $charset;");

        dbDelta("CREATE TABLE $live (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(80) NOT NULL,
            severity VARCHAR(30) NOT NULL DEFAULT 'info',
            summary TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY severity (severity),
            KEY created_at (created_at)
        ) $charset;");
    }

    public static function settings() {
        register_setting('dbsd_settings', 'dbsd_pwa_enabled', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'yes'));
        register_setting('dbsd_settings', 'dbsd_trusted_proxy_cidrs', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => ''));
        register_setting('dbsd_settings', 'dbsd_push_enabled', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'yes'));
        register_setting('dbsd_settings', 'dbsd_push_vapid_public_key', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('dbsd_settings', 'dbsd_push_vapid_private_key', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('dbsd_settings', 'dbsd_push_vapid_subject', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'mailto:' . get_option('admin_email')));
        register_setting('dbsd_settings', 'dbsd_admin_live_refresh_seconds', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 15));
        register_setting('dbsd_settings', 'dbsd_notify_browser_poll_seconds', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30));
    }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/pwa/manifest', array('methods' => 'GET', 'callback' => array(__CLASS__, 'manifest'), 'permission_callback' => '__return_true'));
        register_rest_route($ns, '/push/public-key', array('methods' => 'GET', 'callback' => array(__CLASS__, 'public_key'), 'permission_callback' => '__return_true'));
        register_rest_route($ns, '/push/subscribe', array('methods' => 'POST', 'callback' => array(__CLASS__, 'subscribe'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/push/unsubscribe', array('methods' => 'POST', 'callback' => array(__CLASS__, 'unsubscribe'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/push/test', array('methods' => 'POST', 'callback' => array(__CLASS__, 'test_push'), 'permission_callback' => array(__CLASS__, 'logged_in')));
        register_rest_route($ns, '/push/health', array('methods' => 'GET', 'callback' => array(__CLASS__, 'push_health'), 'permission_callback' => array(__CLASS__, 'admin_only')));
        register_rest_route($ns, '/admin/live', array('methods' => 'GET', 'callback' => array(__CLASS__, 'admin_live'), 'permission_callback' => array(__CLASS__, 'admin_only')));
    }


    public static function rewrite_rules() {
        add_rewrite_rule('^dbsd-sw\.js$', 'index.php?dbsd_sw=1', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'dbsd_sw';
        return $vars;
    }

    public static function root_service_worker() {
        $request_path = '/' . ltrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $home_path = '/' . trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        $home_path = $home_path === '/' ? '/' : trailingslashit($home_path);
        $relative = ltrim(substr($request_path, 0, strlen($home_path)) === $home_path ? substr($request_path, strlen($home_path)) : ltrim($request_path, '/'), '/');
        $is_sw = (function_exists('get_query_var') && get_query_var('dbsd_sw')) || $relative === 'dbsd-sw.js' || basename($request_path) === 'dbsd-sw.js';
        if (!$is_sw) return;
        if (get_option('dbsd_pwa_enabled', 'yes') !== 'yes') { status_header(404); exit; }
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ' . $home_path);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $file = DBSD_PLUGIN_DIR . 'assets/js/dbsd-sw.js';
        if (is_readable($file)) readfile($file);
        exit;
    }

    public static function pwa_head_tags() {
        if (get_option('dbsd_pwa_enabled', 'yes') !== 'yes') return;
        echo '<link rel="manifest" href="' . esc_url(rest_url('datebook-safedate/v1/pwa/manifest')) . '">' . "\n";
        echo '<meta name="theme-color" content="#c2185b">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-title" content="SafeDate">' . "\n";
    }

    public static function manifest() {
        return new WP_REST_Response(array(
            'name' => get_bloginfo('name') . ' SafeDate',
            'short_name' => 'SafeDate',
            'description' => 'Consent-based dating safety sessions, check-ins, and alerts.',
            'start_url' => home_url('/'),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => '#c2185b',
            'icons' => array(
                array('src' => DBSD_PLUGIN_URL . 'assets/img/safedate-icon-192.svg', 'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'),
                array('src' => DBSD_PLUGIN_URL . 'assets/img/safedate-icon-512.svg', 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'),
            ),
        ), 200, array('Content-Type' => 'application/manifest+json'));
    }

    public static function public_key() {
        return array('ok' => true, 'publicKey' => get_option('dbsd_push_vapid_public_key', ''));
    }

    public static function subscribe($request) {
        global $wpdb;
        $p = self::json($request);
        $endpoint = esc_url_raw($p['endpoint'] ?? '');
        $keys = isset($p['keys']) && is_array($p['keys']) ? $p['keys'] : array();
        if (!$endpoint) return new WP_Error('dbsd_missing_endpoint', __('Missing push endpoint.', 'datebook-safedate'), array('status' => 400));
        $hash = hash('sha256', $endpoint);
        $now = current_time('mysql', true);
        $row = array(
            'user_id' => get_current_user_id(),
            'endpoint' => $endpoint,
            'endpoint_hash' => $hash,
            'p256dh' => sanitize_textarea_field($keys['p256dh'] ?? ''),
            'auth' => sanitize_textarea_field($keys['auth'] ?? ''),
            'user_agent' => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'device_label' => sanitize_text_field($p['device_label'] ?? ''),
            'is_active' => 1,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        );
        $wpdb->replace(self::table('push_subscriptions'), $row);
        self::live_event(null, get_current_user_id(), 'push_subscribed', 'info', 'User enabled SafeDate push notifications.');
        return array('ok' => true);
    }

    public static function unsubscribe($request) {
        global $wpdb;
        $p = self::json($request);
        $endpoint = esc_url_raw($p['endpoint'] ?? '');
        if ($endpoint) {
            $wpdb->update(self::table('push_subscriptions'), array('is_active' => 0, 'updated_at' => current_time('mysql', true)), array('endpoint_hash' => hash('sha256', $endpoint), 'user_id' => get_current_user_id()));
        } else {
            $wpdb->update(self::table('push_subscriptions'), array('is_active' => 0, 'updated_at' => current_time('mysql', true)), array('user_id' => get_current_user_id()));
        }
        self::live_event(null, get_current_user_id(), 'push_unsubscribed', 'info', 'User disabled SafeDate push notifications.');
        return array('ok' => true);
    }

    public static function test_push($request) {
        $p = self::json($request);
        $user_id = current_user_can('dbsd_manage_safety') && !empty($p['user_id']) ? absint($p['user_id']) : get_current_user_id();
        $result = self::send_push_to_user($user_id, array(
            'title' => 'SafeDate test notification',
            'body' => 'Push/browser notifications are connected for this device.',
            'url' => home_url('/'),
            'tag' => 'dbsd-test',
        ));
        return array('ok' => true, 'result' => $result);
    }


    public static function push_health() {
        global $wpdb;
        $public = get_option('dbsd_push_vapid_public_key', '');
        $private = get_option('dbsd_push_vapid_private_key', '');
        $subject = get_option('dbsd_push_vapid_subject', 'mailto:' . get_option('admin_email'));
        $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::table('push_subscriptions') . " WHERE is_active=1");
        $ready = class_exists('Minishlink\WebPush\WebPush') && !empty($public) && !empty($private) && !empty($subject) && get_option('dbsd_push_enabled', 'yes') === 'yes';
        return array(
            'ok' => true,
            'push_enabled' => get_option('dbsd_push_enabled', 'yes') === 'yes',
            'pwa_enabled' => get_option('dbsd_pwa_enabled', 'yes') === 'yes',
            'service_worker_url' => home_url('/dbsd-sw.js'),
            'service_worker_scope' => home_url('/'),
            'service_worker_allowed_path' => parse_url(home_url('/'), PHP_URL_PATH) ?: '/',
            'active_subscriptions' => $active,
            'web_push_library_loaded' => class_exists('Minishlink\WebPush\WebPush'),
            'vapid_public_key_configured' => !empty($public),
            'vapid_private_key_configured' => !empty($private),
            'vapid_subject' => $subject,
            'server_push_ready' => $ready,
            'fallback_mode' => $ready ? 'server_web_push' : 'browser_polling_when_app_open',
            'warnings' => array_values(array_filter(array(
                empty($public) || empty($private) ? 'VAPID public/private keys are not configured.' : '',
                !class_exists('Minishlink\WebPush\WebPush') ? 'minishlink/web-push is not loaded.' : '',
            )))
        );
    }

    public static function send_push_to_user($user_id, $payload) {
        global $wpdb;
        $subs = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('push_subscriptions') . " WHERE user_id=%d AND is_active=1", absint($user_id)));
        $delivery = array('subscriptions' => count($subs), 'sent' => 0, 'mode' => 'browser-polling-ready', 'errors' => array());
        if (!$subs) return $delivery;

        // Production adapter: if minishlink/web-push is installed by the site owner, use it.
        if (class_exists('Minishlink\\WebPush\\WebPush') && class_exists('Minishlink\\WebPush\\Subscription')) {
            $public = get_option('dbsd_push_vapid_public_key', '');
            $private = get_option('dbsd_push_vapid_private_key', '');
            $subject = get_option('dbsd_push_vapid_subject', 'mailto:' . get_option('admin_email'));
            if ($public && $private) {
                try {
                    $auth = array('VAPID' => array('subject' => $subject, 'publicKey' => $public, 'privateKey' => $private));
                    $webPush = new Minishlink\WebPush\WebPush($auth);
                    foreach ($subs as $sub) {
                        $subscription = Minishlink\WebPush\Subscription::create(array(
                            'endpoint' => $sub->endpoint,
                            'publicKey' => $sub->p256dh,
                            'authToken' => $sub->auth,
                        ));
                        $webPush->queueNotification($subscription, wp_json_encode($payload));
                    }
                    foreach ($webPush->flush() as $report) {
                        if ($report->isSuccess()) $delivery['sent']++;
                        else $delivery['errors'][] = $report->getReason();
                    }
                    $delivery['mode'] = 'web-push';
                    return $delivery;
                } catch (Exception $e) {
                    $delivery['errors'][] = $e->getMessage();
                }
            } else {
                $delivery['errors'][] = 'VAPID public/private keys are not configured.';
            }
        }

        // Fallback: the PWA/browser polling layer will show pending SafeDate notifications when the app is open/installed.
        $delivery['errors'][] = 'Install minishlink/web-push and configure VAPID keys for true server-sent Web Push delivery.';
        return $delivery;
    }

    public static function push_digest() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT n.*, s.status, s.alert_level FROM " . self::table('notifications') . " n LEFT JOIN " . self::table('sessions') . " s ON s.id=n.session_id WHERE n.status='pending' ORDER BY n.id DESC LIMIT 50");
        foreach ($rows as $n) {
            if (in_array($n->notification_type, array('sos_alert','departure_rejected','arrival_rejected','consent_requested','arrival_claimed','departure_claimed'), true)) {
                self::send_push_to_user((int)$n->recipient_user_id, array(
                    'title' => 'SafeDate: ' . $n->notification_type,
                    'body' => $n->message ?: 'You have a SafeDate notification.',
                    'url' => home_url('/'),
                    'tag' => 'dbsd-session-' . (int)$n->session_id,
                    'session_id' => (int)$n->session_id,
                ));
            }
        }
    }

    public static function admin_live($request) {
        global $wpdb;
        $sessions = $wpdb->get_results("SELECT * FROM " . self::table('sessions') . " ORDER BY updated_at DESC LIMIT 25");
        $alerts = $wpdb->get_results("SELECT * FROM " . self::table('sessions') . " WHERE alert_level <> 'normal' ORDER BY updated_at DESC LIMIT 25");
        $incidents_table = self::table('incidents');
        $incidents = $wpdb->get_results($wpdb->prepare("SHOW TABLES LIKE %s", $incidents_table)) ? $wpdb->get_results("SELECT * FROM $incidents_table WHERE admin_status IN ('open','reviewing') ORDER BY id DESC LIMIT 25") : array();
        $events = $wpdb->get_results("SELECT id, session_id, actor_user_id, event_type, created_at FROM " . self::table('events') . " ORDER BY id DESC LIMIT 40");
        $subs = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table('push_subscriptions') . " WHERE is_active=1");
        return array(
            'ok' => true,
            'generated_at' => current_time('mysql', true),
            'counts' => array(
                'active_sessions' => count($sessions),
                'active_alerts' => count($alerts),
                'open_incidents' => count($incidents),
                'push_subscriptions' => $subs,
            ),
            'sessions' => $sessions,
            'alerts' => $alerts,
            'incidents' => $incidents,
            'events' => $events,
        );
    }

    public static function live_event($session_id, $actor_user_id, $type, $severity, $summary) {
        global $wpdb;
        $wpdb->insert(self::table('live_events'), array(
            'session_id' => $session_id ? absint($session_id) : null,
            'actor_user_id' => $actor_user_id ? absint($actor_user_id) : null,
            'event_type' => sanitize_key($type),
            'severity' => sanitize_key($severity),
            'summary' => sanitize_textarea_field($summary),
            'created_at' => current_time('mysql', true),
        ));
    }

    public static function enqueue_frontend() {
        if (get_option('dbsd_pwa_enabled', 'yes') !== 'yes') return;
        wp_register_script('dbsd-v040', DBSD_PLUGIN_URL . 'assets/js/dbsd-v040.js', array(), DBSD_VERSION, true);
        wp_localize_script('dbsd-v040', 'DBSD_V04', array(
            'restUrl' => esc_url_raw(rest_url('datebook-safedate/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'swUrl' => home_url('/dbsd-sw.js'),
            'publicKey' => get_option('dbsd_push_vapid_public_key', ''),
            'pushEnabled' => get_option('dbsd_push_enabled', 'yes') === 'yes',
            'pollSeconds' => max(10, absint(get_option('dbsd_notify_browser_poll_seconds', 30))),
            'strings' => array(
                'install' => __('Install SafeDate', 'datebook-safedate'),
                'enablePush' => __('Enable safety notifications', 'datebook-safedate'),
                'pushReady' => __('Safety notifications enabled.', 'datebook-safedate'),
                'pushDenied' => __('Notifications are blocked or unavailable on this device.', 'datebook-safedate'),
            ),
        ));
        wp_enqueue_script('dbsd-v040');
    }

    public static function enqueue_admin($hook) {
        if (strpos($hook, 'dbsd-live') === false) return;
        wp_register_style('dbsd-frontend', DBSD_PLUGIN_URL . 'assets/css/dbsd-frontend.css', array(), DBSD_VERSION);
        wp_enqueue_script('dbsd-admin-live', DBSD_PLUGIN_URL . 'assets/js/dbsd-admin-live.js', array(), DBSD_VERSION, true);
        wp_localize_script('dbsd-admin-live', 'DBSD_ADMIN_LIVE', array(
            'restUrl' => esc_url_raw(rest_url('datebook-safedate/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'refreshSeconds' => max(5, absint(get_option('dbsd_admin_live_refresh_seconds', 15))),
        ));
        wp_enqueue_style('dbsd-frontend');
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'SafeDate Live Monitor', 'Live Monitor', 'dbsd_manage_safety', 'dbsd-live', array(__CLASS__, 'admin_live_page'));
    }

    public static function admin_live_page() {
        if (!current_user_can('dbsd_manage_safety')) return;
        echo '<div class="wrap"><h1>SafeDate Live Monitor</h1><p>Real-time polling dashboard for sessions, alerts, incidents, push subscriptions, and recent audit events.</p><div id="dbsd-live-root" class="dbsd-admin-live">Loading live monitor...</div></div>';
    }

    public static function pwa_install_shortcode() {
        if (!is_user_logged_in()) return '<p>Please log in to enable SafeDate app notifications.</p>';
        wp_enqueue_script('dbsd-v040');
        return '<div class="dbsd-card dbsd-pwa-card"><h3>SafeDate App & Notifications</h3><p class="dbsd-muted">Install this site as an app and enable safety notifications for arrival, departure, SOS, and consent prompts.</p><button type="button" class="dbsd-btn" data-dbsd-install-app>Install App</button> <button type="button" class="dbsd-btn" data-dbsd-enable-push>Enable Notifications</button><div class="dbsd-result" data-dbsd-pwa-status></div></div>';
    }
}
