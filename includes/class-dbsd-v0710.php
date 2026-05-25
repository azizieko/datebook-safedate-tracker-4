<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.7.10 release-candidate hardening helpers.
 * Provides CI evidence metadata, pairing telemetry cleanup hooks, and a small
 * runtime diagnostic layer used by QA/release-candidate validation.
 */
class DBSD_V0710 {
    const VERSION = '0.7.10';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 10);
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 710);
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v0710_version', '0');
        if (version_compare($current, self::VERSION, '>=')) return;
        add_option('dbsd_pairing_attempt_retention_days', 90);
        if (!wp_next_scheduled('dbsd_v079_cleanup_pairing_attempts')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dbsd_v079_cleanup_pairing_attempts');
        }
        update_option('dbsd_v0710_version', self::VERSION);
    }

    public static function can_manage_settings() { return current_user_can('dbsd_manage_settings') || current_user_can('dbsd_manage_safety'); }
    public static function can_manage_devices() { return current_user_can('dbsd_manage_devices') || current_user_can('dbsd_manage_safety'); }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/ci/evidence-v0710', array('methods' => 'GET', 'callback' => array(__CLASS__, 'ci_evidence'), 'permission_callback' => array(__CLASS__, 'can_manage_settings')));
        register_rest_route($ns, '/qa/behavioral-status', array('methods' => 'GET', 'callback' => array(__CLASS__, 'behavioral_status'), 'permission_callback' => array(__CLASS__, 'can_manage_settings')));
    }

    public static function ci_evidence() {
        return array(
            'ok' => true,
            'version' => self::VERSION,
            'full_ci_run_status' => 'pending_external_ci_execution',
            'github_actions_workflow' => file_exists(DBSD_PLUGIN_DIR . '.github/workflows/phpunit.yml'),
            'phpunit_config' => file_exists(DBSD_PLUGIN_DIR . 'phpunit.xml.dist'),
            'expected_artifacts' => array(
                'phpunit-results-*',
                'ci-evidence-v0.7.10-*',
                'android-build-*',
                'ios-static-check-*'
            ),
            'note' => 'This package prepares CI evidence generation. Production promotion requires a successful external CI run URL and artifacts.'
        );
    }

    public static function behavioral_status() {
        return array(
            'ok' => true,
            'version' => self::VERSION,
            'service_worker_url' => home_url('/dbsd-sw.js'),
            'service_worker_asset_readable' => is_readable(DBSD_PLUGIN_DIR . 'assets/js/dbsd-sw.js'),
            'android_secure_store_default_required' => true,
            'ios_keychain_store_required' => true,
            'signed_revoke_accepts_refresh_fallback' => true,
            'pairing_abuse_source' => 'dbsd_mobile_pairing_attempts telemetry table'
        );
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'RC Evidence v0.7.10', 'RC Evidence v0.7.10', 'dbsd_manage_settings', 'dbsd-v0710', array(__CLASS__, 'admin_page'));
    }

    public static function admin_page() {
        if (!self::can_manage_settings()) return;
        echo '<div class="wrap"><h1>SafeDate Release Candidate Evidence v0.7.10</h1>';
        echo '<h2>CI Evidence</h2><pre>' . esc_html(wp_json_encode(self::ci_evidence(), JSON_PRETTY_PRINT)) . '</pre>';
        echo '<h2>Behavioral Status</h2><pre>' . esc_html(wp_json_encode(self::behavioral_status(), JSON_PRETTY_PRINT)) . '</pre>';
        echo '</div>';
    }
}
