<?php
require_once __DIR__ . '/TestCase.php';
class CryptoHardeningTest extends DBSD_TestCase {
    public function test_mobile_api_source_does_not_create_plaintext_secret_fallback() { $source=file_get_contents(DBSD_PLUGIN_DIR.'includes/class-dbsd-v060.php'); $this->assertStringNotContainsString("return 'plain:'", $source); $this->assertStringContainsString('sodium_secretbox', $source); $this->assertStringContainsString('aes256gcm', $source); }
}
