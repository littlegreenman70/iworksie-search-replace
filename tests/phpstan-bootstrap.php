<?php
/**
 * PHPStan bootstrap stubs.
 *
 * @package IWorksIE\SearchReplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'IWIESR_VERSION' ) ) {
	define( 'IWIESR_VERSION', '1.0.3' );
}
if ( ! defined( 'IWIESR_FILE' ) ) {
	define( 'IWIESR_FILE', '/tmp/iworksie-search-replace/iworksie-search-replace.php' );
}
if ( ! defined( 'IWIESR_PATH' ) ) {
	define( 'IWIESR_PATH', '/tmp/iworksie-search-replace/' );
}
if ( ! defined( 'IWIESR_URL' ) ) {
	define( 'IWIESR_URL', 'https://example.com/wp-content/plugins/iworksie-search-replace/' );
}
if ( ! defined( 'IWIESR_MIN_PHP' ) ) {
	define( 'IWIESR_MIN_PHP', '7.4' );
}
if ( ! defined( 'IWIESR_MIN_WP' ) ) {
	define( 'IWIESR_MIN_WP', '6.5' );
}

if ( ! class_exists( '\IWorksIE\SearchReplace\Vendor\IWorksIE\Core\Core' ) && is_readable( dirname( __DIR__ ) . '/vendor-prefixed/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
}
