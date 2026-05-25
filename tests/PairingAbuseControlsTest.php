<?php

class PairingAbuseControlsTest extends DBSD_TestCase {
    public function test_pairing_code_creation_is_limited_per_user_per_hour() {
        if (class_exists('DBSD_V075')) DBSD_V075::maybe_upgrade();
        update_option('dbsd_pairing_max_codes_per_user_hour', 1);
        wp_set_current_user($this->traveler_id);

        $first = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array());
        $this->assertSame(200, $first->get_status());

        $second = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array());
        $this->assertSame(429, $second->get_status());
        $this->assertSame('dbsd_pairing_creation_limited', $second->get_data()['code']);
    }

    public function test_locked_pairing_status_is_persisted_after_failed_attempt_limit() {
        global $wpdb;
        if (class_exists('DBSD_V075')) DBSD_V075::maybe_upgrade();
        update_option('dbsd_pairing_max_attempts', 3);
        wp_set_current_user($this->traveler_id);
        $created = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array())->get_data();
        wp_set_current_user(0);

        for ($i = 0; $i < 3; $i++) {
            $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
                'pairing_id' => $created['pairing_id'],
                'code' => '0000000000',
                'device_uuid' => 'abuse-device-1',
            ));
        }

        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $this->table('mobile_pairing_codes') . ' WHERE id=%d', $created['pairing_id']));
        $this->assertSame('locked', $status);
    }
}
