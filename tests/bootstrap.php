<?php
/**
 * PHPUnit bootstrap.
 *
 * @package IWorksIE\SearchReplace
 */

$iwiesr_root = dirname( __DIR__ );

require_once $iwiesr_root . '/vendor/autoload.php';

$iwiesr_prefixed = $iwiesr_root . '/vendor-prefixed/autoload.php';
if ( is_readable( $iwiesr_prefixed ) ) {
	require_once $iwiesr_prefixed;
}

$iwiesr_suite = getenv( 'IWIESR_TESTSUITE' );
if ( ! $iwiesr_suite ) {
	$iwiesr_suite = 'unit';
}

if ( 'integration' === $iwiesr_suite ) {
	$iwiesr_wp_tests = getenv( 'WP_TESTS_DIR' );
	if ( ! $iwiesr_wp_tests ) {
		$iwiesr_wp_tests = $iwiesr_root . '/tests/wordpress/wordpress-tests-lib';
	}
	if ( ! is_readable( $iwiesr_wp_tests . '/includes/functions.php' ) ) {
		fwrite( STDERR, "WP test suite not found at {$iwiesr_wp_tests}. Run bin/install-wp-tests.sh first.\n" );
		exit( 1 );
	}
	require_once $iwiesr_wp_tests . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $iwiesr_root ) {
			$plugin = $iwiesr_root . '/iworksie-search-replace.php';
			if ( is_readable( $plugin ) ) {
				require $plugin;
			}
		}
	);

	require_once $iwiesr_wp_tests . '/includes/bootstrap.php';
}
