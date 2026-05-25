<?php
require_once __DIR__ . '/TestCase.php';

class PublicShareThrottlingTest extends DBSD_TestCase {
    public function test_public_trusted_share_endpoint_is_ip_rate_limited() {
        global $wpdb;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.50';
        update_option('dbsd_public_share_rate_limit_per_minute', 1);
        $session_id = $this->create_session('journey_started');
        $token = 'public-share-token-1234567890';
        $wpdb->insert($this->table('trusted_shares'), array(
            'session_id' => $session_id,
            'owner_user_id' => $this->traveler_id,
            'contact_name' => 'QA Contact',
            'contact_email' => 'qa@example.test',
            'token_hash' => hash('sha256', $token),
            'access_scope' => 'status_only',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => current_time('mysql', true),
        ));

        $first = $this->rest_request('GET', '/datebook-safedate/v1/share/' . $token);
        $this->assertSame(200, $first->get_status());

        $second = $this->rest_request('GET', '/datebook-safedate/v1/share/' . $token);
        $this->assertSame(429, $second->get_status());
        $this->assertSame('dbsd_rate_limited', $second->get_data()['code']);
    }
}
