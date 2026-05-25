<?php

class NativePairingHardeningTest extends DBSD_TestCase {
    public function set_up() {
        parent::set_up();
        update_option('dbsd_pairing_max_attempts', 3);
    }

    public function test_pair_device_requires_pairing_id_and_code() {
        wp_set_current_user(0);
        $response = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
            'code' => 'ABCDEF1234',
            'device_uuid' => 'android-device-001',
        ));
        $this->assertEquals(400, $response->get_status());
    }

    public function test_wrong_code_increments_attempt_count_and_locks_code() {
        global $wpdb;
        wp_set_current_user($this->traveler_id);
        $created = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array())->get_data();
        wp_set_current_user(0);

        for ($i = 0; $i < 3; $i++) {
            $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
                'pairing_id' => $created['pairing_id'],
                'code' => 'BADCODE999',
                'device_uuid' => 'bad-device-' . $i,
            ));
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT attempt_count, locked_at FROM ' . $this->table('mobile_pairing_codes') . ' WHERE id=%d', $created['pairing_id']));
        $this->assertGreaterThanOrEqual(3, (int)$row->attempt_count);
        $this->assertNotEmpty($row->locked_at);

        $valid_after_lock = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
            'pairing_id' => $created['pairing_id'],
            'code' => $created['code'],
            'device_uuid' => 'ios-device-locked',
        ));
        $this->assertEquals(403, $valid_after_lock->get_status());
    }

    public function test_pairing_claim_sets_intermediate_claiming_state_atomically() {
        wp_set_current_user($this->traveler_id);
        $created = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array())->get_data();
        wp_set_current_user(0);

        $first = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
            'pairing_id' => $created['pairing_id'],
            'code' => $created['code'],
            'device_uuid' => 'atomic-device-1',
            'platform' => 'android',
        ));
        $this->assertEquals(200, $first->get_status());

        $second = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
            'pairing_id' => $created['pairing_id'],
            'code' => $created['code'],
            'device_uuid' => 'atomic-device-2',
            'platform' => 'android',
        ));
        $this->assertContains($second->get_status(), array(403, 409));
    }
}
