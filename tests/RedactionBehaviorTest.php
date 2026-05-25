<?php
require_once __DIR__ . '/TestCase.php';

class RedactionBehaviorTest extends DBSD_TestCase {
    public function test_redaction_rounds_coordinates_and_removes_movement_metadata() {
        $payload = array(
            'lat' => 43.653225,
            'lng' => -79.383186,
            'speed' => 8.7,
            'heading' => 224,
            'accuracy' => 5,
        );

        $redacted = DBSD_State::redact_location_payload($payload, 'approximate');
        $this->assertSame(43.65, $redacted['lat']);
        $this->assertSame(-79.38, $redacted['lng']);
        $this->assertArrayNotHasKey('speed', $redacted);
        $this->assertArrayNotHasKey('heading', $redacted);
        $this->assertTrue($redacted['location_redacted']);
        $this->assertSame('approximate_2_decimal_degrees', $redacted['location_precision']);
        $this->assertSame(100, $redacted['accuracy']);
    }

    public function test_traveler_gets_exact_audit_location_but_host_gets_redacted_location() {
        global $wpdb;
        $session_id = $this->create_session('journey_started');
        DBSD_Audit::log_event($session_id, $this->traveler_id, 'location_ping', array(
            'lat' => 43.653225,
            'lng' => -79.383186,
            'speed' => 8.7,
            'heading' => 224,
        ));

        wp_set_current_user($this->host_id);
        $host_response = $this->rest_request('GET', '/datebook-safedate/v1/session/' . $session_id . '/audit');
        $this->assertSame(200, $host_response->get_status());
        $host_payload = json_decode($host_response->get_data()['events'][0]->event_payload, true);
        $this->assertSame(43.65, $host_payload['lat']);
        $this->assertSame(-79.38, $host_payload['lng']);
        $this->assertArrayNotHasKey('speed', $host_payload);
        $this->assertObjectNotHasAttribute('ip_address', $host_response->get_data()['events'][0]);

        wp_set_current_user($this->traveler_id);
        $traveler_response = $this->rest_request('GET', '/datebook-safedate/v1/session/' . $session_id . '/audit');
        $this->assertSame(200, $traveler_response->get_status());
        $traveler_payload = json_decode($traveler_response->get_data()['events'][0]->event_payload, true);
        $this->assertSame(43.653225, (float) $traveler_payload['lat']);
        $this->assertSame(-79.383186, (float) $traveler_payload['lng']);
        $this->assertSame(8.7, (float) $traveler_payload['speed']);
    }

    public function test_locations_endpoint_buckets_accuracy_for_host() {
        global $wpdb;
        $session_id = $this->create_session('journey_started');
        $wpdb->insert($this->table('locations'), array(
            'session_id' => $session_id,
            'user_id' => $this->traveler_id,
            'lat' => 43.653225,
            'lng' => -79.383186,
            'accuracy' => 5,
            'recorded_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
        ));
        wp_set_current_user($this->host_id);
        $response = $this->rest_request('GET', '/datebook-safedate/v1/session/' . $session_id . '/locations');
        $this->assertSame(200, $response->get_status());
        $row = $response->get_data()['locations'][0];
        $this->assertSame('43.65', (string) $row->lat);
        $this->assertSame('100', (string) $row->accuracy);
    }

    public function test_export_redacts_locations_and_buckets_accuracy_for_host() {
        global $wpdb;
        $session_id = $this->create_session('journey_started');
        $wpdb->insert($this->table('locations'), array(
            'session_id' => $session_id,
            'user_id' => $this->traveler_id,
            'lat' => 43.653225,
            'lng' => -79.383186,
            'accuracy' => 5,
            'recorded_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
        ));
        wp_set_current_user($this->host_id);
        $response = $this->rest_request('GET', '/datebook-safedate/v1/session/' . $session_id . '/export');
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $this->assertSame('approximate', $data['redaction_mode']);
        $location = $data['locations'][0];
        $this->assertSame('43.65', (string) $location->lat);
        $this->assertSame('100', (string) $location->accuracy);
    }

}
