<?php
class PairingAbuseControlsV0710Test extends DBSD_TestCase {
    public function test_device_abuse_uses_pairing_attempt_telemetry_table() {
        $ref = new ReflectionClass('DBSD_V075');
        $method = $ref->getMethod('pairing_device_abuse_allowed');
        $method->setAccessible(true);
        update_option('dbsd_pairing_max_failed_per_device_hour', 2);
        DBSD_V078::record_pairing_attempt(123, 0, 'device-rate-test', 'failed', 'wrong_code');
        DBSD_V078::record_pairing_attempt(123, 0, 'device-rate-test', 'rejected', 'wrong_code');
        $this->assertFalse($method->invoke(null, 'device-rate-test'));
    }

    public function test_blocked_pairing_creation_attempt_is_recorded() {
        $user_id = $this->factory()->user->create();
        wp_set_current_user($user_id);
        update_option('dbsd_pairing_max_codes_per_user_hour', 1);
        $req = new WP_REST_Request('POST', '/datebook-safedate/v1/mobile/pairing-code');
        DBSD_V075::create_pairing_code($req);
        $second = DBSD_V075::create_pairing_code($req);
        $this->assertWPError($second);
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dbsd_mobile_pairing_attempts WHERE result='blocked' AND reason='pairing_creation_rate_limited'");
        $this->assertGreaterThanOrEqual(1, $count);
    }
}
