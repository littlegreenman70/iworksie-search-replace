<?php
/**
 * Validates what the user asked for and builds the matcher.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Support;

use IWorksIE\SearchReplace\Engine\DomainPairs;
use IWorksIE\SearchReplace\Engine\Matcher;

/**
 * Run configuration.
 */
final class RunConfig {

	/**
	 * Searches shorter than this need an explicit confirmation.
	 *
	 * @var int
	 */
	const SHORT_SEARCH = 4;

	/**
	 * Longest search / replace accepted (bytes).
	 *
	 * @var int
	 */
	const MAX_LENGTH = 4096;

	/**
	 * Build a config from raw request data (already unslashed).
	 *
	 * Search and replace text is data, not markup: it is kept byte-for-byte
	 * (sanitising it would change what is searched for) and always escaped on output.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param array<int,string>   $allowed_tables Tables the user may pick.
	 * @return array{config:array<string,mixed>,errors:array<int,string>}
	 */
	public static function from_input( array $input, array $allowed_tables ) {
		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : Matcher::MODE_PLAIN;
		if ( ! in_array( $mode, array( Matcher::MODE_PLAIN, Matcher::MODE_REGEX, Matcher::MODE_DOMAIN ), true ) ) {
			$mode = Matcher::MODE_PLAIN;
		}

		$tables = array();
		if ( isset( $input['tables'] ) && is_array( $input['tables'] ) ) {
			foreach ( $input['tables'] as $t ) {
				$t = is_scalar( $t ) ? (string) $t : '';
				if ( in_array( $t, $allowed_tables, true ) && ! in_array( $t, $tables, true ) ) {
					$tables[] = $t;
				}
			}
		}

		$config = array(
			'mode'    => $mode,
			'search'  => self::text( $input, 'search' ),
			'replace' => self::text( $input, 'replace' ),
			'case'    => isset( $input['case'] ) && 'ignore' === $input['case'] ? 'ignore' : 'exact',
			'old_url' => trim( self::text( $input, 'old_url' ) ),
			'new_url' => trim( self::text( $input, 'new_url' ) ),
			'www'     => ! empty( $input['www'] ),
			'guids'   => ! empty( $input['guids'] ),
			'short'   => ! empty( $input['confirm_short'] ),
			'tables'  => $tables,
		);

		$errors = array();
		if ( Matcher::MODE_DOMAIN === $mode ) {
			if ( null === DomainPairs::parse( $config['old_url'] ) ) {
				$errors[] = __( 'Enter the old address as a full URL, for example https://old-site.com', 'iworksie-search-replace' );
			}
			if ( null === DomainPairs::parse( $config['new_url'] ) ) {
				$errors[] = __( 'Enter the new address as a full URL, for example https://new-site.com', 'iworksie-search-replace' );
			}
			if ( empty( $errors ) && empty( DomainPairs::build( $config['old_url'], $config['new_url'], $config['www'] ) ) ) {
				$errors[] = __( 'The old and new addresses are the same, so there is nothing to change.', 'iworksie-search-replace' );
			}
		} else {
			if ( '' === $config['search'] ) {
				$errors[] = __( 'Enter the text to search for.', 'iworksie-search-replace' );
			} elseif ( strlen( $config['search'] ) > self::MAX_LENGTH || strlen( $config['replace'] ) > self::MAX_LENGTH ) {
				$errors[] = __( 'Search and replace text must be under 4,096 characters.', 'iworksie-search-replace' );
			} elseif ( $config['search'] === $config['replace'] && Matcher::MODE_PLAIN === $mode && 'exact' === $config['case'] ) {
				$errors[] = __( 'The search and replace text are identical, so nothing would change.', 'iworksie-search-replace' );
			} elseif ( Matcher::MODE_PLAIN === $mode && self::is_short( $config['search'] ) && ! $config['short'] ) {
				$errors[] = sprintf(
					/* translators: %d: minimum number of characters */
					__( 'Searches shorter than %d characters often match far more than you expect (for example inside other words). Tick "I understand" to search anyway.', 'iworksie-search-replace' ),
					self::SHORT_SEARCH
				);
			}
			if ( empty( $errors ) ) {
				$code = self::matcher( $config )->validate();
				if ( 'invalid_regex' === $code ) {
					$errors[] = __( 'That regular expression is not valid. Check brackets and special characters, or turn regular expressions off to search for the text exactly as typed.', 'iworksie-search-replace' );
				} elseif ( 'empty_match' === $code ) {
					$errors[] = __( 'That regular expression can match empty text, which would insert the replacement everywhere. Make it more specific.', 'iworksie-search-replace' );
				}
			}
		}

		if ( empty( $tables ) ) {
			$errors[] = __( 'Choose at least one table.', 'iworksie-search-replace' );
		}

		return array(
			'config' => $config,
			'errors' => $errors,
		);
	}

	/**
	 * Whether a search string is short enough to need confirmation.
	 *
	 * @param string $search Search text.
	 * @return bool
	 */
	public static function is_short( $search ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $search, 'UTF-8' ) : strlen( (string) $search );
		return $length < self::SHORT_SEARCH;
	}

	/**
	 * Matcher for a validated config.
	 *
	 * @param array<string,mixed> $config Config.
	 * @return Matcher
	 */
	public static function matcher( array $config ) {
		$case = ( isset( $config['case'] ) ? $config['case'] : 'exact' ) !== 'ignore';
		switch ( isset( $config['mode'] ) ? $config['mode'] : '' ) {
			case Matcher::MODE_DOMAIN:
				return Matcher::domain( DomainPairs::build( (string) $config['old_url'], (string) $config['new_url'], ! empty( $config['www'] ) ) );
			case Matcher::MODE_REGEX:
				return Matcher::regex( (string) $config['search'], (string) $config['replace'], $case );
			default:
				return Matcher::plain( (string) $config['search'], (string) $config['replace'], $case );
		}
	}

	/**
	 * One-line description of a config, for history and headings.
	 *
	 * @param array<string,mixed> $config Config.
	 * @return array{0:string,1:string} From and to.
	 */
	public static function label( array $config ) {
		if ( isset( $config['mode'] ) && Matcher::MODE_DOMAIN === $config['mode'] ) {
			return array( (string) $config['old_url'], (string) $config['new_url'] );
		}
		return array( (string) $config['search'], (string) $config['replace'] );
	}

	/**
	 * Read a string field.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key   Key.
	 * @return string
	 */
	private static function text( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return '';
		}
		// Normalise line endings typed into the box; keep everything else exactly.
		return str_replace( "\r\n", "\n", (string) $input[ $key ] );
	}
}
