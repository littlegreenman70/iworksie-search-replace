<?php
/**
 * Applies a Matcher to one database cell, serialized-safe.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Engine;

/**
 * Replaces text in plain values and inside (nested) serialized values.
 */
final class ValueReplacer {

	const SKIP_NONE         = '';
	const SKIP_SERIALIZED   = 'serialized_unreadable';
	const SKIP_INVALID      = 'serialized_invalid';
	const SKIP_PATTERN      = 'pattern_error';
	const DEFAULT_MAX_DEPTH = 8;

	/**
	 * @var Matcher
	 */
	private $matcher;

	/**
	 * @var int
	 */
	private $max_depth;

	/**
	 * @param Matcher $matcher   Matcher.
	 * @param int     $max_depth How many levels of serialized-inside-serialized to open.
	 */
	public function __construct( Matcher $matcher, $max_depth = self::DEFAULT_MAX_DEPTH ) {
		$this->matcher   = $matcher;
		$this->max_depth = max( 1, (int) $max_depth );
	}

	/**
	 * @return Matcher
	 */
	public function matcher() {
		return $this->matcher;
	}

	/**
	 * Process one value.
	 *
	 * @param string $value Original value.
	 * @return array{value:string,count:int,skipped:string} New value, number of replacements, skip reason ('' when none).
	 */
	public function process( $value ) {
		return $this->process_at( (string) $value, 0 );
	}

	/**
	 * @param string $value Value.
	 * @param int    $depth Serialized nesting depth.
	 * @return array{value:string,count:int,skipped:string}
	 */
	private function process_at( $value, $depth ) {
		if ( $depth < $this->max_depth && SerializedWalker::looks_serialized( $value ) ) {
			$total   = 0;
			$skipped = self::SKIP_NONE;
			$walker  = new SerializedWalker();
			$out     = $walker->walk(
				$value,
				function ( $inner ) use ( &$total, &$skipped, $depth ) {
					$r      = $this->process_at( (string) $inner, $depth + 1 );
					$total += $r['count'];
					if ( self::SKIP_NONE !== $r['skipped'] ) {
						$skipped = $r['skipped'];
					}
					return $r['value'];
				}
			);

			if ( null !== $out ) {
				if ( $total > 0 && ! self::is_valid_serialized( $out ) ) {
					return self::result( $value, 0, self::SKIP_INVALID );
				}
				return self::result( $total > 0 ? $out : $value, $total, $skipped );
			}

			// Our parser could not read it. If PHP can, it is serialized data we do not
			// understand: leave it alone rather than risk corrupting it.
			if ( self::is_valid_serialized( $value ) ) {
				$probe = $this->matcher->first_match( $value );
				return self::result( $value, 0, null === $probe ? self::SKIP_NONE : self::SKIP_SERIALIZED );
			}
			// Otherwise it is ordinary text that happens to look serialized.
		}

		$errors_before       = $this->matcher->errors();
		list( $new, $count ) = $this->matcher->replace( $value );
		if ( $this->matcher->errors() > $errors_before ) {
			return self::result( $value, 0, self::SKIP_PATTERN );
		}
		return self::result( $new, $count, self::SKIP_NONE );
	}

	/**
	 * Can PHP read this back? Never creates real objects (allowed_classes false).
	 *
	 * @param string $data Serialized text.
	 * @return bool
	 */
	public static function is_valid_serialized( $data ) {
		$data = trim( (string) $data );
		if ( 'b:0;' === $data ) {
			return true;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- validation only; allowed_classes false means no object code can run.
		return false !== @unserialize( $data, array( 'allowed_classes' => false ) );
	}

	/**
	 * @param string $value   Value.
	 * @param int    $count   Count.
	 * @param string $skipped Skip reason.
	 * @return array{value:string,count:int,skipped:string}
	 */
	private static function result( $value, $count, $skipped ) {
		return array(
			'value'   => (string) $value,
			'count'   => (int) $count,
			'skipped' => (string) $skipped,
		);
	}
}
