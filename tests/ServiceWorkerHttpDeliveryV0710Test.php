<?php
class ServiceWorkerHttpDeliveryV0710Test extends DBSD_TestCase {
    public function test_service_worker_status_reports_root_url_and_readable_asset() {
        $status = DBSD_V075::service_worker_status();
        $this->assertTrue($status['ok']);
        $this->assertStringEndsWith('/dbsd-sw.js', $status['service_worker_url']);
        $this->assertTrue($status['asset_readable']);
        $this->assertArrayHasKey('service_worker_allowed_scope', $status);
    }
}
