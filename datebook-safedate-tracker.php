<?php
/**
 * Plugin Name: DateBook SafeDate Tracker
 * Description: Consent-based dating safety tracker for DateBook/WordPress. Adds SafeDate sessions, arrival/departure confirmations, live location pings, secure audit logs, PWA support, push/browser notifications, live admin monitoring, and hardened native mobile APIs, and native Android/iOS starter kits. Adds automated PHPUnit coverage for safety/security critical paths.
 * Version: 0.7.10
 * Author: SafeDate MVP
 * Text Domain: datebook-safedate
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DBSD_VERSION', '0.7.10');
define('DBSD_PLUGIN_FILE', __FILE__);
define('DBSD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBSD_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-activator.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-audit.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-state.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-rest.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-shortcodes.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-admin.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-monitor.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v030.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v040.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v050.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v060.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v070.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v074.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v075.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v078.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v079.php';
require_once DBSD_PLUGIN_DIR . 'includes/class-dbsd-v0710.php';

register_activation_hook(__FILE__, array('DBSD_Activator', 'activate'));

add_action('plugins_loaded', function () {
    DBSD_REST::init();
    DBSD_Shortcodes::init();
    DBSD_Admin::init();
    DBSD_Monitor::init();
    DBSD_V030::init();
    DBSD_V040::init();
    DBSD_V050::init();
    DBSD_V060::init();
    DBSD_V070::init();
    DBSD_V074::init();
    DBSD_V075::init();
    DBSD_V078::init();
    DBSD_V079::init();
    DBSD_V0710::init();
});

add_action('wp_enqueue_scripts', function () {
    wp_register_style('dbsd-frontend', DBSD_PLUGIN_URL . 'assets/css/dbsd-frontend.css', array(), DBSD_VERSION);
    wp_register_script('dbsd-frontend', DBSD_PLUGIN_URL . 'assets/js/dbsd-frontend.js', array(), DBSD_VERSION, true);
    wp_localize_script('dbsd-frontend', 'DBSD', array(
        'restUrl' => esc_url_raw(rest_url('datebook-safedate/v1')),
        'nonce' => wp_create_nonce('wp_rest'),
        'currentUserId' => get_current_user_id(),
        'pingIntervalMs' => (int) apply_filters('dbsd_location_ping_interval_ms', 15000),
        'strings' => array(
            'geoUnsupported' => __('Geolocation is not supported on this device/browser.', 'datebook-safedate'),
            'geoDenied' => __('Location permission was denied or unavailable.', 'datebook-safedate'),
            'started' => __('Journey tracking started.', 'datebook-safedate'),
            'stopped' => __('Tracking stopped.', 'datebook-safedate'),
            'saved' => __('Saved.', 'datebook-safedate'),
            'error' => __('Something went wrong. Please try again.', 'datebook-safedate'),
        ),
    ));
});
