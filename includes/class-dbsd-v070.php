<?php
if (!defined('ABSPATH')) exit;

/**
 * v0.7 native app starter kit integration.
 * The actual Android/iOS client source is packaged under /native.
 * This class adds lightweight WordPress discovery endpoints and admin links so teams can wire apps safely.
 */
class DBSD_V070 {
    const VERSION = '0.7.4';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 60);
        add_shortcode('db_safedate_native_apps', array(__CLASS__, 'native_apps_shortcode'));
    }

    public static function routes() {
        register_rest_route('datebook-safedate/v1', '/mobile/app-config', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'app_config'),
            'permission_callback' => '__return_true'
        ));
    }

    public static function app_config() {
        return array(
            'ok' => true,
            'plugin_version' => defined('DBSD_VERSION') ? DBSD_VERSION : self::VERSION,
            'api_base' => esc_url_raw(rest_url('datebook-safedate/v1')),
            'mobile_endpoints' => array(
                'register_device' => '/mobile/register-device',
                'refresh_token' => '/mobile/refresh-token',
                'revoke_device' => '/mobile/revoke-device',
                'whoami' => '/mobile/whoami',
                'signed_location' => '/mobile/location/signed',
                'signed_device_health' => '/mobile/device-health/signed',
                'session_safety_status' => '/mobile/session/{id}/safety-status'
            ),
            'signature' => array(
                'algorithm' => 'HMAC-SHA256-Base64',
                'canonical_format' => "METHOD\nROUTE\nTIMESTAMP\nNONCE\nSHA256_RAW_BODY",
                'route_must_match_wp_rest_request_get_route' => true,
                'route_example' => '/datebook-safedate/v1/mobile/location/signed',
                'required_headers' => array('Authorization', 'X-DBSD-Device-Id', 'X-DBSD-Timestamp', 'X-DBSD-Nonce', 'X-DBSD-Signature')
            ),
            'notes' => array(
                'Registering a device still requires an authenticated WordPress session in v0.6/v0.7.',
                'Native apps should complete login through your WordPress/DateBook auth flow, then call register-device.',
                'Refresh-token and revoke-device requests must be HMAC-signed with the device signing secret in v0.7.10.',
                'Never log access tokens, refresh tokens, or signing secrets in production builds.'
            )
        );
    }

    public static function admin_menu() {
        add_submenu_page('dbsd', 'Native Apps v0.7', 'Native Apps v0.7', 'dbsd_manage_safety', 'dbsd-native-apps', array(__CLASS__, 'admin_page'));
    }

    public static function admin_page() {
        echo '<div class="wrap"><h1>DateBook SafeDate Native Apps v0.7</h1>';
        echo '<p>v0.7 packages minimal Android Kotlin and iOS SwiftUI starter clients that consume the hardened v0.6 mobile API.</p>';
        echo '<h2>Packaged starter kits</h2><ul>';
        echo '<li><code>native/android/SafeDateStarter/</code> - Android Kotlin starter app with signed location/device-health calls.</li>';
        echo '<li><code>native/ios/SafeDateStarter/</code> - iOS SwiftUI starter app with signed location/device-health calls.</li>';
        echo '<li><code>native/shared/</code> - shared API contract and test vectors.</li>';
        echo '</ul>';
        echo '<h2>Discovery endpoint</h2><p><code>' . esc_html(rest_url('datebook-safedate/v1/mobile/app-config')) . '</code></p>';
        echo '<p><strong>Production reminder:</strong> use HTTPS only, configure real WordPress/DateBook login, store tokens in Keychain/EncryptedSharedPreferences, and disable debug logging in release builds.</p>';
        echo '</div>';
    }

    public static function native_apps_shortcode() {
        ob_start(); ?>
        <div class="dbsd-card dbsd-native-apps">
            <h3>SafeDate Native App Starter Kits</h3>
            <p>Android and iOS starter projects are included in the plugin ZIP under <code>native/</code>.</p>
            <p>API config: <code><?php echo esc_html(rest_url('datebook-safedate/v1/mobile/app-config')); ?></code></p>
            <ul>
                <li>Android Kotlin client: signed location pings, token refresh, safety status.</li>
                <li>iOS SwiftUI client: signed location pings, token refresh, safety status.</li>
                <li>Shared test vectors and native integration checklist.</li>
            </ul>
        </div>
        <?php return ob_get_clean();
    }
}
