<?php

class PairingTelemetryTest extends DBSD_TestCase {
    public function test_pairing_attempts_are_recorded_for_successful_claim() {
        global $wpdb;
        if (class_exists('DBSD_V078')) DBSD_V078::maybe_upgrade();
        wp_set_current_user($this->traveler_id);
        $created = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pairing-code', array())->get_data();
        wp_set_current_user(0);
        $claim = $this->rest_request('POST', '/datebook-safedate/v1/mobile/pair-device', array(
            'pairing_id' => $created['pairing_id'],
            'code' => $created['code'],
            'device_uuid' => 'telemetry-device-1',
            'platform' => 'android'
        ))->get_data();
        $this->assertTrue($claim['ok']);
        $count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $this->table('mobile_pairing_attempts') . ' WHERE pairing_id=%d AND result=%s', $created['pairing_id'], 'claimed'));
        $this->assertGreaterThanOrEqual(1, $count);
    }
}
