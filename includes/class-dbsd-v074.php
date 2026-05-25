<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.7.4 QA/security hardening.
 * Adds capability migration, shared public IP throttling, rewrite flush tracking,
 * and operational helpers used by the stabilization tests.
 */
class DBSD_V074 {
    const VERSION = '0.7.4';
    const CAPABILITY = 'dbsd_manage_safety';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 1);
        add_action('admin_init', array(__CLASS__, 'settings'));
    }

    public static function maybe_upgrade() {
        $current = get_option('dbsd_v074_version', '0');
        if (version_compare($current, self::VERSION, '>=')) return;
        self::grant_caps();
        if (class_exists('DBSD_V040')) {
            DBSD_V040::rewrite_rules();
            flush_rewrite_rules(false);
            update_option('dbsd_v040_rewrite_flushed_version', DBSD_VERSION);
        }
        add_option('dbsd_public_share_rate_limit_per_minute', 60);
        update_option('dbsd_v074_version', self::VERSION);
    }

    public static function settings() {
        register_setting('dbsd_settings', 'dbsd_public_share_rate_limit_per_minute', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 60));
    }

    public static function grant_caps() {
        foreach (array('administrator') as $role_name) {
            $role = get_role($role_name);
            if ($role && !$role->has_cap(self::CAPABILITY)) {
                $role->add_cap(self::CAPABILITY);
            }
        }
    }

    public static function client_ip() {
        if (class_exists('DBSD_Audit')) return DBSD_Audit::client_ip();
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public static function public_ip_rate_limit($route, $limit = null) {
        global $wpdb;
        $limit = $limit === null ? absint(get_option('dbsd_public_share_rate_limit_per_minute', 60)) : absint($limit);
        $limit = max(1, $limit);
        $identity = 'public-ip:' . self::client_ip();
        $identity_hash = hash('sha256', $identity . '|' . wp_salt('nonce'));
        $window = floor(time() / 60) * 60;
        $table = $wpdb->prefix . 'dbsd_mobile_rate_limits';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE identity_hash=%s AND route=%s AND window_start=%d", $identity_hash, $route, $window));
        if ($row) {
            if ((int)$row->request_count >= $limit) return false;
            $wpdb->update($table, array('request_count' => (int)$row->request_count + 1, 'updated_at' => current_time('mysql', true)), array('id' => (int)$row->id));
        } else {
            $wpdb->insert($table, array('identity_hash' => $identity_hash, 'route' => sanitize_text_field(substr($route, 0, 190)), 'window_start' => $window, 'request_count' => 1, 'updated_at' => current_time('mysql', true)));
        }
        return true;
    }

    public static function can_manage() {
        return current_user_can(self::CAPABILITY);
    }
}
