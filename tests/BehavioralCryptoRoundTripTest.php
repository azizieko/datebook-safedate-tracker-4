<?php

class BehavioralCryptoRoundTripTest extends DBSD_TestCase {
    public function test_new_mobile_pairing_secret_is_not_stored_in_plaintext() {
        global $wpdb;
        if (class_exists('DBSD_V075')) DBSD_V075::maybe_upgrade();
        wp_set_current_user($this->traveler_id);
        $created = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array())->get_data();
        wp_set_current_user(0);
        $claim = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array('pairing_id' => $created['pairing_id'], 'code' => $created['code'], 'device_uuid' => 'android-crypto-1'))->get_data();
        $this->assertTrue($claim['ok']);
        $sealed = $wpdb->get_var($wpdb->prepare('SELECT signing_secret_sealed FROM ' . $this->table('mobile_devices') . ' WHERE id=%d', $claim['device_id']));
        $this->assertNotEquals($claim['signing_secret'], $sealed);
        $this->assertMatchesRegularExpression('/^(sodium_secretbox|aes256gcm):/', $sealed);
        $this->assertStringNotContainsString($claim['signing_secret'], $sealed);
    }
}
