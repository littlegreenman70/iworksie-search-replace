<?php
/**
 * Undo storage: original values of every changed cell.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Database;

/**
 * Owns the {base_prefix}iwiesr_backup table.
 */
final class BackupStore {

	/**
	 * Schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Site option holding the installed schema version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'iwiesr_db_version';

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return (string) $wpdb->base_prefix . 'iwiesr_backup';
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id varchar(32) NOT NULL,
  table_name varchar(191) NOT NULL,
  column_name varchar(191) NOT NULL,
  row_key text NOT NULL,
  old_value longblob,
  new_hash char(40) NOT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id)
) {$charset};";
		dbDelta( $sql );
		update_site_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Install if missing or outdated (plugin updates do not run activation hooks).
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== (string) get_site_option( self::VERSION_OPTION, '' ) || ! self::exists() ) {
			self::install();
		}
	}

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public static function exists() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Save the original value of one cell.
	 *
	 * @param string              $run_id    Run id.
	 * @param string              $table     Table.
	 * @param string              $column    Column.
	 * @param array<string,mixed> $row_key   Key column => value.
	 * @param string              $old_value Original value.
	 * @param string              $new_value Value written.
	 * @return int Inserted id, 0 on failure.
	 */
	public static function add( $run_id, $table, $column, array $row_key, $old_value, $new_value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			self::table(),
			array(
				'run_id'      => (string) $run_id,
				'table_name'  => (string) $table,
				'column_name' => (string) $column,
				'row_key'     => (string) wp_json_encode( $row_key ),
				'old_value'   => (string) $old_value,
				'new_hash'    => sha1( (string) $new_value ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Delete one backup row.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function delete_row( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Next batch of backup rows for a run.
	 *
	 * @param string $run_id Run id.
	 * @param int    $after  Last id seen.
	 * @param int    $limit  Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public static function batch( $run_id, $after, $limit ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s AND id > %d ORDER BY id ASC LIMIT %d", $run_id, (int) $after, (int) $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows and bytes kept for a run.
	 *
	 * @param string $run_id Run id.
	 * @return array{rows:int,bytes:int}
	 */
	public static function stats( $run_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(old_value)),0) AS b FROM {$table} WHERE run_id = %s", $run_id ), ARRAY_A );
		return array(
			'rows'  => is_array( $row ) ? (int) $row['n'] : 0,
			'bytes' => is_array( $row ) ? (int) $row['b'] : 0,
		);
	}

	/**
	 * Delete everything kept for a run.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	public static function delete_run( $run_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'run_id' => (string) $run_id ), array( '%s' ) );
	}

	/**
	 * Drop the table (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_site_option( self::VERSION_OPTION );
	}
}
