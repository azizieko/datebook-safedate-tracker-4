<?php
if (!defined('ABSPATH')) exit;

class DBSD_Monitor {
    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('dbsd_monitor_sessions', array(__CLASS__, 'monitor_sessions'));
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = array('interval' => 300, 'display' => __('Every five minutes', 'datebook-safedate'));
        }
        return $schedules;
    }

    public static function monitor_sessions() {
        global $wpdb;
        $sessions = $wpdb->prefix . 'dbsd_sessions';
        $missing_minutes = max(5, (int) get_option('dbsd_missing_location_minutes', 20));
        $arrival_grace = max(5, (int) get_option('dbsd_expected_arrival_grace_minutes', 15));
        $now = current_time('mysql', true);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $sessions WHERE status IN ('journey_started','ready','arrival_claimed','arrival_confirmed','departure_claimed') AND alert_level <> 'sos' AND updated_at < DATE_SUB(%s, INTERVAL 1 MINUTE) LIMIT 50",
            $now
        ));

        foreach ($rows as $s) {
            if (!empty($s->last_location_at) && in_array($s->status, array('journey_started'), true)) {
                $last_ts = strtotime($s->last_location_at . ' UTC');
                if ($last_ts && $last_ts < time() - ($missing_minutes * 60) && $s->alert_level === 'normal') {
                    self::raise_alert((int) $s->id, 0, 'missing_location', array('last_location_at' => $s->last_location_at, 'threshold_minutes' => $missing_minutes));
                }
            }
            if (!empty($s->expected_arrival_at) && in_array($s->status, array('journey_started','ready'), true)) {
                $arrival_ts = strtotime($s->expected_arrival_at . ' UTC');
                if ($arrival_ts && $arrival_ts < time() - ($arrival_grace * 60) && $s->alert_level === 'normal') {
                    self::raise_alert((int) $s->id, 0, 'arrival_overdue', array('expected_arrival_at' => $s->expected_arrival_at, 'grace_minutes' => $arrival_grace));
                }
            }
        }
    }

    public static function raise_alert($session_id, $actor_user_id, $type, $payload = array()) {
        global $wpdb;
        $level = $type === 'sos' ? 'sos' : 'watch';
        $wpdb->update($wpdb->prefix . 'dbsd_sessions', array('alert_level' => $level, 'updated_at' => current_time('mysql', true)), array('id' => $session_id));
        DBSD_Audit::log_event($session_id, $actor_user_id, 'alert_' . sanitize_key($type), $payload);
        self::notify_platform($session_id, $type, $payload);
    }

    public static function notify_platform($session_id, $type, $payload = array()) {
        $email = sanitize_email(get_option('dbsd_platform_alert_email', get_option('admin_email')));
        if (!$email) return;
        $subject = sprintf('[SafeDate] %s alert for session #%d', strtoupper($type), $session_id);
        $body = "SafeDate alert created.\n\nSession: #" . $session_id . "\nType: " . $type . "\nTime UTC: " . current_time('mysql', true) . "\n\nPayload:\n" . wp_json_encode($payload, JSON_PRETTY_PRINT);
        wp_mail($email, $subject, $body);
    }
}
