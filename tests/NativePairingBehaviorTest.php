<?php

class NativePairingBehaviorTest extends DBSD_TestCase {
    public function set_up() {
        parent::set_up();
        if (class_exists('DBSD_V075')) DBSD_V075::maybe_upgrade();
    }

    public function test_logged_in_user_can_create_one_time_pairing_code() {
        wp_set_current_user($this->traveler_id);
        $response = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array());
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertNotEmpty($data['code']);
        $this->assertNotEmpty($data['pairing_id']);
        $this->assertNotEmpty($data['expires_at']);
    }

    public function test_native_app_can_claim_pairing_code_once_and_get_tokens() {
        wp_set_current_user($this->traveler_id);
        $created = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array())->get_data();
        wp_set_current_user(0);
        $claim = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
            'pairing_id' => $created['pairing_id'],
            'code' => $created['code'],
            'device_uuid' => 'ios-device-001',
            'platform' => 'ios',
            'device_name' => 'QA iPhone',
            'app_version' => '0.7.6-test',
        ))->get_data();
        $this->assertTrue($claim['ok']);
        $this->assertEquals($this->traveler_id, $claim['user_id']);
        $this->assertNotEmpty($claim['access_token']);
        $this->assertNotEmpty($claim['refresh_token']);
        $this->assertNotEmpty($claim['signing_secret']);

        $second = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
            'pairing_id' => $created['pairing_id'],
            'pairing_id' => $created['pairing_id'],
            'code' => $created['code'],
            'device_uuid' => 'ios-device-002',
        ));
        $this->assertEquals(403, $second->get_status());
    }
}
