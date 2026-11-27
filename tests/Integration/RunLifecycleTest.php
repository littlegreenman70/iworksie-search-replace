<?php
/**
 * Preview, apply and undo against a real WordPress database.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Tests\Integration;

use IWorksIE\SearchReplace\Database\BackupStore;
use IWorksIE\SearchReplace\Database\Runs;
use IWorksIE\SearchReplace\Database\Tables;
use IWorksIE\SearchReplace\Jobs\SearchReplaceJob;
use IWorksIE\SearchReplace\Support\Pending;
use IWorksIE\SearchReplace\Support\RunConfig;
use WP_UnitTestCase;

final class RunLifecycleTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		BackupStore::install();
		Tables::flush_cache();
		delete_option( Runs::OPTION );
		delete_option( SearchReplaceJob::PREVIEW_OPTION );
	}

	/**
	 * Drive the job like the AJAX runner does.
	 *
	 * @param array<string,mixed> $request Pending request.
	 * @return array<string,mixed> finish() result.
	 */
	private function run_job( array $request ) {
		$job   = new SearchReplaceJob();
		$token = Pending::put( $request, get_current_user_id() );
		$state = $job->start( array( 'token' => $token ) );
		$guard = 0;
		while ( empty( $state['done'] ) && $guard++ < 1000 ) {
			$state = $job->step( $state, 5.0 );
		}
		$this->assertTrue( ! empty( $state['done'] ), 'job finished' );
		return $job->finish( $state );
	}

	/**
	 * @param array<string,mixed> $input Form input.
	 * @return array<string,mixed>
	 */
	private function config( array $input ) {
		global $wpdb;
		$input = array_merge( array( 'tables' => array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->users ) ), $input );
		$built = RunConfig::from_input( $input, Tables::available_names() );
		$this->assertSame( array(), $built['errors'] );
		return $built['config'];
	}

	public function test_preview_changes_nothing_then_apply_then_undo() {
		global $wpdb;
		$post_id = self::factory()->post->create(
			array(
				'post_content' => 'Visit http://old.example/page and https://old.example/x',
				'post_title'   => 'Untouched title',
			)
		);
		update_post_meta( $post_id, 'iwiesr_test_meta', array( 'url' => 'http://old.example/meta', 'n' => 3 ) );
		update_option( 'iwiesr_test_unrelated', 'x' ); // Own prefix: never edited.
		update_option( 'zz_test_widget', array( 'nested' => serialize( array( 'u' => 'https://old.example' ) ) ) );

		$config = $this->config(
			array(
				'mode'    => 'domain',
				'old_url' => 'http://old.example',
				'new_url' => 'https://new.example',
				'www'     => '1',
			)
		);

		// Preview.
		$result = $this->run_job(
			array(
				'mode'   => SearchReplaceJob::MODE_PREVIEW,
				'config' => $config,
			)
		);
		$this->assertSame( 'preview', $result['mode'] );
		$this->assertSame( 4, $result['totals']['matches'] );
		$this->assertSame( 3, $result['totals']['cells'] );
		$this->assertStringContainsString( 'http://old.example/page', get_post_field( 'post_content', $post_id, 'raw' ) );
		$preview = get_option( SearchReplaceJob::PREVIEW_OPTION );
		$this->assertNotEmpty( $preview['samples'] );
		$this->assertSame( 'm', $preview['samples'][0]['snippet']['segments'][1][0] );

		// Apply.
		$result = $this->run_job(
			array(
				'mode'   => SearchReplaceJob::MODE_APPLY,
				'config' => $config,
			)
		);
		$run_id = $result['run_id'];
		$this->assertSame( 3, $result['totals']['cells'] );
		$this->assertSame( 'Visit https://new.example/page and https://new.example/x', get_post_field( 'post_content', $post_id, 'raw' ) );
		$meta = get_post_meta( $post_id, 'iwiesr_test_meta', true );
		$this->assertSame( array( 'url' => 'https://new.example/meta', 'n' => 3 ), $meta );
		$widget = get_option( 'zz_test_widget' );
		$this->assertSame( array( 'u' => 'https://new.example' ), unserialize( $widget['nested'] ) );
		$this->assertSame( Runs::STATUS_DONE, Runs::get( $run_id )['status'] );
		$this->assertFalse( get_option( SearchReplaceJob::PREVIEW_OPTION ), 'apply clears the preview' );
		$this->assertSame( 3, BackupStore::stats( $run_id )['rows'] );

		// Someone edits the post again before undo: that value must not be overwritten.
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'edited later' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$result = $this->run_job(
			array(
				'mode'   => SearchReplaceJob::MODE_UNDO,
				'run_id' => $run_id,
			)
		);
		$this->assertSame( 2, $result['restored'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertSame( 'edited later', get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertSame( array( 'url' => 'http://old.example/meta', 'n' => 3 ), get_post_meta( $post_id, 'iwiesr_test_meta', true ) );
		$this->assertSame( array( 'u' => 'https://old.example' ), unserialize( get_option( 'zz_test_widget' )['nested'] ) );
		$this->assertSame( Runs::STATUS_PARTIAL_UNDO, Runs::get( $run_id )['status'] );
		$this->assertSame( 1, BackupStore::stats( $run_id )['rows'], 'conflicting original kept' );
	}

	public function test_passwords_guids_and_own_options_are_never_changed() {
		global $wpdb;
		$user_id = self::factory()->user->create( array( 'user_url' => 'https://zebra.test' ) );
		$wpdb->update( $wpdb->users, array( 'user_pass' => 'zebrahash' ), array( 'ID' => $user_id ) );
		$post_id = self::factory()->post->create( array( 'post_content' => 'zebra', 'guid' => 'https://zebra.test/?p=1' ) );
		update_option( 'iwiesr_canary', 'zebra' );

		$config = $this->config(
			array(
				'mode'    => 'plain',
				'search'  => 'zebra',
				'replace' => 'horse',
			)
		);
		$this->run_job(
			array(
				'mode'   => SearchReplaceJob::MODE_APPLY,
				'config' => $config,
			)
		);

		$this->assertSame( 'zebrahash', $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $user_id ) ) );
		$this->assertSame( 'https://horse.test', $wpdb->get_var( $wpdb->prepare( "SELECT user_url FROM {$wpdb->users} WHERE ID = %d", $user_id ) ) );
		$this->assertSame( 'https://zebra.test/?p=1', $wpdb->get_var( $wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ) );
		$this->assertSame( 'horse', $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ) );
		$this->assertSame( 'zebra', get_option( 'iwiesr_canary' ) );
	}

	public function test_guids_changed_when_asked() {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'guid' => 'https://yak.test/?p=1' ) );
		$config  = $this->config(
			array(
				'mode'    => 'plain',
				'search'  => 'yak.test',
				'replace' => 'ox.test',
				'guids'   => '1',
			)
		);
		$this->run_job(
			array(
				'mode'   => SearchReplaceJob::MODE_APPLY,
				'config' => $config,
			)
		);
		$this->assertSame( 'https://ox.test/?p=1', $wpdb->get_var( $wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ) );
	}

	public function test_batches_and_case_insensitive_and_regex() {
		global $wpdb;
		add_filter(
			'iwiesr_batch_size',
			static function () {
				return 10;
			}
		);
		$ids = self::factory()->post->create_many( 35, array( 'post_content' => 'Hello KOALA world' ) );

		$config = $this->config(
			array(
				'mode'    => 'plain',
				'search'  => 'koala',
				'replace' => 'wombat',
				'case'    => 'ignore',
			)
		);
		$result = $this->run_job(
			array(
				'mode'   => SearchReplaceJob::MODE_APPLY,
				'config' => $config,
			)
		);
		$this->assertSame( 35, $result['totals']['rows'] );
		foreach ( $ids as $id ) {
			$this->assertSame( 'Hello wombat world', $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ) );
		}

		$config = $this->config(
			array(
				'mode'    => 'regex',
				'search'  => 'Hello (\w+) world',
				'replace' => 'Bye $1!',
			)
		);
		$result = $this->run_job(
			array(
				'mode'   => SearchReplaceJob::MODE_APPLY,
				'config' => $config,
			)
		);
		$this->assertSame( 35, $result['totals']['rows'] );
		$this->assertSame( 'Bye wombat!', $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $ids[0] ) ) );
	}

	public function test_short_search_and_unknown_tables_rejected() {
		global $wpdb;
		$built = RunConfig::from_input(
			array(
				'mode'    => 'plain',
				'search'  => 'ab',
				'replace' => 'x',
				'tables'  => array( $wpdb->posts ),
			),
			Tables::available_names()
		);
		$this->assertCount( 1, $built['errors'] );

		$built = RunConfig::from_input(
			array(
				'mode'    => 'plain',
				'search'  => 'abcdef',
				'replace' => 'x',
				'tables'  => array( $wpdb->posts . '`; DROP TABLE x; --', BackupStore::table() ),
			),
			Tables::available_names()
		);
		$this->assertSame( array(), $built['config']['tables'] );
		$this->assertNotEmpty( $built['errors'] );
	}

	public function test_backup_table_hidden_and_core_preselected() {
		global $wpdb;
		$names = Tables::available_names();
		$this->assertNotContains( BackupStore::table(), $names );
		$this->assertContains( $wpdb->posts, Tables::default_selection() );
	}

	public function test_expired_or_foreign_token_is_refused() {
		$token = Pending::put( array( 'mode' => 'preview', 'config' => array() ), $this->admin );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertNull( Pending::take( $token, $other ) );
		$this->assertNull( Pending::take( $token, $this->admin ), 'token is single-use' );

		$job   = new SearchReplaceJob();
		$state = $job->start( array( 'token' => 'nope' ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 'expired', $state['error'] );
	}

	public function test_prune_keeps_last_runs() {
		add_filter(
			'iwiesr_keep_runs',
			static function () {
				return 2;
			}
		);
		for ( $i = 0; $i < 3; $i++ ) {
			$post = self::factory()->post->create( array( 'post_content' => 'gnu' . $i ) );
			$this->run_job(
				array(
					'mode'   => SearchReplaceJob::MODE_APPLY,
					'config' => $this->config(
						array(
							'search'  => 'gnu' . $i,
							'replace' => 'emu' . $i,
						)
					),
				)
			);
			unset( $post );
		}
		$this->assertCount( 2, Runs::all() );
	}
}
