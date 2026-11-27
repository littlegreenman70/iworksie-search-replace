<?php
/**
 * AJAX endpoints: validate a request before the job starts, discard a preview, delete a backup.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Admin;

use IWorksIE\SearchReplace\Database\Runs;
use IWorksIE\SearchReplace\Database\Tables;
use IWorksIE\SearchReplace\Jobs\SearchReplaceJob;
use IWorksIE\SearchReplace\Support\Pending;
use IWorksIE\SearchReplace\Support\RunConfig;
use IWorksIE\SearchReplace\Vendor\IWorksIE\Core\Core;

/**
 * Plugin AJAX handlers. Every handler checks a nonce and the capability.
 */
final class Ajax {

	const NONCE = 'iwiesr_admin';

	/**
	 * @var Core
	 */
	private $core;

	/**
	 * @param Core $core Core.
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_ajax_iwiesr_prepare', array( $this, 'prepare' ) );
		add_action( 'wp_ajax_iwiesr_discard', array( $this, 'discard' ) );
		add_action( 'wp_ajax_iwiesr_delete_run', array( $this, 'delete_run' ) );
	}

	/**
	 * Nonce + capability.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( (string) $this->core->config( 'capability' ) ) ) {
			wp_send_json_error( array( 'messages' => array( __( 'Sorry, you are not allowed to do that.', 'iworksie-search-replace' ) ) ), 403 );
		}
	}

	/**
	 * Whether a job is in progress (and not abandoned).
	 *
	 * @return bool
	 */
	public function job_running() {
		$jobs  = $this->core->jobs();
		$state = $jobs->load_state( SearchReplaceJob::ID );
		return is_array( $state ) && empty( $state['done'] ) && empty( $state['cancelled'] ) && ! $jobs->is_stale( $state );
	}

	/**
	 * Validate a preview / apply / undo request and return a one-time token for the job.
	 *
	 * @return void
	 */
	public function prepare() {
		$this->guard();

		if ( $this->job_running() ) {
			wp_send_json_error( array( 'messages' => array( __( 'Another run is still in progress. Resume or stop it first.', 'iworksie-search-replace' ) ) ), 409 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';

		if ( SearchReplaceJob::MODE_PREVIEW === $what ) {
			$input = array();
			foreach ( array( 'mode', 'search', 'replace', 'case', 'old_url', 'new_url', 'www', 'guids', 'confirm_short' ) as $field ) {
				if ( isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ) {
					// Search/replace text is matched byte-for-byte, so it is deliberately not sanitised; it is validated in RunConfig and escaped on output.
					$input[ $field ] = wp_unslash( (string) $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
			}
			$input['tables'] = isset( $_POST['tables'] ) && is_array( $_POST['tables'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tables'] ) ) : array();
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$built = RunConfig::from_input( $input, Tables::available_names() );
			if ( ! empty( $built['errors'] ) ) {
				wp_send_json_error( array( 'messages' => $built['errors'] ), 400 );
			}
			$token = Pending::put(
				array(
					'mode'   => SearchReplaceJob::MODE_PREVIEW,
					'config' => $built['config'],
				),
				get_current_user_id()
			);
			wp_send_json_success( array( 'token' => $token ) );
		}

		if ( SearchReplaceJob::MODE_APPLY === $what ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			$preview_id = isset( $_POST['preview_id'] ) ? sanitize_key( wp_unslash( $_POST['preview_id'] ) ) : '';
			$preview    = get_option( SearchReplaceJob::PREVIEW_OPTION, null );
			if ( ! is_array( $preview ) || '' === $preview_id || ! hash_equals( (string) $preview['id'], $preview_id ) ) {
				wp_send_json_error( array( 'messages' => array( __( 'This preview is out of date. Run the preview again, then apply.', 'iworksie-search-replace' ) ) ), 409 );
			}
			if ( empty( $preview['totals']['cells'] ) ) {
				wp_send_json_error( array( 'messages' => array( __( 'The preview found nothing to change.', 'iworksie-search-replace' ) ) ), 400 );
			}
			// Apply exactly what was previewed, re-checked against today's table list.
			$config           = (array) $preview['config'];
			$config['tables'] = Tables::filter_allowed( (array) $config['tables'] );
			if ( empty( $config['tables'] ) ) {
				wp_send_json_error( array( 'messages' => array( __( 'The tables in this preview no longer exist.', 'iworksie-search-replace' ) ) ), 400 );
			}
			$token = Pending::put(
				array(
					'mode'       => SearchReplaceJob::MODE_APPLY,
					'config'     => $config,
					'preview_id' => $preview_id,
				),
				get_current_user_id()
			);
			wp_send_json_success( array( 'token' => $token ) );
		}

		if ( SearchReplaceJob::MODE_UNDO === $what ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
			$run    = Runs::get( $run_id );
			if ( null === $run || Runs::STATUS_UNDONE === $run['status'] ) {
				wp_send_json_error( array( 'messages' => array( __( 'There is nothing left to undo for this run.', 'iworksie-search-replace' ) ) ), 400 );
			}
			$token = Pending::put(
				array(
					'mode'   => SearchReplaceJob::MODE_UNDO,
					'run_id' => $run_id,
				),
				get_current_user_id()
			);
			wp_send_json_success( array( 'token' => $token ) );
		}

		wp_send_json_error( array( 'messages' => array( 'unknown request' ) ), 400 );
	}

	/**
	 * Forget the current preview.
	 *
	 * @return void
	 */
	public function discard() {
		$this->guard();
		delete_option( SearchReplaceJob::PREVIEW_OPTION );
		wp_send_json_success();
	}

	/**
	 * Delete a run's undo data.
	 *
	 * @return void
	 */
	public function delete_run() {
		$this->guard();
		if ( $this->job_running() ) {
			wp_send_json_error( array( 'messages' => array( __( 'Wait for the current run to finish first.', 'iworksie-search-replace' ) ) ), 409 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
		if ( null === Runs::get( $run_id ) ) {
			wp_send_json_error( array( 'messages' => array( __( 'That run no longer exists.', 'iworksie-search-replace' ) ) ), 404 );
		}
		Runs::delete( $run_id );
		wp_send_json_success();
	}
}
