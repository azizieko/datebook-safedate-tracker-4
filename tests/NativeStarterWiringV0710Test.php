<?php
class NativeStarterWiringV0710Test extends DBSD_TestCase {
    public function test_android_api_requires_explicit_credential_store_and_version_is_updated() {
        $api = file_get_contents(DBSD_PLUGIN_DIR . 'native/android/SafeDateStarter/app/src/main/java/com/datebook/safedate/SafeDateApi.kt');
        $this->assertStringNotContainsString('= InMemoryCredentialStore()', $api);
        $gradle = file_get_contents(DBSD_PLUGIN_DIR . 'native/android/SafeDateStarter/app/build.gradle');
        $this->assertStringContainsString("versionName '0.7.10'", $gradle);
        $this->assertStringContainsString('versionCode 710', $gradle);
    }

    public function test_ios_readme_and_revoke_payload_include_refresh_token() {
        $readme = file_get_contents(DBSD_PLUGIN_DIR . 'native/ios/SafeDateStarter/README.md');
        $this->assertStringContainsString('v0.7.10', $readme);
        $swift = file_get_contents(DBSD_PLUGIN_DIR . 'native/ios/SafeDateStarter/SafeDateStarter/SafeDateApi.swift');
        $this->assertStringContainsString('"refresh_token": credentials.refreshToken', $swift);
    }
}
