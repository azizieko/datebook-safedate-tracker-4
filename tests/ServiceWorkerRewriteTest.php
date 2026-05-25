<?php
require_once __DIR__ . '/TestCase.php';

class ServiceWorkerRewriteTest extends DBSD_TestCase {
    public function test_v074_records_rewrite_flush_version_for_existing_installs() {
        delete_option('dbsd_v074_version');
        delete_option('dbsd_v040_rewrite_flushed_version');
        DBSD_V074::maybe_upgrade();
        $this->assertSame(DBSD_VERSION, get_option('dbsd_v040_rewrite_flushed_version'));
    }

    public function test_service_worker_serving_method_sets_allowed_scope_header_in_source() {
        $source = file_get_contents(DBSD_PLUGIN_DIR . 'includes/class-dbsd-v040.php');
        $this->assertStringContainsString('Service-Worker-Allowed', $source);
        $this->assertStringContainsString('dbsd-sw.js', $source);
        $this->assertStringContainsString('flush_rewrite_rules(false)', $source);
    }
}
