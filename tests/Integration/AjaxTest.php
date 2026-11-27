<?php
/**
 * AJAX permissions and validation.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Tests\Integration;

use IWorksIE\SearchReplace\Admin\Ajax;
use IWorksIE\SearchReplace\Jobs\SearchReplaceJob;
use IWorksIE\SearchReplace\Support\Pending;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * Not in the core "ajax" group, so it runs with the default integration suite.
 */
final class AjaxTest extends WP_Ajax_UnitTestCase {

	/**
	 * Call an AJAX action and return the decoded response.
	 *
	 * @param string $action Action.
	 * @return array<string,mixed>
	 */
	private function call( $action ) {
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		$decoded = json_decode( (string) $this->_last_response, true );
		$this->_last_response = '';
		return is_array( $decoded ) ? $decoded : array( 'raw' => true );
	}

	public function test_subscriber_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array(
			'nonce'  => wp_create_nonce( Ajax::NONCE ),
			'what'   => 'preview',
			'search' => 'abcdef',
		);
		$r     = $this->call( 'iwiesr_prepare' );
		$this->assertFalse( $r['success'] );
		$this->assertFalse( get_option( Pending::OPTION ) );
	}

	public function test_bad_nonce_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array(
			'nonce'  => 'bad',
			'what'   => 'preview',
			'search' => 'abcdef',
		);
		$r     = $this->call( 'iwiesr_prepare' );
		$this->assertArrayNotHasKey( 'success', $r, 'check_ajax_referer dies with -1' );
		$this->assertFalse( get_option( Pending::OPTION ) );
	}

	public function test_admin_preview_keeps_search_text_byte_for_byte() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$search = '<a href="https://x.test/%20a">  two  spaces</a>';
		$_POST  = array(
			'nonce'   => wp_create_nonce( Ajax::NONCE ),
			'what'    => 'preview',
			'mode'    => 'plain',
			'search'  => wp_slash( $search ),
			'replace' => wp_slash( "it's" ),
			'case'    => 'exact',
			'tables'  => array( $wpdb->posts ),
		);
		$r      = $this->call( 'iwiesr_prepare' );
		$this->assertTrue( $r['success'] );
		$pending = get_option( Pending::OPTION );
		$this->assertSame( $search, $pending['config']['search'] );
		$this->assertSame( "it's", $pending['config']['replace'] );
		$this->assertSame( $r['data']['token'], $pending['token'] );
	}

	public function test_apply_requires_current_preview() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			SearchReplaceJob::PREVIEW_OPTION,
			array(
				'id'     => 'abc123',
				'config' => array( 'tables' => array() ),
				'totals' => array( 'cells' => 2 ),
			)
		);
		$_POST = array(
			'nonce'      => wp_create_nonce( Ajax::NONCE ),
			'what'       => 'apply',
			'preview_id' => 'other',
		);
		$r     = $this->call( 'iwiesr_prepare' );
		$this->assertFalse( $r['success'] );
	}

	public function test_validation_errors_are_returned() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array(
			'nonce'  => wp_create_nonce( Ajax::NONCE ),
			'what'   => 'preview',
			'mode'   => 'regex',
			'search' => '(broken',
			'tables' => array(),
		);
		$r     = $this->call( 'iwiesr_prepare' );
		$this->assertFalse( $r['success'] );
		$this->assertCount( 2, $r['data']['messages'] );
	}
}
