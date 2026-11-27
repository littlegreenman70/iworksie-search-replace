<?php
/**
 * Plugin Name:       iWorks.ie Search & Replace
 * Plugin URI:        https://www.iworks.ie
 * Description:       Search and replace across your database with a free row-by-row preview, a Change domain preset, serialized-safe editing and one-click undo.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            iWorks.ie
 * Author URI:        https://www.iworks.ie
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       iworksie-search-replace
 * Domain Path:       /languages
 *
 * @package IWorksIE\SearchReplace
 */

defined( 'ABSPATH' ) || exit;

define( 'IWIESR_VERSION', '1.0.0' );
define( 'IWIESR_FILE', __FILE__ );
define( 'IWIESR_PATH', plugin_dir_path( __FILE__ ) );
define( 'IWIESR_URL', plugin_dir_url( __FILE__ ) );
define( 'IWIESR_MIN_PHP', '7.4' );
define( 'IWIESR_MIN_WP', '6.5' );

/**
 * Admin notice when requirements are not met.
 *
 * @param string $message Notice text.
 * @return void
 */
function iwiesr_requirements_notice( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	);
}

if ( version_compare( PHP_VERSION, IWIESR_MIN_PHP, '<' ) ) {
	iwiesr_requirements_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'iWorks.ie Search & Replace requires PHP %1$s or higher. This site is running PHP %2$s.', 'iworksie-search-replace' ),
			IWIESR_MIN_PHP,
			PHP_VERSION
		)
	);
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, IWIESR_MIN_WP, '<' ) ) {
	iwiesr_requirements_notice(
		sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'iWorks.ie Search & Replace requires WordPress %1$s or higher. This site is running WordPress %2$s.', 'iworksie-search-replace' ),
			IWIESR_MIN_WP,
			$wp_version
		)
	);
	return;
}

$iwiesr_prefixed = IWIESR_PATH . 'vendor-prefixed/autoload.php';
if ( ! is_readable( $iwiesr_prefixed ) ) {
	iwiesr_requirements_notice(
		__( 'iWorks.ie Search & Replace is missing its vendor-prefixed library. Run composer install, or install the release zip from wordpress.org.', 'iworksie-search-replace' )
	);
	return;
}
require_once $iwiesr_prefixed;

$iwiesr_autoload = IWIESR_PATH . 'vendor/autoload.php';
if ( is_readable( $iwiesr_autoload ) ) {
	require_once $iwiesr_autoload;
} else {
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'IWorksIE\\SearchReplace\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = IWIESR_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook(
	__FILE__,
	static function () {
		\IWorksIE\SearchReplace\Database\BackupStore::install();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\IWorksIE\SearchReplace\Vendor\IWorksIE\Core\Core' ) ) {
			iwiesr_requirements_notice(
				__( 'iWorks.ie Search & Replace could not load its shared tools library.', 'iworksie-search-replace' )
			);
			return;
		}
		// Boot after init so translations are not loaded too early (WP 6.7+).
		add_action(
			'init',
			static function () {
				\IWorksIE\SearchReplace\Plugin::instance()->boot();
			},
			5
		);
	},
	5
);
