<?php
if (!defined('ABSPATH')) exit;

class DBSD_Audit {
    public static function client_ip() {
        $remote = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $remote = self::valid_ip($remote) ? $remote : '';
        if ($remote && self::is_trusted_proxy($remote)) {
            foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR') as $key) {
                if (empty($_SERVER[$key])) continue;
                $value = sanitize_text_field(wp_unslash($_SERVER[$key]));
                foreach (explode(',', $value) as $candidate) {
                    $candidate = trim($candidate);
                    if (self::valid_ip($candidate)) return $candidate;
                }
            }
        }
        return $remote;
    }

    private static function valid_ip($ip) {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP);
    }

    private static function is_trusted_proxy($ip) {
        $cidrs = get_option('dbsd_trusted_proxy_cidrs', '');
        if (!$cidrs) return false;
        foreach (preg_split('/[\s,]+/', $cidrs) as $cidr) {
            $cidr = trim($cidr);
            if ($cidr && self::ip_in_cidr($ip, $cidr)) return true;
        }
        return false;
    }

    private static function ip_in_cidr($ip, $cidr) {
        if (strpos($cidr, '/') === false) return hash_equals($cidr, $ip);
        list($subnet, $bits) = explode('/', $cidr, 2);
        if (!self::valid_ip($subnet) || !is_numeric($bits)) return false;
        $ip_bin = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin)) return false;
        $bits = max(0, min((int)$bits, strlen($ip_bin) * 8));
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) return false;
        if ($remainder === 0) return true;
        $mask = chr((0xff << (8 - $remainder)) & 0xff);
        return (ord($ip_bin[$bytes]) & ord($mask)) === (ord($subnet_bin[$bytes]) & ord($mask));
    }

    public static function user_agent() {
        return !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    }

    public static function log_event($session_id, $actor_user_id, $event_type, $payload = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'dbsd_events';
        $previous_hash = $wpdb->get_var($wpdb->prepare(
            "SELECT event_hash FROM $table WHERE session_id = %d ORDER BY id DESC LIMIT 1",
            $session_id
        ));
        $created_at = current_time('mysql', true);
        $json = wp_json_encode($payload);
        $hash_source = $session_id . '|' . $actor_user_id . '|' . $event_type . '|' . $json . '|' . $previous_hash . '|' . $created_at;
        $hash = hash('sha256', $hash_source);

        $wpdb->insert($table, array(
            'session_id' => absint($session_id),
            'actor_user_id' => $actor_user_id ? absint($actor_user_id) : null,
            'event_type' => sanitize_key($event_type),
            'event_payload' => $json,
            'event_hash' => $hash,
            'previous_event_hash' => $previous_hash,
            'ip_address' => self::client_ip(),
            'user_agent' => self::user_agent(),
            'created_at' => $created_at,
        ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s'));

        return $wpdb->insert_id;
    }
}
