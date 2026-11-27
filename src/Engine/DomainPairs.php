<?php
/**
 * Builds the URL variants for the "Change domain" preset.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Engine;

/**
 * Turns an old and new site address into every form the old one is stored in.
 */
final class DomainPairs {

	/**
	 * Parse a site address into [scheme, host+path] or null.
	 *
	 * @param string $url Address such as https://example.com or https://example.com/blog.
	 * @return array{scheme:string,base:string,host:string}|null
	 */
	public static function parse( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '/\s/', $url ) ) {
			return null;
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback for unit tests outside WordPress.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}
		$path = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		return array(
			'scheme' => $scheme,
			'base'   => $host . $path,
			'host'   => $host,
		);
	}

	/**
	 * Build variant => replacement pairs.
	 *
	 * @param string $old_url  Old address.
	 * @param string $new_url  New address.
	 * @param bool   $with_www Also match the www / non-www twin of the old host.
	 * @return array<string,string> Empty when either address is invalid or they are the same.
	 */
	public static function build( $old_url, $new_url, $with_www = true ) {
		$old = self::parse( $old_url );
		$new = self::parse( $new_url );
		if ( null === $old || null === $new ) {
			return array();
		}
		// Same address typed twice: nothing to do (use http:// → https:// to upgrade links).
		if ( $old['scheme'] === $new['scheme'] && $old['base'] === $new['base'] ) {
			return array();
		}

		$bases = array( $old['base'] );
		if ( $with_www ) {
			$bases[] = ( 0 === strpos( $old['base'], 'www.' ) ) ? substr( $old['base'], 4 ) : 'www.' . $old['base'];
		}

		$target_full = $new['scheme'] . '://' . $new['base'];
		$target_rel  = '//' . $new['base'];

		$plain = array();
		foreach ( $bases as $base ) {
			$plain[ 'https://' . $base ] = $target_full;
			$plain[ 'http://' . $base ]  = $target_full;
			$plain[ '//' . $base ]       = $target_rel;
		}

		$pairs = array();
		foreach ( $plain as $from => $to ) {
			if ( strtolower( $from ) === strtolower( $to ) ) {
				continue;
			}
			$pairs[ $from ]                            = $to;
			$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
			$pairs[ rawurlencode( $from ) ]            = rawurlencode( $to );
		}

		// Nothing to do if the addresses are effectively the same.
		if ( empty( $pairs ) ) {
			return array();
		}
		return $pairs;
	}

	/**
	 * Human-readable list of the forms covered, for the UI.
	 *
	 * @param array<string,string> $pairs Pairs from build().
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function describe( array $pairs ) {
		$out = array();
		foreach ( $pairs as $from => $to ) {
			$out[] = array( (string) $from, (string) $to );
		}
		return $out;
	}
}
