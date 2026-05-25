<?php
class RevokeExpiredTokenBehaviorTest extends DBSD_TestCase {
    public function test_revoke_helper_exists_for_expired_access_signed_revoke() {
        $ref = new ReflectionClass('DBSD_V060');
        $this->assertTrue($ref->hasMethod('authenticate_mobile_for_revoke'));
        $source = file_get_contents(DBSD_PLUGIN_DIR . 'includes/class-dbsd-v060.php');
        $this->assertStringContainsString('find_device_by_bearer_or_refresh', $source);
        $this->assertStringContainsString('accepted_expired_access_token_with_valid_signature', $source);
    }
}
