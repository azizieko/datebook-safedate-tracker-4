<?php

class NativeProductionWiringTest extends DBSD_TestCase {
    public function test_public_signature_vector_does_not_expose_raw_secret() {
        $data = DBSD_V078::signature_test_vector();
        $this->assertArrayNotHasKey('secret', $data);
        $this->assertArrayHasKey('test_secret_reference', $data);
        $this->assertEquals('/datebook-safedate/v1/mobile/refresh-token', $data['canonical_route']);
    }

    public function test_pairing_attempt_cleanup_deletes_old_rows() {
        global $wpdb;
        if (class_exists('DBSD_V078')) DBSD_V078::maybe_upgrade();
        if (class_exists('DBSD_V079')) DBSD_V079::maybe_upgrade();
        $wpdb->insert($this->table('mobile_pairing_attempts'), array(
            'pairing_id' => null,
            'user_id' => null,
            'device_uuid' => 'old-device',
            'result' => 'failed',
            'reason' => 'old_attempt',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'metadata' => '{}',
            'created_at' => gmdate('Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS),
        ));
        update_option('dbsd_pairing_attempt_retention_days', 90);
        $deleted = DBSD_V079::cleanup_pairing_attempts();
        $this->assertSame(1, (int) $deleted);
    }

    public function test_android_and_ios_starters_reference_secure_storage_and_signed_revoke() {
        $android = file_get_contents(DBSD_PLUGIN_DIR . 'native/android/SafeDateStarter/app/src/main/java/com/datebook/safedate/MainActivity.kt');
        $ios = file_get_contents(DBSD_PLUGIN_DIR . 'native/ios/SafeDateStarter/SafeDateStarter/ContentView.swift');
        $this->assertStringContainsString('KeystoreCredentialStore(this)', $android);
        $this->assertStringContainsString('signedRevokeAndLogout', $android);
        $this->assertStringContainsString('KeychainStore.loadCredentials()', $ios);
        $this->assertStringContainsString('signedRevokeAndLogout', $ios);
    }
}
