<?php

class ExactLocationPermissionTest extends DBSD_TestCase {
    public function test_host_locations_are_approximate_without_exact_location_permission() {
        global $wpdb;
        $session_id = $this->create_session('journey_started');
        $wpdb->insert($this->table('locations'), array(
            'session_id' => $session_id,
            'user_id' => $this->traveler_id,
            'lat' => 43.653225,
            'lng' => -79.383186,
            'accuracy' => 12,
            'recorded_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
        ));
        update_option('dbsd_show_exact_location_to_host', 'no');
        wp_set_current_user($this->host_id);
        $data = $this->rest_request('GET', '/datebook-safedate/v1/session/' . $session_id . '/locations')->get_data();
        $this->assertTrue($data['ok']);
        $this->assertEquals(43.65, (float)$data['locations'][0]->lat);
        $this->assertEquals(-79.38, (float)$data['locations'][0]->lng);
        $this->assertEquals(100, (int)$data['locations'][0]->accuracy);
        $this->assertObjectNotHasAttribute('speed', $data['locations'][0]);
    }
}
