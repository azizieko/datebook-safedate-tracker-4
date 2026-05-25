<?php
if (!defined('ABSPATH')) exit;

class DBSD_Activator {
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $sessions = $wpdb->prefix . 'dbsd_sessions';
        $consents = $wpdb->prefix . 'dbsd_consents';
        $locations = $wpdb->prefix . 'dbsd_locations';
        $events = $wpdb->prefix . 'dbsd_events';
        $notifications = $wpdb->prefix . 'dbsd_notifications';
        $contacts = $wpdb->prefix . 'dbsd_emergency_contacts';

        $sql = array();
        $sql[] = "CREATE TABLE $sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            host_user_id BIGINT UNSIGNED NOT NULL,
            traveler_user_id BIGINT UNSIGNED NOT NULL,
            meeting_address TEXT NULL,
            meeting_lat DECIMAL(10,7) NULL,
            meeting_lng DECIMAL(10,7) NULL,
            planned_start_at DATETIME NULL,
            planned_end_at DATETIME NULL,
            expected_arrival_at DATETIME NULL,
            expected_departure_at DATETIME NULL,
            last_location_at DATETIME NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pending_consent',
            alert_level VARCHAR(20) NOT NULL DEFAULT 'normal',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY host_user_id (host_user_id),
            KEY traveler_user_id (traveler_user_id),
            KEY status (status),
            KEY alert_level (alert_level),
            KEY last_location_at (last_location_at)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE $consents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            consent_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            consented_at DATETIME NULL,
            ip_address VARCHAR(100) NULL,
            user_agent TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_user (session_id, user_id),
            KEY session_id (session_id)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE $locations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            lat DECIMAL(10,7) NOT NULL,
            lng DECIMAL(10,7) NOT NULL,
            accuracy FLOAT NULL,
            speed FLOAT NULL,
            heading FLOAT NULL,
            battery_level FLOAT NULL,
            recorded_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_user_time (session_id, user_id, recorded_at)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE $events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(80) NOT NULL,
            event_payload LONGTEXT NULL,
            event_hash CHAR(64) NOT NULL,
            previous_event_hash CHAR(64) NULL,
            ip_address VARCHAR(100) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE $notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            recipient_user_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(80) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            message TEXT NULL,
            response_payload LONGTEXT NULL,
            sent_at DATETIME NULL,
            read_at DATETIME NULL,
            responded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_recipient (session_id, recipient_user_id),
            KEY status (status)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE $contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            contact_name VARCHAR(190) NOT NULL,
            contact_email VARCHAR(190) NULL,
            contact_phone VARCHAR(60) NULL,
            can_receive_alerts TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        add_option('dbsd_data_retention_days', 180);
        add_option('dbsd_show_exact_location_to_host', 'no');
        add_option('dbsd_missing_location_minutes', 20);
        add_option('dbsd_expected_arrival_grace_minutes', 15);
        add_option('dbsd_platform_alert_email', get_option('admin_email'));

        // v0.7.4: dedicated SafeDate administrator capability and root service-worker rewrite setup.
        foreach (array('administrator') as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('dbsd_manage_safety');
            }
        }
        if (class_exists('DBSD_V040')) {
            DBSD_V040::rewrite_rules();
        }
        flush_rewrite_rules(false);


        if (class_exists('DBSD_Monitor')) {
            add_filter('cron_schedules', array('DBSD_Monitor', 'cron_schedules'));
        }
        if (!wp_next_scheduled('dbsd_monitor_sessions')) {
            wp_schedule_event(time() + 300, 'five_minutes', 'dbsd_monitor_sessions');
        }
    }
}
