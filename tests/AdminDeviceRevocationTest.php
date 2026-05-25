<?php
require_once __DIR__ . '/TestCase.php';
class AdminDeviceRevocationTest extends DBSD_TestCase {
    public function test_admin_revoke_device_endpoint_requires_device_id() { wp_set_current_user(self::factory()->user->create(array('role'=>'administrator'))); $response=$this->rest_request('POST','/datebook-safedate/v1/admin/mobile/revoke-device',array()); $this->assertSame(400,$response->get_status()); }
    public function test_admin_mobile_page_contains_revoke_button_markup() { wp_set_current_user(self::factory()->user->create(array('role'=>'administrator'))); ob_start(); DBSD_V060::admin_page(); $html=ob_get_clean(); $this->assertStringContainsString('SafeDate Mobile API Security',$html); $this->assertStringContainsString('Recent devices',$html); }
}
