<?php

class NativeSignatureCompatibilityTest extends DBSD_TestCase {
    public function test_canonical_route_matches_wordpress_rest_route() {
        if (class_exists('DBSD_V078')) DBSD_V078::maybe_upgrade();
        $this->assertSame('/datebook-safedate/v1/mobile/refresh-token', DBSD_V078::canonical_route('/mobile/refresh-token'));
        $this->assertSame('/datebook-safedate/v1/mobile/location/signed', DBSD_V078::canonical_route('/datebook-safedate/v1/mobile/location/signed'));
    }

    public function test_hmac_test_vector_matches_native_clients() {
        $body = '{"device_uuid":"device-123","refresh_token":"refresh-abc"}';
        $sig = DBSD_V078::hmac_signature('test-signing-secret', 'POST', '/mobile/refresh-token', 1710000000, 'nonce-12345', $body);
        $this->assertSame('Yzgfjb5q2pH/FRJ5pbLz9F6hkRLTKly8wtzi58RkG8E=', $sig);
    }
}
