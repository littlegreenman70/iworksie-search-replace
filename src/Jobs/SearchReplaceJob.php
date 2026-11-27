<?php
/**
 * The chunked job behind Preview, Apply and Undo.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Jobs;

use IWorksIE\SearchReplace\Database\BackupStore;
use IWorksIE\SearchReplace\Database\Runs;
use IWorksIE\SearchReplace\Database\Tables;
use IWorksIE\SearchReplace\Engine\Matcher;
use IWorksIE\SearchReplace\Engine\Snippet;
use IWorksIE\SearchReplace\Engine\ValueReplacer;
use IWorksIE\SearchReplace\Support\Pending;
use IWorksIE\SearchReplace\Support\RunConfig;
use IWorksIE\SearchReplace\Vendor\IWorksIE\Core\Jobs\Job;

/**
 * One job id for all three modes, so only one can run at a time.
 */
final class SearchReplaceJob implements Job {

	const ID             = 'search_replace';
	const MODE_PREVIEW   = 'preview';
	const MODE_APPLY     = 'apply';
	const MODE_UNDO      = 'undo';
	const PREVIEW_OPTION = 'iwiesr_last_preview';
	const FLASH_OPTION   = 'iwiesr_flash';

	/**
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * Start from a token issued by the prepare endpoint (the only way in).
	 *
	 * @param array<string,mixed> $params Must contain `token`.
	 * @return array<string,mixed>
	 */
	public function start( array $params ) {
		$token   = isset( $params['token'] ) ? (string) $params['token'] : '';
		$pending = Pending::take( $token, get_current_user_id() );
		if ( null === $pending ) {
			return array(
				'done'     => true,
				'error'    => 'expired',
				'progress' => 0,
				'message'  => __( 'This request expired. Please try again.', 'iworksie-search-replace' ),
			);
		}

		if ( self::MODE_UNDO === $pending['mode'] ) {
			$run_id = (string) $pending['run_id'];
			$stats  = BackupStore::stats( $run_id );
			return array(
				'mode'      => self::MODE_UNDO,
				'run_id'    => $run_id,
				'after'     => 0,
				'total'     => $stats['rows'],
				'seen'      => 0,
				'restored'  => 0,
				'conflicts' => 0,
				'failed'    => 0,
				'progress'  => 0,
				'message'   => __( 'Starting undo…', 'iworksie-search-replace' ),
			);
		}

		$config = (array) $pending['config'];
		$est    = array();
		foreach ( Tables::available() as $t ) {
			if ( in_array( $t['name'], $config['tables'], true ) ) {
				$est[ $t['name'] ] = max( 1, (int) $t['rows'] );
			}
		}

		$state = array(
			'mode'     => (string) $pending['mode'],
			'config'   => $config,
			'tables'   => array_values( array_intersect( (array) $config['tables'], array_keys( $est ) ) ),
			'ti'       => 0,
			'cursor'   => null,
			'range'    => null,
			'est'      => $est,
			'stats'    => array(),
			'samples'  => array(),
			'skips'    => array(),
			'progress' => 0,
			'message'  => __( 'Starting…', 'iworksie-search-replace' ),
			'preview'  => isset( $pending['preview_id'] ) ? (string) $pending['preview_id'] : '',
		);

		if ( self::MODE_APPLY === $state['mode'] ) {
			BackupStore::maybe_install();
			Runs::prune( 1 );
			$state['run_id']   = Runs::new_id();
			list( $from, $to ) = RunConfig::label( $config );
			Runs::save(
				array(
					'id'        => $state['run_id'],
					'time'      => time(),
					'user'      => get_current_user_id(),
					'mode'      => (string) $config['mode'],
					'from'      => $from,
					'to'        => $to,
					'tables'    => count( $state['tables'] ),
					'rows'      => 0,
					'cells'     => 0,
					'status'    => Runs::STATUS_RUN,
					'restored'  => 0,
					'conflicts' => 0,
				)
			);
		}

		return $state;
	}

	/**
	 * Advance for up to $time_budget seconds.
	 *
	 * @param array<string,mixed> $state       State.
	 * @param float               $time_budget Seconds.
	 * @return array<string,mixed>
	 */
	public function step( array $state, $time_budget ) {
		if ( ! empty( $state['error'] ) ) {
			$state['done'] = true;
			return $state;
		}
		$deadline = microtime( true ) + max( 0.5, (float) $time_budget );
		if ( self::MODE_UNDO === $state['mode'] ) {
			return $this->step_undo( $state, $deadline );
		}
		return $this->step_scan( $state, $deadline );
	}

	/**
	 * Final result payload.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function finish( array $state ) {
		if ( ! empty( $state['error'] ) ) {
			return array( 'error' => (string) $state['error'] );
		}

		if ( self::MODE_UNDO === $state['mode'] ) {
			$status = ( (int) $state['conflicts'] > 0 || (int) $state['failed'] > 0 ) ? Runs::STATUS_PARTIAL_UNDO : Runs::STATUS_UNDONE;
			$run    = Runs::get( (string) $state['run_id'] );
			Runs::update(
				(string) $state['run_id'],
				array(
					'status'    => $status,
					'restored'  => (int) ( null !== $run && isset( $run['restored'] ) ? $run['restored'] : 0 ) + (int) $state['restored'],
					'conflicts' => (int) $state['conflicts'],
					'undone_at' => time(),
				)
			);
			wp_cache_flush();
			$result = array(
				'mode'      => self::MODE_UNDO,
				'run_id'    => (string) $state['run_id'],
				'restored'  => (int) $state['restored'],
				'conflicts' => (int) $state['conflicts'],
				'failed'    => (int) $state['failed'],
			);
			update_option( self::FLASH_OPTION, $result, false );
			return $result;
		}

		$totals = self::totals( (array) $state['stats'] );

		if ( self::MODE_PREVIEW === $state['mode'] ) {
			$preview = array(
				'id'      => strtolower( wp_generate_password( 16, false, false ) ),
				'time'    => time(),
				'user'    => get_current_user_id(),
				'config'  => $state['config'],
				'stats'   => $state['stats'],
				'samples' => $state['samples'],
				'skips'   => $state['skips'],
				'totals'  => $totals,
			);
			update_option( self::PREVIEW_OPTION, $preview, false );
			return array(
				'mode'   => self::MODE_PREVIEW,
				'totals' => $totals,
			);
		}

		Runs::update(
			(string) $state['run_id'],
			array(
				'status' => Runs::STATUS_DONE,
				'rows'   => $totals['rows'],
				'cells'  => $totals['cells'],
				'failed' => $totals['failed'],
			)
		);
		delete_option( self::PREVIEW_OPTION );
		wp_cache_flush();
		$result = array(
			'mode'   => self::MODE_APPLY,
			'run_id' => (string) $state['run_id'],
			'totals' => $totals,
			'skips'  => $state['skips'],
		);
		update_option( self::FLASH_OPTION, $result, false );
		return $result;
	}

	/**
	 * Sum per-table stats.
	 *
	 * @param array<string,array<string,mixed>> $stats Stats.
	 * @return array{rows:int,cells:int,matches:int,failed:int,tables:int}
	 */
	public static function totals( array $stats ) {
		$t = array(
			'rows'    => 0,
			'cells'   => 0,
			'matches' => 0,
			'failed'  => 0,
			'tables'  => 0,
		);
		foreach ( $stats as $s ) {
			$t['rows']    += isset( $s['rows'] ) ? (int) $s['rows'] : 0;
			$t['cells']   += isset( $s['cells'] ) ? (int) $s['cells'] : 0;
			$t['matches'] += isset( $s['matches'] ) ? (int) $s['matches'] : 0;
			$t['failed']  += isset( $s['failed'] ) ? (int) $s['failed'] : 0;
			if ( ! empty( $s['rows'] ) ) {
				++$t['tables'];
			}
		}
		return $t;
	}

	/**
	 * Preview / apply: scan tables in key order.
	 *
	 * @param array<string,mixed> $state    State.
	 * @param float               $deadline microtime() deadline.
	 * @return array<string,mixed>
	 */
	private function step_scan( array $state, $deadline ) {
		global $wpdb;

		$config   = (array) $state['config'];
		$matcher  = RunConfig::matcher( $config );
		$replacer = new ValueReplacer( $matcher );
		$apply    = ( self::MODE_APPLY === $state['mode'] );
		$batch    = max( 10, (int) apply_filters( 'iwiesr_batch_size', 100 ) );
		$max_samp = max( 0, (int) apply_filters( 'iwiesr_preview_samples', 200 ) );
		$tables   = (array) $state['tables'];
		$wrote    = false;
		$n_tables = count( $tables );

		while ( $state['ti'] < $n_tables && microtime( true ) < $deadline ) {
			$table = (string) $tables[ $state['ti'] ];
			$info  = Tables::describe( $table, ! empty( $config['guids'] ) );

			if ( ! isset( $state['stats'][ $table ] ) ) {
				$state['stats'][ $table ] = array(
					'rows'    => 0,
					'cells'   => 0,
					'matches' => 0,
					'failed'  => 0,
					'skipped' => 0,
					'reason'  => (string) $info['skip_reason'],
				);
				$state['range']           = self::key_range( $table, $info );
				$state['cursor']          = null;
			}

			if ( '' !== $info['skip_reason'] ) {
				$this->next_table( $state );
				continue;
			}

			$rows = $this->fetch( $table, $info, $matcher, $state['cursor'], $batch );
			if ( null === $rows ) {
				$state['stats'][ $table ]['reason'] = 'query_failed';
				$this->next_table( $state );
				continue;
			}

			$columns     = array_merge( $info['text'], $info['blob'] );
			$interrupted = false;
			foreach ( $rows as $row ) {
				$key = array();
				foreach ( $info['key'] as $k ) {
					$key[ $k ] = $row[ $k ];
				}
				$state['cursor'] = array_values( $key );

				$changed = array();
				foreach ( $columns as $col ) {
					if ( ! isset( $row[ $col ] ) || '' === $row[ $col ] ) {
						continue;
					}
					$old = (string) $row[ $col ];
					$r   = $replacer->process( $old );
					if ( '' !== $r['skipped'] ) {
						++$state['stats'][ $table ]['skipped'];
						$state['skips'][ $r['skipped'] ] = ( isset( $state['skips'][ $r['skipped'] ] ) ? (int) $state['skips'][ $r['skipped'] ] : 0 ) + 1;
					}
					if ( $r['count'] > 0 && $r['value'] !== $old ) {
						$changed[ $col ] = array( $old, $r['value'], $r['count'] );
					}
				}
				if ( empty( $changed ) ) {
					continue;
				}

				if ( $apply ) {
					if ( ! $this->write( (string) $state['run_id'], $table, $key, $changed ) ) {
						++$state['stats'][ $table ]['failed'];
						continue;
					}
					$wrote = true;
				}

				++$state['stats'][ $table ]['rows'];
				foreach ( $changed as $col => $c ) {
					++$state['stats'][ $table ]['cells'];
					$state['stats'][ $table ]['matches'] += (int) $c[2];
					if ( ! $apply && count( $state['samples'] ) < $max_samp ) {
						$snippet = Snippet::build( $matcher, $c[0] );
						if ( null !== $snippet ) {
							$state['samples'][] = array(
								'table'   => $table,
								'column'  => (string) $col,
								'key'     => Snippet::clean( implode( ', ', array_map( 'strval', $key ) ) ),
								'count'   => (int) $c[2],
								'snippet' => $snippet,
							);
						}
					}
				}

				if ( microtime( true ) >= $deadline && end( $rows ) !== $row ) {
					$interrupted = true;
					break;
				}
			}

			// A short batch read to the end means the table is finished.
			if ( ! $interrupted && count( $rows ) < $batch ) {
				$this->next_table( $state );
			}
		}

		if ( $wrote ) {
			wp_cache_flush();
			Runs::update( (string) $state['run_id'], array_merge( array( 'status' => Runs::STATUS_RUN ), self::totals( (array) $state['stats'] ) ) );
		}

		$state['done']     = $state['ti'] >= count( $tables );
		$state['progress'] = $this->progress( $state );
		$state['message']  = $this->message( $state );
		return $state;
	}

	/**
	 * Move to the next table.
	 *
	 * @param array<string,mixed> $state State (by reference).
	 * @return void
	 */
	private function next_table( array &$state ) {
		++$state['ti'];
		$state['cursor'] = null;
		$state['range']  = null;
	}

	/**
	 * Fetch the next batch of candidate rows.
	 *
	 * @param string                $table   Table.
	 * @param array<string,mixed>   $info    Tables::describe().
	 * @param Matcher               $matcher Matcher.
	 * @param array<int,mixed>|null $cursor Last key seen.
	 * @param int                   $limit   Batch size.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function fetch( $table, array $info, Matcher $matcher, $cursor, $limit ) {
		global $wpdb;

		$columns = array_merge( $info['text'], $info['blob'] );
		$select  = array();
		foreach ( array_merge( $info['key'], $columns ) as $c ) {
			$select[] = Tables::quote_p( $c );
		}
		$keys = array();
		foreach ( $info['key'] as $c ) {
			$keys[] = Tables::quote_p( $c );
		}

		$where = array();
		$args  = array();

		// Pre-filter in SQL: rows must contain at least one literal fragment.
		$terms = $matcher->like_terms();
		if ( ! empty( $terms ) ) {
			$ors = array();
			foreach ( $columns as $c ) {
				if ( ! $matcher->is_case_sensitive() && in_array( $c, $info['exact_only'], true ) ) {
					// Byte-exact column + case-insensitive search: cannot pre-filter, read it all.
					$ors = array();
					break;
				}
				foreach ( $terms as $term ) {
					$ors[]  = Tables::quote_p( $c ) . ' LIKE %s';
					$args[] = '%' . $wpdb->esc_like( (string) $term ) . '%';
				}
			}
			if ( ! empty( $ors ) ) {
				$where[] = '(' . implode( ' OR ', $ors ) . ')';
			} else {
				$args = array();
			}
		}

		$own = Tables::own_rows_filter( $table );
		if ( null !== $own ) {
			$where[] = Tables::quote_p( $own['column'] ) . ' NOT LIKE %s';
			$args[]  = $wpdb->esc_like( $own['like'] ) . '%';
		}

		if ( is_array( $cursor ) && count( $cursor ) === count( $keys ) ) {
			$place = array();
			foreach ( $cursor as $v ) {
				$place[] = '%s';
				$args[]  = (string) $v;
			}
			$where[] = '(' . implode( ', ', $keys ) . ') > (' . implode( ', ', $place ) . ')';
		}

		$sql = 'SELECT ' . implode( ', ', $select ) . ' FROM ' . Tables::quote_p( $table );
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql   .= ' ORDER BY ' . implode( ', ', $keys ) . ' LIMIT %d';
		$args[] = (int) $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders -- identifiers are whitelisted and quoted; values go through prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Back up then update one row. All-or-nothing per row.
	 *
	 * @param string                                       $run_id  Run id.
	 * @param string                                       $table   Table.
	 * @param array<string,mixed>                          $key     Key column => value.
	 * @param array<string,array{0:string,1:string,2:int}> $changed Column => [old, new, count].
	 * @return bool
	 */
	private function write( $run_id, $table, array $key, array $changed ) {
		global $wpdb;

		$backup_ids = array();
		$data       = array();
		foreach ( $changed as $col => $c ) {
			$id = BackupStore::add( $run_id, $table, (string) $col, $key, $c[0], $c[1] );
			if ( 0 === $id ) {
				foreach ( $backup_ids as $b ) {
					BackupStore::delete_row( $b );
				}
				return false;
			}
			$backup_ids[]          = $id;
			$data[ (string) $col ] = $c[1];
		}

		$where = array();
		foreach ( $key as $k => $v ) {
			$where[ (string) $k ] = (string) $v;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the whole point of the plugin; caches flushed after each step.
		$ok = $wpdb->update( $table, $data, $where, array_fill( 0, count( $data ), '%s' ), array_fill( 0, count( $where ), '%s' ) );
		if ( false === $ok ) {
			foreach ( $backup_ids as $b ) {
				BackupStore::delete_row( $b );
			}
			return false;
		}
		return true;
	}

	/**
	 * Undo: restore original values, newest change first is not needed (one value per cell per run).
	 *
	 * @param array<string,mixed> $state    State.
	 * @param float               $deadline microtime() deadline.
	 * @return array<string,mixed>
	 */
	private function step_undo( array $state, $deadline ) {
		global $wpdb;
		$wrote = false;

		while ( microtime( true ) < $deadline ) {
			$rows = BackupStore::batch( (string) $state['run_id'], (int) $state['after'], 100 );
			if ( empty( $rows ) ) {
				$state['done'] = true;
				break;
			}
			foreach ( $rows as $b ) {
				$state['after'] = (int) $b['id'];
				++$state['seen'];

				$table  = (string) $b['table_name'];
				$column = (string) $b['column_name'];
				$key    = json_decode( (string) $b['row_key'], true );
				if ( ! is_array( $key ) || empty( $key ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $column ) ) {
					++$state['failed'];
					continue;
				}

				$conds = array();
				$args  = array();
				foreach ( $key as $k => $v ) {
					$conds[] = Tables::quote_p( (string) $k ) . ' = %s';
					$args[]  = (string) $v;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders -- identifiers quoted; values prepared.
				$current = $wpdb->get_var( $wpdb->prepare( 'SELECT ' . Tables::quote_p( $column ) . ' FROM ' . Tables::quote_p( $table ) . ' WHERE ' . implode( ' AND ', $conds ) . ' LIMIT 1', $args ) );

				if ( null === $current || ! hash_equals( (string) $b['new_hash'], sha1( (string) $current ) ) ) {
					// Changed (or deleted) since this run: leave it alone, keep the backup row.
					++$state['conflicts'];
					continue;
				}

				$where = array();
				foreach ( $key as $k => $v ) {
					$where[ (string) $k ] = (string) $v;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$ok = $wpdb->update( $table, array( $column => (string) $b['old_value'] ), $where, array( '%s' ), array_fill( 0, count( $where ), '%s' ) );
				if ( false === $ok ) {
					++$state['failed'];
					continue;
				}
				BackupStore::delete_row( (int) $b['id'] );
				++$state['restored'];
				$wrote = true;
			}
		}

		if ( $wrote ) {
			wp_cache_flush();
		}

		$total             = max( 1, (int) $state['total'] );
		$state['done']     = ! empty( $state['done'] );
		$state['progress'] = $state['done'] ? 100 : min( 99, (int) floor( ( (int) $state['seen'] / $total ) * 100 ) );
		$state['message']  = sprintf(
			/* translators: 1: values processed, 2: total values */
			__( 'Restoring original values: %1$s of %2$s…', 'iworksie-search-replace' ),
			number_format_i18n( (int) $state['seen'] ),
			number_format_i18n( (int) $state['total'] )
		);
		return $state;
	}

	/**
	 * Numeric key range for progress within a table.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $info  describe().
	 * @return array{0:float,1:float}|null
	 */
	private static function key_range( $table, array $info ) {
		global $wpdb;
		if ( empty( $info['key_numeric'] ) || '' !== $info['skip_reason'] ) {
			return null;
		}
		$k = Tables::quote( $info['key'][0] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- identifiers quoted.
		$r = $wpdb->get_row( "SELECT MIN({$k}) AS lo, MAX({$k}) AS hi FROM " . Tables::quote( $table ), ARRAY_A );
		if ( ! is_array( $r ) || null === $r['lo'] ) {
			return null;
		}
		return array( (float) $r['lo'], (float) $r['hi'] );
	}

	/**
	 * Overall progress, weighted by estimated table size.
	 *
	 * @param array<string,mixed> $state State.
	 * @return int
	 */
	private function progress( array $state ) {
		$tables = (array) $state['tables'];
		if ( ! empty( $state['done'] ) || empty( $tables ) ) {
			return 100;
		}
		$total = 0;
		$done  = 0.0;
		foreach ( $tables as $i => $t ) {
			$w      = isset( $state['est'][ $t ] ) ? (int) $state['est'][ $t ] : 1;
			$total += $w;
			if ( $i < $state['ti'] ) {
				$done += $w;
			} elseif ( $i === $state['ti'] && is_array( $state['range'] ) && is_array( $state['cursor'] ) ) {
				list( $lo, $hi ) = $state['range'];
				$span            = max( 1.0, $hi - $lo );
				$done           += $w * min( 1.0, max( 0.0, ( (float) $state['cursor'][0] - $lo ) / $span ) );
			}
		}
		return (int) min( 99, floor( ( $done / max( 1, $total ) ) * 100 ) );
	}

	/**
	 * Progress message.
	 *
	 * @param array<string,mixed> $state State.
	 * @return string
	 */
	private function message( array $state ) {
		$tables = (array) $state['tables'];
		if ( ! empty( $state['done'] ) ) {
			return __( 'Finished.', 'iworksie-search-replace' );
		}
		$current = isset( $tables[ $state['ti'] ] ) ? (string) $tables[ $state['ti'] ] : '';
		$verb    = ( self::MODE_APPLY === $state['mode'] )
			/* translators: 1: table name, 2: table number, 3: number of tables */
			? __( 'Changing %1$s (table %2$d of %3$d)…', 'iworksie-search-replace' )
			/* translators: 1: table name, 2: table number, 3: number of tables */
			: __( 'Checking %1$s (table %2$d of %3$d)…', 'iworksie-search-replace' );
		return sprintf( $verb, $current, (int) $state['ti'] + 1, count( $tables ) );
	}
}
