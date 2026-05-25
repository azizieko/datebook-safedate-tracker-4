<?php
require_once __DIR__ . '/TestCase.php';

class CapabilityHardeningTest extends DBSD_TestCase {
    public function test_administrator_role_receives_dedicated_safedate_capability() {
        DBSD_V074::grant_caps();
        $role = get_role('administrator');
        $this->assertTrue($role->has_cap('dbsd_manage_safety'));
    }

    public function test_admin_menus_use_dedicated_capability_in_source() {
        $admin_source = file_get_contents(DBSD_PLUGIN_DIR . 'includes/class-dbsd-admin.php');
        $this->assertStringContainsString('dbsd_manage_safety', $admin_source);
        $this->assertStringNotContainsString("'manage_options'", $admin_source);
    }
}
