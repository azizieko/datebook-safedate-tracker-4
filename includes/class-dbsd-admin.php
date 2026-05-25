<?php
if (!defined('ABSPATH')) exit;

class DBSD_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'settings'));
    }

    public static function menu() { add_menu_page('SafeDate', 'SafeDate', 'dbsd_manage_safety', 'dbsd', array(__CLASS__, 'page'), 'dashicons-shield-alt', 56); }

    public static function settings() {
        register_setting('dbsd_settings', 'dbsd_data_retention_days', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 180));
        register_setting('dbsd_settings', 'dbsd_show_exact_location_to_host', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'no'));
        register_setting('dbsd_settings', 'dbsd_missing_location_minutes', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 20));
        register_setting('dbsd_settings', 'dbsd_expected_arrival_grace_minutes', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 15));
        register_setting('dbsd_settings', 'dbsd_platform_alert_email', array('type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => get_option('admin_email')));
    }

    public static function page() {
        if (!current_user_can('dbsd_manage_safety')) return;
        global $wpdb;
        $sessions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dbsd_sessions ORDER BY id DESC LIMIT 50");
        $alerts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dbsd_sessions WHERE alert_level <> 'normal' ORDER BY updated_at DESC LIMIT 25");
        ?>
        <div class="wrap">
            <h1>DateBook SafeDate Tracker</h1>
            <p><strong>v0.4:</strong> PWA install support, browser/Web Push subscriptions, live admin monitoring, SOS alerts, emergency contacts, incident reports, trusted-contact sharing, and secure audit exports.</p>
            <form method="post" action="options.php">
                <?php settings_fields('dbsd_settings'); ?>
                <h2>Settings</h2>
                <table class="form-table">
                    <tr><th scope="row">Data retention days</th><td><input name="dbsd_data_retention_days" type="number" value="<?php echo esc_attr(get_option('dbsd_data_retention_days', 180)); ?>"></td></tr>
                    <tr><th scope="row">Show exact traveler location to host</th><td><select name="dbsd_show_exact_location_to_host"><option value="no" <?php selected(get_option('dbsd_show_exact_location_to_host', 'no'), 'no'); ?>>No - rounded location only</option><option value="yes" <?php selected(get_option('dbsd_show_exact_location_to_host', 'no'), 'yes'); ?>>Yes - exact location</option></select><p class="description">Recommended: No, unless your legal/privacy policy clearly allows exact location sharing.</p></td></tr>
                    <tr><th scope="row">Missing location alert after minutes</th><td><input name="dbsd_missing_location_minutes" type="number" min="5" value="<?php echo esc_attr(get_option('dbsd_missing_location_minutes', 20)); ?>"></td></tr>
                    <tr><th scope="row">Expected arrival grace minutes</th><td><input name="dbsd_expected_arrival_grace_minutes" type="number" min="5" value="<?php echo esc_attr(get_option('dbsd_expected_arrival_grace_minutes', 15)); ?>"></td></tr>
                    <tr><th scope="row">Platform alert email</th><td><input name="dbsd_platform_alert_email" type="email" class="regular-text" value="<?php echo esc_attr(get_option('dbsd_platform_alert_email', get_option('admin_email'))); ?>"></td></tr>
                    <tr><th scope="row">Enable PWA support</th><td><select name="dbsd_pwa_enabled"><option value="yes" <?php selected(get_option('dbsd_pwa_enabled', 'yes'), 'yes'); ?>>Yes</option><option value="no" <?php selected(get_option('dbsd_pwa_enabled', 'yes'), 'no'); ?>>No</option></select></td></tr>
                    <tr><th scope="row">Enable push/browser notifications</th><td><select name="dbsd_push_enabled"><option value="yes" <?php selected(get_option('dbsd_push_enabled', 'yes'), 'yes'); ?>>Yes</option><option value="no" <?php selected(get_option('dbsd_push_enabled', 'yes'), 'no'); ?>>No</option></select></td></tr>
                    <tr><th scope="row">VAPID public key</th><td><input name="dbsd_push_vapid_public_key" type="text" class="large-text" value="<?php echo esc_attr(get_option('dbsd_push_vapid_public_key', '')); ?>"><p class="description">Required for true background Web Push delivery.</p></td></tr>
                    <tr><th scope="row">VAPID private key</th><td><input name="dbsd_push_vapid_private_key" type="password" class="large-text" value="<?php echo esc_attr(get_option('dbsd_push_vapid_private_key', '')); ?>"></td></tr>
                    <tr><th scope="row">VAPID subject</th><td><input name="dbsd_push_vapid_subject" type="text" class="regular-text" value="<?php echo esc_attr(get_option('dbsd_push_vapid_subject', 'mailto:' . get_option('admin_email'))); ?>"></td></tr>
                    <tr><th scope="row">Admin live refresh seconds</th><td><input name="dbsd_admin_live_refresh_seconds" type="number" min="5" value="<?php echo esc_attr(get_option('dbsd_admin_live_refresh_seconds', 15)); ?>"></td></tr>
                    <tr><th scope="row">Browser notification polling seconds</th><td><input name="dbsd_notify_browser_poll_seconds" type="number" min="10" value="<?php echo esc_attr(get_option('dbsd_notify_browser_poll_seconds', 30)); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Active Alerts</h2>
            <table class="widefat striped"><thead><tr><th>ID</th><th>Alert</th><th>Status</th><th>Host</th><th>Traveler</th><th>Last location</th><th>Updated</th></tr></thead><tbody>
                <?php if (!$alerts): ?><tr><td colspan="7">No active alerts.</td></tr><?php endif; ?>
                <?php foreach ($alerts as $s): ?><tr><td><?php echo esc_html($s->id); ?></td><td><strong><?php echo esc_html($s->alert_level); ?></strong></td><td><?php echo esc_html($s->status); ?></td><td><?php echo esc_html($s->host_user_id); ?></td><td><?php echo esc_html($s->traveler_user_id); ?></td><td><?php echo esc_html($s->last_location_at); ?></td><td><?php echo esc_html($s->updated_at); ?></td></tr><?php endforeach; ?>
            </tbody></table>

            <h2>Recent Sessions</h2>
            <table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>Alert</th><th>Host</th><th>Traveler</th><th>Created</th><th>Shortcode</th></tr></thead><tbody>
                <?php foreach ($sessions as $s): ?><tr><td><?php echo esc_html($s->id); ?></td><td><?php echo esc_html($s->status); ?></td><td><?php echo esc_html($s->alert_level); ?></td><td><?php echo esc_html($s->host_user_id); ?></td><td><?php echo esc_html($s->traveler_user_id); ?></td><td><?php echo esc_html($s->created_at); ?></td><td><code>[db_safedate_session id="<?php echo esc_attr($s->id); ?>"]</code></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }
}
