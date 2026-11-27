<?php
/**
 * Plugin bootstrap.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace;

use IWorksIE\SearchReplace\Admin\Ajax;
use IWorksIE\SearchReplace\Admin\Screens;
use IWorksIE\SearchReplace\Database\BackupStore;
use IWorksIE\SearchReplace\Jobs\SearchReplaceJob;
use IWorksIE\SearchReplace\Vendor\IWorksIE\Core\Core;

/**
 * Singleton plugin entry.
 */
final class Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @var Core|null
	 */
	private $core = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @return Core|null
	 */
	public function core() {
		return $this->core;
	}

	/**
	 * Capability required for every screen and action.
	 *
	 * On multisite only super admins may use it: users and usermeta are shared by
	 * every site, so a site admin must never be able to rewrite them.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Boot core, job, menu, AJAX.
	 *
	 * @return void
	 */
	public function boot() {
		if ( null !== $this->core ) {
			return;
		}

		$this->core = Core::boot(
			array(
				'plugin_file' => IWIESR_FILE,
				'slug'        => 'iworksie-search-replace',
				'prefix'      => 'iwiesr',
				'name'        => __( 'Search & Replace', 'iworksie-search-replace' ),
				'version'     => IWIESR_VERSION,
				'text_domain' => 'iworksie-search-replace',
				'capability'  => self::capability(),
				'wporg_url'   => 'https://wordpress.org/plugins/iworksie-search-replace/',
				'description' => __( 'Search and replace across your database with a free row-by-row preview and one-click undo.', 'iworksie-search-replace' ),
				'icon'        => 'dashicons-search',
				'tagline'     => __( 'See every change before it happens, and undo any run with one click.', 'iworksie-search-replace' ),
			)
		);

		$this->core->jobs()->add( new SearchReplaceJob() );

		$ajax = new Ajax( $this->core );
		$ajax->hooks();

		$screens = new Screens( $this->core, $ajax );
		$this->core->menu()->add_submenu(
			__( 'Search & Replace', 'iworksie-search-replace' ),
			__( 'Search & Replace', 'iworksie-search-replace' ),
			'iworksie-search-replace',
			array( $screens, 'render' )
		);

		add_action( 'admin_init', array( BackupStore::class, 'maybe_install' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Plugin JS/CSS on our screen only.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( null === $this->core || false === strpos( (string) $hook, 'iworksie-search-replace' ) ) {
			return;
		}
		wp_enqueue_script(
			'iwiesr-admin',
			IWIESR_URL . 'assets/js/admin.js',
			array( 'iwiesr-iwt-core' ),
			IWIESR_VERSION,
			true
		);
		wp_localize_script(
			'iwiesr-admin',
			'iwiesrAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Ajax::NONCE ),
				'jobsNonce' => wp_create_nonce( 'iwiesr_jobs' ),
				'jobId'     => SearchReplaceJob::ID,
				'strings'   => array(
					'preparing'    => __( 'Checking your request…', 'iworksie-search-replace' ),
					'failed'       => __( 'Something went wrong. Reload the page and try again.', 'iworksie-search-replace' ),
					'stopped'      => __( 'Stopped.', 'iworksie-search-replace' ),
					/* translators: %d: number of tables */
					'selected'     => __( '%d selected', 'iworksie-search-replace' ),
					'confirmDel'   => __( 'Delete the undo data for this run? You will no longer be able to undo it.', 'iworksie-search-replace' ),
					'leaveWarning' => __( 'A run is in progress. If you leave, you can resume it later.', 'iworksie-search-replace' ),
				),
			)
		);
		wp_enqueue_style(
			'iwiesr-admin',
			IWIESR_URL . 'assets/css/admin.css',
			array( 'iwiesr-iwt-core' ),
			IWIESR_VERSION
		);
	}
}
