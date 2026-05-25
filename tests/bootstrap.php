<?php
/**
 * PHPUnit bootstrap for DateBook SafeDate Tracker.
 *
 * Requires the WordPress PHPUnit test suite. Set WP_TESTS_DIR when it is not in
 * /tmp/wordpress-tests-lib.
 */

define('DBSD_TESTS_PLUGIN_FILE', dirname(__DIR__) . '/datebook-safedate-tracker.php');

$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "WordPress PHPUnit test suite not found. Run scripts/install-wp-tests.sh or set WP_TESTS_DIR.\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    require DBSD_TESTS_PLUGIN_FILE;
});

require $_tests_dir . '/includes/bootstrap.php';

// Load shared test fixtures once from the bootstrap. Several test files rely on
// DBSD_TestCase being available when PHPUnit includes tests in arbitrary order.
require_once __DIR__ . '/TestCase.php';

// Ensure plugin tables are available for tests that run before init hooks.
if (class_exists('DBSD_Activator')) {
    DBSD_Activator::activate();
}
foreach (array('DBSD_V030','DBSD_V040','DBSD_V050','DBSD_V060','DBSD_V074','DBSD_V075','DBSD_V078','DBSD_V079','DBSD_V0710') as $dbsd_upgrade_class) {
    if (class_exists($dbsd_upgrade_class) && method_exists($dbsd_upgrade_class, 'maybe_upgrade')) {
        call_user_func(array($dbsd_upgrade_class, 'maybe_upgrade'));
    }
}
