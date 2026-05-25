<?php
require_once __DIR__ . '/TestCase.php';

class StateTransitionsTest extends DBSD_TestCase {
    public function test_traveler_can_start_journey_only_from_ready_state() {
        $session_id = $this->create_session('ready');
        $session = $this->get_session($session_id);

        $this->assertTrue(DBSD_State::assert_transition($session, 'traveler', 'journey_start'));

        $session->status = 'pending_consent';
        $result = DBSD_State::assert_transition($session, 'traveler', 'journey_start');
        $this->assertWPError($result);
        $this->assertSame('dbsd_invalid_session_state', $result->get_error_code());
    }

    public function test_host_cannot_start_traveler_journey_or_claim_arrival() {
        $session_id = $this->create_session('ready');
        $session = $this->get_session($session_id);

        $journey = DBSD_State::assert_transition($session, 'host', 'journey_start');
        $this->assertWPError($journey);
        $this->assertSame('dbsd_invalid_actor_for_action', $journey->get_error_code());

        $session->status = 'journey_started';
        $arrival = DBSD_State::assert_transition($session, 'host', 'arrival_claim');
        $this->assertWPError($arrival);
        $this->assertSame('dbsd_invalid_actor_for_action', $arrival->get_error_code());
    }

    public function test_arrival_and_departure_follow_strict_order() {
        $session_id = $this->create_session('journey_started');
        $session = $this->get_session($session_id);

        $this->assertTrue(DBSD_State::assert_transition($session, 'traveler', 'arrival_claim'));

        $session->status = 'arrival_claimed';
        $this->assertTrue(DBSD_State::assert_transition($session, 'host', 'arrival_respond'));

        $early_departure = DBSD_State::assert_transition($session, 'host', 'departure_claim');
        $this->assertWPError($early_departure);
        $this->assertSame('dbsd_invalid_session_state', $early_departure->get_error_code());

        $session->status = 'arrival_confirmed';
        $this->assertTrue(DBSD_State::assert_transition($session, 'host', 'departure_claim'));

        $session->status = 'departure_claimed';
        $this->assertTrue(DBSD_State::assert_transition($session, 'traveler', 'departure_respond'));
    }

    public function test_rest_journey_start_rejects_wrong_actor() {
        $session_id = $this->create_session('ready');
        wp_set_current_user($this->host_id);

        $response = $this->rest_request('POST', '/datebook-safedate/v1/journey/start', array('session_id' => $session_id));
        $this->assertSame(403, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('dbsd_invalid_actor_for_action', $data['code']);
    }
}
