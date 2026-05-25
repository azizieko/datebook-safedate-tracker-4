<?php
require_once __DIR__ . '/TestCase.php';

class PWARegistrationTest extends DBSD_TestCase {
    public function test_manifest_uses_site_root_scope_and_required_icons() {
        $response = DBSD_V040::manifest();
        $this->assertInstanceOf('WP_REST_Response', $response);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertSame(home_url('/'), $data['start_url']);
        $this->assertSame(home_url('/'), $data['scope']);
        $this->assertSame('standalone', $data['display']);
        $this->assertNotEmpty($data['icons']);
        $this->assertStringContainsString('safedate-icon-192.svg', $data['icons'][0]['src']);
        $this->assertStringContainsString('safedate-icon-512.svg', $data['icons'][1]['src']);
    }

    public function test_frontend_pwa_script_registers_root_service_worker_url() {
        DBSD_V040::enqueue_frontend();
        $data = wp_scripts()->get_data('dbsd-v040', 'data');
        $this->assertIsString($data);
        $this->assertStringContainsString('dbsd-sw.js', $data);
        $this->assertStringContainsString(str_replace('/', '\\/', home_url('/dbsd-sw.js')), $data);
    }

    public function test_push_health_reports_missing_vapid_keys_for_common_shared_hosting_setup() {
        delete_option('dbsd_push_vapid_public_key');
        delete_option('dbsd_push_vapid_private_key');
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        $response = $this->rest_request('GET', '/datebook-safedate/v1/push/health');
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['vapid_public_key_configured']);
        $this->assertFalse($data['vapid_private_key_configured']);
        $this->assertSame(home_url('/dbsd-sw.js'), $data['service_worker_url']);
        $this->assertSame(home_url('/'), $data['service_worker_scope']);
        $this->assertArrayHasKey('service_worker_allowed_path', $data);
    }

    public function test_service_worker_url_remains_root_scoped_for_subdirectory_wordpress() {
        update_option('home', 'https://example.test/community');
        update_option('siteurl', 'https://example.test/community/wp');

        $response = DBSD_V040::manifest();
        $data = $response->get_data();
        $this->assertSame('https://example.test/community/', $data['scope']);
        $this->assertSame('https://example.test/community/', $data['start_url']);

        DBSD_V040::enqueue_frontend();
        $localized = wp_scripts()->get_data('dbsd-v040', 'data');
        $this->assertStringContainsString('https:\/\/example.test\/community\/dbsd-sw.js', $localized);
    }

    public function test_root_service_worker_path_match_supports_subdirectory_home_path() {
        update_option('home', 'https://example.test/community');
        $this->assertSame('https://example.test/community/dbsd-sw.js', home_url('/dbsd-sw.js'));
        $ref = new ReflectionClass('DBSD_V040');
        $this->assertTrue($ref->hasMethod('root_service_worker'));
    }
}
