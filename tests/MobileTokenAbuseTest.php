<?php
require_once __DIR__ . '/TestCase.php';

class MobileTokenAbuseTest extends DBSD_TestCase {
    private $access_token = 'unit-access-token';
    private $signing_secret = 'unit-signing-secret';
    private $device_uuid = 'unit-device-uuid-123';
    private $device_id;

    public function set_up() {
        parent::set_up();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_USER_AGENT'] = 'SafeDate PHPUnit';
        $this->device_id = $this->create_mobile_device($this->traveler_id, $this->device_uuid, $this->access_token, $this->signing_secret);
    }

    private function create_mobile_device($user_id, $device_uuid, $access_token, $signing_secret) {
        global $wpdb;
        $token_hash = $this->call_private_static('DBSD_V060', 'token_hash', array($access_token));
        $refresh_hash = $this->call_private_static('DBSD_V060', 'token_hash', array('unit-refresh-token'));
        $sealed = $this->call_private_static('DBSD_V060', 'seal_secret', array($signing_secret));
        $wpdb->insert($this->table('mobile_devices'), array(
            'user_id' => $user_id,
            'device_uuid' => $device_uuid,
            'platform' => 'phpunit',
            'device_name' => 'PHPUnit Device',
            'app_version' => '0.7.4-test',
            'access_token_hash' => $token_hash,
            'refresh_token_hash' => $refresh_hash,
            'signing_secret_sealed' => $sealed,
            'status' => 'active',
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'last_seen_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ));
        return (int) $wpdb->insert_id;
    }


    private function signed_refresh_request($refresh_token, $nonce = 'refresh-nonce-1', $timestamp = null, $body_extra = array(), $signature_override = null) {
        $timestamp = $timestamp ?: time();
        $route = '/datebook-safedate/v1/mobile/refresh-token';
        $body = array_merge(array('refresh_token' => $refresh_token, 'device_uuid' => $this->device_uuid), $body_extra);
        $json = wp_json_encode($body);
        $canonical = "POST\n{$route}\n{$timestamp}\n{$nonce}\n" . hash('sha256', $json);
        $signature = $signature_override ?: base64_encode(hash_hmac('sha256', $canonical, $this->signing_secret, true));
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-DBSD-Timestamp', (string) $timestamp);
        $request->set_header('X-DBSD-Nonce', $nonce);
        $request->set_header('X-DBSD-Device-Id', $this->device_uuid);
        $request->set_header('X-DBSD-Signature', $signature);
        $request->set_body($json);
        $request->set_body_params($body);
        return rest_get_server()->dispatch($request);
    }

    private function signed_request($body, $nonce = 'nonce-1', $timestamp = null, $signature_override = null) {
        $timestamp = $timestamp ?: time();
        $route = '/datebook-safedate/v1/mobile/location/signed';
        $json = wp_json_encode($body);
        $canonical = "POST\n{$route}\n{$timestamp}\n{$nonce}\n" . hash('sha256', $json);
        $signature = $signature_override ?: base64_encode(hash_hmac('sha256', $canonical, $this->signing_secret, true));

        $request = new WP_REST_Request('POST', $route);
        $request->set_header('Authorization', 'Bearer ' . $this->access_token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-DBSD-Timestamp', (string) $timestamp);
        $request->set_header('X-DBSD-Nonce', $nonce);
        $request->set_header('X-DBSD-Device-Id', $this->device_uuid);
        $request->set_header('X-DBSD-Signature', $signature);
        $request->set_body($json);
        return rest_get_server()->dispatch($request);
    }

    public function test_invalid_bearer_token_is_rejected_and_logged() {
        $response = $this->rest_request('GET', '/datebook-safedate/v1/mobile/whoami', array(), array('Authorization' => 'Bearer bad-token'));
        $this->assertSame(401, $response->get_status());
        $this->assertSame('dbsd_mobile_unauthorized', $response->get_data()['code']);
    }

    public function test_signed_location_rejects_bad_signature() {
        $session_id = $this->create_session('journey_started');
        $body = array('session_id' => $session_id, 'lat' => 43.653225, 'lng' => -79.383186, 'accuracy' => 10);

        $response = $this->signed_request($body, 'nonce-bad-sig', time(), 'invalid-signature');
        $this->assertSame(401, $response->get_status());
        $this->assertSame('dbsd_bad_signature', $response->get_data()['code']);
    }

    public function test_signed_location_rejects_stale_timestamp() {
        $session_id = $this->create_session('journey_started');
        $body = array('session_id' => $session_id, 'lat' => 43.653225, 'lng' => -79.383186, 'accuracy' => 10);

        $response = $this->signed_request($body, 'nonce-stale', time() - 3600);
        $this->assertSame(401, $response->get_status());
        $this->assertSame('dbsd_signature_stale', $response->get_data()['code']);
    }

    public function test_replay_nonce_is_rejected_after_successful_request() {
        $session_id = $this->create_session('journey_started');
        $body = array('session_id' => $session_id, 'lat' => 43.653225, 'lng' => -79.383186, 'accuracy' => 10);

        $first = $this->signed_request($body, 'nonce-replay', time());
        $this->assertSame(200, $first->get_status());
        $this->assertTrue($first->get_data()['ok']);

        $second = $this->signed_request($body, 'nonce-replay', time());
        $this->assertSame(409, $second->get_status());
        $this->assertSame('dbsd_replay_detected', $second->get_data()['code']);
    }

    public function test_mobile_location_endpoint_rate_limits_per_device() {
        update_option('dbsd_mobile_location_rate_limit_per_minute', 1);
        $session_id = $this->create_session('journey_started');

        $first = $this->signed_request(array('session_id' => $session_id, 'lat' => 43.65, 'lng' => -79.38), 'nonce-rate-1', time());
        $this->assertSame(200, $first->get_status());

        $second = $this->signed_request(array('session_id' => $session_id, 'lat' => 43.66, 'lng' => -79.39), 'nonce-rate-2', time());
        $this->assertSame(429, $second->get_status());
        $this->assertSame('dbsd_rate_limited', $second->get_data()['code']);
    }

    public function test_refresh_token_requires_valid_hmac_signature() {
        $response = $this->signed_refresh_request('unit-refresh-token', 'refresh-bad-sig', time(), array(), 'bad-signature');
        $this->assertSame(401, $response->get_status());
        $this->assertSame('dbsd_bad_signature', $response->get_data()['code']);
    }

    public function test_refresh_token_rotation_rejects_reuse_of_old_refresh_token() {
        $first = $this->signed_refresh_request('unit-refresh-token', 'refresh-good-1', time());
        $this->assertSame(200, $first->get_status());
        $this->assertTrue($first->get_data()['ok']);

        $second = $this->signed_refresh_request('unit-refresh-token', 'refresh-good-2', time());
        $this->assertSame(401, $second->get_status());
        $this->assertSame('dbsd_refresh_reused', $second->get_data()['code']);
    }

    public function test_revoked_device_cannot_use_whoami() {
        global $wpdb;
        $wpdb->update($this->table('mobile_devices'), array('status' => 'revoked', 'revoked_at' => current_time('mysql', true)), array('id' => $this->device_id));
        $response = $this->rest_request('GET', '/datebook-safedate/v1/mobile/whoami', array(), array('Authorization' => 'Bearer ' . $this->access_token));
        $this->assertSame(401, $response->get_status());
        $this->assertSame('dbsd_mobile_unauthorized', $response->get_data()['code']);
    }

}
