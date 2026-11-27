<?php
/**
 * Table discovery and column metadata.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Database;

/**
 * Lists the tables a user may search and describes their columns.
 */
final class Tables {

	/**
	 * Column types we search.
	 *
	 * @var array<int,string>
	 */
	const TEXT_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' );

	/**
	 * Binary column types we search (byte-for-byte comparison in SQL).
	 *
	 * @var array<int,string>
	 */
	const BLOB_TYPES = array( 'tinyblob', 'blob', 'mediumblob', 'longblob' );

	/**
	 * Per-request cache of describe().
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $describe_cache = array();

	/**
	 * Tables visible on this screen.
	 *
	 * Single site: every table starting with the site prefix.
	 * Multisite: the current site's own tables plus, on the main site, the
	 * network-wide tables (users, usermeta, blogs, site, sitemeta…).
	 *
	 * @return array<int,array{name:string,rows:int,bytes:int,core:bool}>
	 */
	public static function available() {
		global $wpdb;

		$prefix = (string) $wpdb->prefix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live table list.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( (string) $wpdb->base_prefix ) . '%' ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$core   = self::core_tables();
		$backup = BackupStore::table();
		$out    = array();

		foreach ( $rows as $row ) {
			$name = isset( $row['Name'] ) ? (string) $row['Name'] : '';
			if ( '' === $name || $name === $backup ) {
				continue;
			}
			if ( isset( $row['Comment'] ) && 'VIEW' === strtoupper( (string) $row['Comment'] ) ) {
				continue;
			}
			if ( ! self::belongs_to_current_site( $name, $prefix ) ) {
				continue;
			}
			$out[] = array(
				'name'  => $name,
				'rows'  => isset( $row['Rows'] ) ? (int) $row['Rows'] : 0,
				'bytes' => ( isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0 ) + ( isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0 ),
				'core'  => in_array( $name, $core, true ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['core'] !== $b['core'] ) {
					return $a['core'] ? -1 : 1;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Names from available().
	 *
	 * @return array<int,string>
	 */
	public static function available_names() {
		$names = array();
		foreach ( self::available() as $t ) {
			$names[] = $t['name'];
		}
		return $names;
	}

	/**
	 * Whether a table belongs on the current site's list.
	 *
	 * @param string $name   Table name.
	 * @param string $prefix Current site prefix.
	 * @return bool
	 */
	private static function belongs_to_current_site( $name, $prefix ) {
		global $wpdb;
		if ( ! is_multisite() ) {
			return 0 === strpos( $name, $prefix );
		}
		$base = (string) $wpdb->base_prefix;
		if ( $prefix !== $base ) {
			// Sub-site: only its own wp_N_ tables.
			return 0 === strpos( $name, $prefix );
		}
		// Main site: base-prefixed tables that are not another site's wp_N_ tables.
		if ( 0 !== strpos( $name, $base ) ) {
			return false;
		}
		$rest = substr( $name, strlen( $base ) );
		return ! preg_match( '/^\d+_/', $rest );
	}

	/**
	 * WordPress core table names for the current site (and global ones where shown).
	 *
	 * @return array<int,string>
	 */
	public static function core_tables() {
		global $wpdb;
		$names = array();
		foreach ( $wpdb->tables( 'blog' ) as $table ) {
			$names[] = (string) $table;
		}
		if ( ! is_multisite() || is_main_site() ) {
			foreach ( $wpdb->tables( 'global' ) as $table ) {
				$names[] = (string) $table;
			}
		}
		return $names;
	}

	/**
	 * Tables ticked by default: WordPress core tables that exist.
	 *
	 * @return array<int,string>
	 */
	public static function default_selection() {
		$out = array();
		foreach ( self::available() as $t ) {
			if ( $t['core'] ) {
				$out[] = $t['name'];
			}
		}
		return $out;
	}

	/**
	 * Keep only names that are on the available list.
	 *
	 * @param array<int|string,mixed> $requested Requested names.
	 * @return array<int,string>
	 */
	public static function filter_allowed( array $requested ) {
		$allowed = self::available_names();
		$out     = array();
		foreach ( $requested as $name ) {
			$name = (string) $name;
			if ( in_array( $name, $allowed, true ) && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Describe a table: key columns and searchable columns.
	 *
	 * @param string $table         Table name (must come from available()).
	 * @param bool   $include_guids Include wp_posts.guid.
	 * `exact_only` lists columns whose SQL comparison is byte-exact (blob or *_bin
	 * collation): a case-insensitive search cannot be pre-filtered with LIKE there.
	 *
	 * @return array{key:array<int,string>,key_numeric:bool,text:array<int,string>,blob:array<int,string>,exact_only:array<int,string>,skip_reason:string}
	 */
	public static function describe( $table, $include_guids = false ) {
		global $wpdb;
		$cache_key = $table . '|' . ( $include_guids ? '1' : '0' );
		if ( isset( self::$describe_cache[ $cache_key ] ) ) {
			return self::$describe_cache[ $cache_key ];
		}

		$quoted = self::quote( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier quoted by self::quote() and whitelisted by caller.
		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM {$quoted}", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
		$keys = $wpdb->get_results( "SHOW KEYS FROM {$quoted}", ARRAY_A );

		$info = array(
			'key'         => array(),
			'key_numeric' => false,
			'text'        => array(),
			'blob'        => array(),
			'exact_only'  => array(),
			'skip_reason' => '',
		);

		if ( ! is_array( $columns ) || empty( $columns ) ) {
			$info['skip_reason']                = 'unreadable';
			self::$describe_cache[ $cache_key ] = $info;
			return $info;
		}

		$types     = array();
		$nullable  = array();
		$collation = array();
		foreach ( $columns as $col ) {
			$field               = (string) $col['Field'];
			$collation[ $field ] = isset( $col['Collation'] ) ? strtolower( (string) $col['Collation'] ) : '';
			$types[ $field ]     = strtolower( (string) $col['Type'] );
			$nullable[ $field ]  = ( 'YES' === strtoupper( (string) $col['Null'] ) );
		}

		$info['key'] = self::pick_key( is_array( $keys ) ? $keys : array(), $nullable );
		if ( empty( $info['key'] ) ) {
			$info['skip_reason'] = 'no_key';
		} elseif ( 1 === count( $info['key'] ) ) {
			$info['key_numeric'] = (bool) preg_match( '/^(tiny|small|medium|big)?int/', $types[ $info['key'][0] ] );
		}

		$excluded = self::excluded_columns( $table, $include_guids );
		foreach ( $types as $field => $type ) {
			if ( in_array( $field, $info['key'], true ) || in_array( $field, $excluded, true ) ) {
				continue;
			}
			$base = (string) preg_replace( '/[\s(].*$/', '', $type );
			if ( in_array( $base, self::TEXT_TYPES, true ) ) {
				$info['text'][] = $field;
				if ( '_bin' === substr( $collation[ $field ], -4 ) ) {
					$info['exact_only'][] = $field;
				}
			} elseif ( in_array( $base, self::BLOB_TYPES, true ) ) {
				$info['blob'][]       = $field;
				$info['exact_only'][] = $field;
			}
		}
		if ( '' === $info['skip_reason'] && empty( $info['text'] ) && empty( $info['blob'] ) ) {
			$info['skip_reason'] = 'no_text';
		}

		self::$describe_cache[ $cache_key ] = $info;
		return $info;
	}

	/**
	 * Primary key, else the first unique key whose columns are all NOT NULL.
	 *
	 * @param array<int,array<string,mixed>> $keys     SHOW KEYS rows.
	 * @param array<string,bool>             $nullable Column => nullable.
	 * @return array<int,string>
	 */
	private static function pick_key( array $keys, array $nullable ) {
		$indexes = array();
		foreach ( $keys as $k ) {
			if ( ! empty( $k['Non_unique'] ) && '0' !== (string) $k['Non_unique'] ) {
				continue;
			}
			$name = (string) $k['Key_name'];
			$seq  = (int) $k['Seq_in_index'];
			if ( ! isset( $indexes[ $name ] ) ) {
				$indexes[ $name ] = array();
			}
			$indexes[ $name ][ $seq ] = (string) $k['Column_name'];
		}
		if ( isset( $indexes['PRIMARY'] ) ) {
			ksort( $indexes['PRIMARY'] );
			return array_values( $indexes['PRIMARY'] );
		}
		foreach ( $indexes as $cols ) {
			ksort( $cols );
			$ok = true;
			foreach ( $cols as $c ) {
				if ( ! isset( $nullable[ $c ] ) || $nullable[ $c ] ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return array_values( $cols );
			}
		}
		return array();
	}

	/**
	 * Columns never touched.
	 *
	 * @param string $table         Table.
	 * @param bool   $include_guids Include posts.guid.
	 * @return array<int,string>
	 */
	public static function excluded_columns( $table, $include_guids ) {
		global $wpdb;
		$out = array();
		if ( $table === $wpdb->users ) {
			$out[] = 'user_pass';
			$out[] = 'user_activation_key';
		}
		if ( ! $include_guids && preg_match( '/^' . preg_quote( (string) $wpdb->base_prefix, '/' ) . '(\d+_)?posts$/', $table ) ) {
			$out[] = 'guid';
		}
		/**
		 * Filter columns that Search & Replace must never change.
		 *
		 * @param array<int,string> $out   Column names.
		 * @param string            $table Table name.
		 */
		return (array) apply_filters( 'iwiesr_excluded_columns', $out, $table );
	}

	/**
	 * Rows of our own that must not be edited (they hold the search text itself).
	 *
	 * @param string $table Table.
	 * @return array{column:string,like:string}|null The like value is a literal prefix (escape it before use).
	 */
	public static function own_rows_filter( $table ) {
		global $wpdb;
		$base = preg_quote( (string) $wpdb->base_prefix, '/' );
		if ( preg_match( '/^' . $base . '(\d+_)?options$/', $table ) ) {
			return array(
				'column' => 'option_name',
				'like'   => 'iwiesr_',
			);
		}
		if ( $table === $wpdb->base_prefix . 'sitemeta' ) {
			return array(
				'column' => 'meta_key',
				'like'   => 'iwiesr_',
			);
		}
		return null;
	}

	/**
	 * Backtick-quote an identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public static function quote( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Backtick-quote an identifier for use inside a $wpdb->prepare() query string.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public static function quote_p( $name ) {
		return str_replace( '%', '%%', self::quote( $name ) );
	}

	/**
	 * Clear the describe() cache (tests).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$describe_cache = array();
	}
}
