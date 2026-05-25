<?php
if (!defined('ABSPATH')) exit;

/**
 * Central SafeDate session state-machine guard for v0.7.1.
 * Keeps safety actions in an auditable order and prevents replay/out-of-order claims.
 */
class DBSD_State {
    public static function role($session, $user_id = null) {
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        if (!$session) return 'unknown';
        if ((int)$session->traveler_user_id === $user_id) return 'traveler';
        if ((int)$session->host_user_id === $user_id) return 'host';
        if (current_user_can('dbsd_manage_safety')) return 'admin';
        return 'unknown';
    }

    public static function allowed_statuses($action) {
        $map = array(
            'consent_accept' => array('pending_consent'),
            'journey_start' => array('ready'),
            'location_ping' => array('journey_started','arrival_claimed','arrival_confirmed','departure_claimed','departure_disputed'),
            'arrival_claim' => array('journey_started'),
            'arrival_respond' => array('arrival_claimed'),
            'departure_claim' => array('arrival_confirmed'),
            'departure_respond' => array('departure_claimed'),
            'journey_stop' => array('journey_started','arrival_claimed','arrival_confirmed','departure_claimed','departure_disputed'),
            'sos' => array('ready','journey_started','arrival_claimed','arrival_confirmed','arrival_disputed','departure_claimed','departure_disputed'),
            'checkin' => array('ready','journey_started','arrival_claimed','arrival_confirmed','arrival_disputed','departure_claimed','departure_disputed'),
        );
        return isset($map[$action]) ? $map[$action] : array();
    }

    public static function assert_transition($session, $actor_role, $action) {
        if (!$session) return new WP_Error('dbsd_missing_session', __('SafeDate session not found.', 'datebook-safedate'), array('status' => 404));
        if ($actor_role === 'admin') return true;
        $role_map = array(
            'journey_start' => array('traveler'),
            'journey_stop' => array('traveler'),
            'location_ping' => array('traveler'),
            'arrival_claim' => array('traveler'),
            'arrival_respond' => array('host'),
            'departure_claim' => array('host'),
            'departure_respond' => array('traveler'),
            'sos' => array('host','traveler'),
            'checkin' => array('host','traveler'),
            'consent_accept' => array('host','traveler'),
        );
        if (isset($role_map[$action]) && !in_array($actor_role, $role_map[$action], true)) {
            return new WP_Error('dbsd_invalid_actor_for_action', __('This participant cannot perform that SafeDate action.', 'datebook-safedate'), array('status' => 403));
        }
        $allowed = self::allowed_statuses($action);
        if ($allowed && !in_array((string)$session->status, $allowed, true)) {
            return new WP_Error('dbsd_invalid_session_state', sprintf(__('Action %1$s is not allowed while session is %2$s.', 'datebook-safedate'), $action, $session->status), array('status' => 409, 'current_status' => $session->status, 'allowed_statuses' => $allowed));
        }
        return true;
    }

    public static function redact_location_payload($payload, $mode = 'approximate') {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) $payload = $decoded;
        }
        if (!is_array($payload)) return $payload;
        foreach (array('lat','latitude','meeting_lat') as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) $payload[$key] = $mode === 'exact' ? (float)$payload[$key] : round((float)$payload[$key], 2);
        }
        foreach (array('lng','longitude','meeting_lng') as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) $payload[$key] = $mode === 'exact' ? (float)$payload[$key] : round((float)$payload[$key], 2);
        }
        if ($mode !== 'exact') {
            foreach (array('speed','heading','bearing','altitude') as $key) unset($payload[$key]);
            if (isset($payload['accuracy'])) $payload['accuracy'] = self::bucket_accuracy($payload['accuracy'], 'approximate');
            $payload['location_redacted'] = true;
            $payload['location_precision'] = 'approximate_2_decimal_degrees';
        }
        return $payload;
    }


    public static function bucket_accuracy($accuracy, $mode = 'approximate') {
        if ($accuracy === null || $accuracy === '') return null;
        $accuracy = (float) $accuracy;
        if ($mode === 'exact') return $accuracy;
        if ($accuracy <= 100) return 100;
        if ($accuracy <= 1000) return 1000;
        return 10000;
    }

    public static function viewer_location_mode($session) {
        if (current_user_can('dbsd_manage_safety')) return 'exact';
        $uid = get_current_user_id();
        if ($session && (int)$session->traveler_user_id === $uid) return 'exact';
        $show_exact = get_option('dbsd_show_exact_location_to_host', 'no') === 'yes';
        return $show_exact ? 'exact' : 'approximate';
    }
}
