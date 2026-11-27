<?php
/**
 * History of applied runs (for Undo).
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Database;

/**
 * Stores run records in a non-autoloaded option and prunes old backups.
 */
final class Runs {

	const OPTION              = 'iwiesr_runs';
	const DEFAULT_KEEP        = 5;
	const STATUS_RUN          = 'running';
	const STATUS_DONE         = 'complete';
	const STATUS_UNDONE       = 'undone';
	const STATUS_PARTIAL_UNDO = 'undo_partial';

	/**
	 * All runs, newest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$runs = get_option( self::OPTION, array() );
		if ( ! is_array( $runs ) ) {
			return array();
		}
		uasort(
			$runs,
			static function ( $a, $b ) {
				return (int) ( isset( $b['time'] ) ? $b['time'] : 0 ) - (int) ( isset( $a['time'] ) ? $a['time'] : 0 );
			}
		);
		return $runs;
	}

	/**
	 * @param string $id Run id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		$runs = self::all();
		return isset( $runs[ $id ] ) ? $runs[ $id ] : null;
	}

	/**
	 * Insert or replace a run.
	 *
	 * @param array<string,mixed> $run Run (must have id).
	 * @return void
	 */
	public static function save( array $run ) {
		$runs               = self::all();
		$runs[ $run['id'] ] = $run;
		update_option( self::OPTION, $runs, false );
	}

	/**
	 * Merge fields into a run.
	 *
	 * @param string              $id     Run id.
	 * @param array<string,mixed> $fields Fields.
	 * @return void
	 */
	public static function update( $id, array $fields ) {
		$run = self::get( $id );
		if ( null === $run ) {
			return;
		}
		self::save( array_merge( $run, $fields ) );
	}

	/**
	 * Remove a run and its backup rows.
	 *
	 * @param string $id Run id.
	 * @return void
	 */
	public static function delete( $id ) {
		BackupStore::delete_run( $id );
		$runs = self::all();
		unset( $runs[ $id ] );
		update_option( self::OPTION, $runs, false );
	}

	/**
	 * How many runs keep their undo data.
	 *
	 * @return int
	 */
	public static function keep() {
		/**
		 * Filter how many recent runs keep their undo backup.
		 *
		 * @param int $keep Default 5.
		 */
		return max( 1, (int) apply_filters( 'iwiesr_keep_runs', self::DEFAULT_KEEP ) );
	}

	/**
	 * Drop runs beyond keep(), oldest first. Call before adding a new run.
	 *
	 * @param int $room Slots to free for runs about to be added.
	 * @return array<int,string> Removed run ids.
	 */
	public static function prune( $room = 0 ) {
		$runs    = self::all();
		$limit   = max( 0, self::keep() - (int) $room );
		$removed = array();
		$i       = 0;
		foreach ( $runs as $id => $run ) {
			++$i;
			if ( $i > $limit ) {
				BackupStore::delete_run( (string) $id );
				unset( $runs[ $id ] );
				$removed[] = (string) $id;
			}
		}
		if ( ! empty( $removed ) ) {
			update_option( self::OPTION, $runs, false );
		}
		return $removed;
	}

	/**
	 * New random run id.
	 *
	 * @return string
	 */
	public static function new_id() {
		return gmdate( 'YmdHis' ) . strtolower( wp_generate_password( 8, false, false ) );
	}
}
