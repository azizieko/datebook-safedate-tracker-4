<?php

class PushReadinessBehaviorTest extends DBSD_TestCase {
    public function set_up() {
        parent::set_up();
        if (class_exists('DBSD_V075')) DBSD_V075::maybe_upgrade();
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin);
    }

    public function test_push_readiness_reports_missing_production_dependencies() {
        update_option('dbsd_push_enabled', 'yes');
        update_option('dbsd_push_vapid_public_key', '');
        update_option('dbsd_push_vapid_private_key', '');
        $response = $this->rest_request('GET', '/datebook-safedate/v1/push/readiness');
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertFalse($data['ready_for_background_push']);
        $this->assertContains('browser_polling_only', array($data['fallback_mode']));
        $this->assertNotEmpty($data['warnings']);
    }

    public function test_service_worker_status_exposes_root_scope_diagnostics() {
        $response = $this->rest_request('GET', '/datebook-safedate/v1/pwa/service-worker-status');
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertStringContainsString('/dbsd-sw.js', $data['service_worker_url']);
        $this->assertArrayHasKey('asset_readable', $data);
        $this->assertArrayHasKey('service_worker_allowed_scope', $data);
    }
}
