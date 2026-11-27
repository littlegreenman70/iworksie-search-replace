<?php
/**
 * Short before/after excerpts for the preview.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Engine;

/**
 * Cuts a window around the first match and splits it into highlighted segments.
 */
final class Snippet {

	/**
	 * Build an excerpt.
	 *
	 * @param Matcher $matcher Matcher.
	 * @param string  $value   Full original value.
	 * @param int     $context Bytes of context either side.
	 * @return array{segments:array<int,array<int,string>>,cut_start:bool,cut_end:bool}|null Null when nothing matches.
	 */
	public static function build( Matcher $matcher, $value, $context = 60 ) {
		$value = self::readable_part( $matcher, (string) $value, 0 );
		$hit   = $matcher->first_match( $value );
		if ( null === $hit ) {
			return null;
		}
		$length = strlen( $value );
		$start  = self::char_start( $value, max( 0, $hit[0] - $context ) );
		// Show more text after the first match so nearby matches are visible too.
		$end = self::char_start( $value, min( $length, $hit[0] + $hit[1] + ( 4 * $context ) ) );
		if ( $end < $hit[0] + $hit[1] ) {
			$end = $hit[0] + $hit[1];
		}
		$window   = (string) substr( $value, $start, $end - $start );
		$segments = $matcher->segments( $window );
		foreach ( $segments as $i => $seg ) {
			foreach ( $seg as $j => $part ) {
				if ( $j > 0 ) {
					$segments[ $i ][ $j ] = self::clean( (string) $part );
				}
			}
		}
		return array(
			'segments'  => $segments,
			'cut_start' => $start > 0,
			'cut_end'   => $end < $length,
		);
	}

	/**
	 * For serialized data, the first string inside it that matches (so the preview
	 * shows readable text, not `s:39:"…"` with a length that will be recalculated).
	 *
	 * @param Matcher $matcher Matcher.
	 * @param string  $value   Value.
	 * @param int     $depth   Nesting depth.
	 * @return string
	 */
	private static function readable_part( Matcher $matcher, $value, $depth ) {
		if ( $depth >= ValueReplacer::DEFAULT_MAX_DEPTH || ! SerializedWalker::looks_serialized( $value ) ) {
			return $value;
		}
		$found  = null;
		$walker = new SerializedWalker();
		$parsed = $walker->walk(
			$value,
			static function ( $inner ) use ( $matcher, &$found ) {
				if ( null === $found && null !== $matcher->first_match( (string) $inner ) ) {
					$found = (string) $inner;
				}
				return $inner;
			}
		);
		if ( null === $parsed || null === $found ) {
			return $value;
		}
		return self::readable_part( $matcher, $found, $depth + 1 );
	}

	/**
	 * Move an offset back to the start of a UTF-8 character.
	 *
	 * @param string $text   Text.
	 * @param int    $offset Byte offset.
	 * @return int
	 */
	private static function char_start( $text, $offset ) {
		$length = strlen( $text );
		while ( $offset > 0 && $offset < $length && ( ord( $text[ $offset ] ) & 0xC0 ) === 0x80 ) {
			--$offset;
		}
		return $offset;
	}

	/**
	 * Make text safe to store and display: valid UTF-8, no control characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		if ( ! Matcher::is_utf8( $text ) ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$text = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
			} else {
				$text = (string) preg_replace( '/[\x80-\xFF]/', '?', $text );
			}
		}
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '·', $text );
	}
}
