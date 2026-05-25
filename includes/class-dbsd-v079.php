<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.7.9 CI evidence and native production-wiring support.
 * Adds pairing-attempt retention cleanup, CI evidence metadata, and admin helpers
 * without adding new product features.
 */
class DBSD_V079 {
    const VERSION = '0.7.10';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 6);
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('dbsd_v079_cleanup_pairing_attempts', array(__CLASS__, 'cleanup_pairing_attempts'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 79);
    }

    private static function table($name) { global $wpdb; return $wpdb->prefix . 'dbsd_' . $name; }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v079_version', '0');
        if (version_compare($current, self::VERSION, '>=')) return;
        add_option('dbsd_pairing_attempt_retention_days', 90);
        if (!wp_next_scheduled('dbsd_v079_cleanup_pairing_attempts')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dbsd_v079_cleanup_pairing_attempts');
        }
        update_option('dbsd_v079_version', self::VERSION);
    }

    public static function cleanup_pairing_attempts() {
        global $wpdb;
        $days = max(7, min(3650, absint(get_option('dbsd_pairing_attempt_retention_days', 90))));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $table = self::table('mobile_pairing_attempts');
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < %s", $cutoff));
        if (class_exists('DBSD_V060')) {
            DBSD_V060::security_event(null, get_current_user_id() ?: null, null, 'pairing_attempt_retention_cleanup', 'info', array('deleted' => (int)$deleted, 'retention_days' => $days));
        }
        return (int)$deleted;
    }

    public static function routes() {
        $ns = 'datebook-safedate/v1';
        register_rest_route($ns, '/ci/evidence', array('methods' => 'GET', 'callback' => array(__CLASS__, 'ci_evidence'), 'permission_callback' => array(__CLASS__, 'can_manage_settings')));
        register_rest_route($ns, '/admin/pairing-attempts/cleanup', array('methods' => 'POST', 'callback' => array(__CLASS__, 'cleanup_now'), 'permission_callback' => array(__CLASS__, 'can_manage_devices')));
    }

    public static function can_manage_devices() { return current_user_can('dbsd_manage_devices') || current_user_can('dbsd_manage_safety'); }
    public static function can_manage_settings() { return current_user_can('dbsd_manage_settings') || current_user_can('dbsd_manage_safety'); }

    public static function ci_evidence() {
        $ci = DBSD_PLUGIN_DIR . 'docs/ci-results-v0.7.10.md';
        return array(
            'ok' => true,
            'version' => self::VERSION,
            'phpunit_config' => file_exists(DBSD_PLUGIN_DIR . 'phpunit.xml.dist'),
            'github_actions_workflow' => file_exists(DBSD_PLUGIN_DIR . '.github/workflows/phpunit.yml'),
            'ci_results_document' => file_exists($ci) ? 'docs/ci-results-v0.7.10.md' : null,
            'artifact_expected' => 'ci-evidence-v0.7.10 and phpunit-results-* artifacts from GitHub Actions',
            'full_ci_run_status' => 'pending_external_ci_execution',
            'note' => 'This endpoint reports packaged evidence hooks. Production promotion requires an actual successful CI run URL/artifact outside this ZIP.'
        );
    }

    public static function cleanup_now() {
        return array('ok' => true, 'deleted' => self::cleanup_pairing_attempts());
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'CI Evidence v0.7.10', 'CI Evidence v0.7.10', 'dbsd_manage_settings', 'dbsd-v079', array(__CLASS__, 'admin_page'));
    }

    public static function admin_page() {
        if (!self::can_manage_settings()) return;
        echo '<div class="wrap"><h1>SafeDate CI Evidence v0.7.10</h1>';
        echo '<p>v0.7.10 packages CI evidence generation, native starter production wiring, and pairing-attempt retention cleanup.</p>';
        echo '<pre>' . esc_html(wp_json_encode(self::ci_evidence(), JSON_PRETTY_PRINT)) . '</pre>';
        echo '</div>';
    }
}
