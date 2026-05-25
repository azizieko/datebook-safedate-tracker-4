<?php
require_once __DIR__ . '/TestCase.php';
class TrustedProxyThrottlingTest extends DBSD_TestCase {
    public function test_untrusted_x_forwarded_for_is_ignored() { update_option('dbsd_trusted_proxy_cidrs', ''); $_SERVER['REMOTE_ADDR']='203.0.113.10'; $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.99'; $this->assertSame('203.0.113.10', DBSD_Audit::client_ip()); }
    public function test_trusted_proxy_allows_forwarded_client_ip() { update_option('dbsd_trusted_proxy_cidrs', '203.0.113.0/24'); $_SERVER['REMOTE_ADDR']='203.0.113.10'; $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.99'; $this->assertSame('198.51.100.99', DBSD_Audit::client_ip()); }
}
