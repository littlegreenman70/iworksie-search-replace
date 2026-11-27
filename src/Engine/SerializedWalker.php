<?php
/**
 * Edits strings inside PHP-serialized data without ever unserializing it.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Engine;

/**
 * A small parser for PHP's serialize() format.
 *
 * It walks the text, hands every string value (not array keys or property names)
 * to a callback, and rewrites the `s:<length>:` prefix to match the new string.
 * No objects are ever created, so no __wakeup()/__destruct() code can run, and
 * objects of classes that are not loaded keep their data intact.
 */
final class SerializedWalker {

	/**
	 * Deepest nesting accepted.
	 *
	 * @var int
	 */
	const MAX_NESTING = 512;

	/**
	 * @var string
	 */
	private $data = '';

	/**
	 * @var int
	 */
	private $pos = 0;

	/**
	 * @var int
	 */
	private $len = 0;

	/**
	 * @var callable
	 */
	private $callback;

	/**
	 * Quick check: could this be serialized data? (Full parse decides.)
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_serialized( $value ) {
		$value = trim( (string) $value );
		if ( strlen( $value ) < 2 ) {
			return false;
		}
		if ( 'N;' === $value ) {
			return true;
		}
		if ( ':' !== $value[1] ) {
			return false;
		}
		if ( false === strpos( 'aOsSbidCEN', $value[0] ) ) {
			return false;
		}
		$last = substr( $value, -1 );
		return ';' === $last || '}' === $last;
	}

	/**
	 * Walk serialized data, replacing string values via the callback.
	 *
	 * @param string   $data     Serialized text (may have surrounding whitespace).
	 * @param callable $callback function( string $value ): string.
	 * @return string|null New serialized text, or null when the data is not valid serialized text.
	 */
	public function walk( $data, $callback ) {
		$data    = (string) $data;
		$trimmed = trim( $data );
		if ( '' === $trimmed ) {
			return null;
		}
		$lead = (int) strspn( $data, " \t\n\r\0\x0B" );
		$head = substr( $data, 0, $lead );
		$tail = (string) substr( $data, $lead + strlen( $trimmed ) );

		$this->data     = $trimmed;
		$this->pos      = 0;
		$this->len      = strlen( $trimmed );
		$this->callback = $callback;

		try {
			$out = $this->value( false, 0 );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		if ( $this->pos !== $this->len ) {
			return null;
		}
		return $head . $out . $tail;
	}

	/**
	 * Parse one value at the cursor and return its (possibly rewritten) text.
	 *
	 * @param bool $is_key Whether this is an array key / property name (never rewritten).
	 * @param int  $depth  Nesting depth.
	 * @return string
	 * @throws \UnexpectedValueException On malformed data.
	 */
	private function value( $is_key, $depth ) {
		if ( $depth > self::MAX_NESTING ) {
			throw new \UnexpectedValueException( 'too deep' );
		}
		$type = $this->peek();

		if ( 'N' === $type ) {
			$this->expect( 'N;' );
			return 'N;';
		}

		$this->pos += 1;
		$this->expect( ':' );

		switch ( $type ) {
			case 'b':
				$v = $this->until( ';' );
				if ( '0' !== $v && '1' !== $v ) {
					throw new \UnexpectedValueException( 'bad bool' );
				}
				return 'b:' . $v . ';';

			case 'i':
				$v = $this->until( ';' );
				if ( ! preg_match( '/^[+-]?\d+$/', $v ) ) {
					throw new \UnexpectedValueException( 'bad int' );
				}
				return 'i:' . $v . ';';

			case 'd':
				$v = $this->until( ';' );
				if ( ! preg_match( '/^(?:[+-]?(?:\d+\.?\d*(?:[eE][+-]?\d+)?|\.\d+(?:[eE][+-]?\d+)?)|-?INF|NAN)$/', $v ) ) {
					throw new \UnexpectedValueException( 'bad float' );
				}
				return 'd:' . $v . ';';

			case 'r':
			case 'R':
				$v = $this->until( ';' );
				if ( ! ctype_digit( $v ) ) {
					throw new \UnexpectedValueException( 'bad ref' );
				}
				return $type . ':' . $v . ';';

			case 's':
				$str = $this->quoted( (int) $this->number( ':' ) );
				$this->expect( ';' );
				if ( $is_key ) {
					return 's:' . strlen( $str ) . ':"' . $str . '";';
				}
				$new = (string) call_user_func( $this->callback, $str );
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'E':
				$str = $this->quoted( (int) $this->number( ':' ) );
				$this->expect( ';' );
				return 'E:' . strlen( $str ) . ':"' . $str . '";';

			case 'a':
				$count = (int) $this->number( ':' );
				$this->expect( '{' );
				$out = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$key_type = $this->peek();
					if ( 'i' !== $key_type && 's' !== $key_type ) {
						throw new \UnexpectedValueException( 'bad key' );
					}
					$out .= $this->value( true, $depth + 1 );
					$out .= $this->value( false, $depth + 1 );
				}
				$this->expect( '}' );
				return $out . '}';

			case 'O':
				$class = $this->quoted( (int) $this->number( ':' ) );
				$this->expect( ':' );
				$count = (int) $this->number( ':' );
				$this->expect( '{' );
				$out = 'O:' . strlen( $class ) . ':"' . $class . '":' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$key_type = $this->peek();
					if ( 'i' !== $key_type && 's' !== $key_type ) {
						throw new \UnexpectedValueException( 'bad property' );
					}
					$out .= $this->value( true, $depth + 1 );
					$out .= $this->value( false, $depth + 1 );
				}
				$this->expect( '}' );
				return $out . '}';

			case 'C':
				// Custom Serializable payload: opaque, copied unchanged.
				$class = $this->quoted( (int) $this->number( ':' ) );
				$this->expect( ':' );
				$size = (int) $this->number( ':' );
				$this->expect( '{' );
				if ( $this->pos + $size > $this->len ) {
					throw new \UnexpectedValueException( 'short C' );
				}
				$raw        = substr( $this->data, $this->pos, $size );
				$this->pos += $size;
				$this->expect( '}' );
				return 'C:' . strlen( $class ) . ':"' . $class . '":' . $size . ':{' . $raw . '}';
		}

		throw new \UnexpectedValueException( 'unknown type' );
	}

	/**
	 * Read a non-negative integer followed by $terminator.
	 *
	 * @param string $terminator Char after the number.
	 * @return string
	 * @throws \UnexpectedValueException On malformed data.
	 */
	private function number( $terminator ) {
		$v = $this->until( $terminator );
		if ( '' === $v || ! ctype_digit( $v ) ) {
			throw new \UnexpectedValueException( 'bad number' );
		}
		return $v;
	}

	/**
	 * Read `"<exactly $length bytes>"`.
	 *
	 * @param int $length Byte length.
	 * @return string
	 * @throws \UnexpectedValueException On malformed data.
	 */
	private function quoted( $length ) {
		$this->expect( '"' );
		if ( $length < 0 || $this->pos + $length > $this->len ) {
			throw new \UnexpectedValueException( 'short string' );
		}
		$str        = (string) substr( $this->data, $this->pos, $length );
		$this->pos += $length;
		$this->expect( '"' );
		return $str;
	}

	/**
	 * Read up to (and consume) $char.
	 *
	 * @param string $char Terminator.
	 * @return string
	 * @throws \UnexpectedValueException On malformed data.
	 */
	private function until( $char ) {
		$end = strpos( $this->data, $char, $this->pos );
		if ( false === $end ) {
			throw new \UnexpectedValueException( 'missing terminator' );
		}
		$v         = (string) substr( $this->data, $this->pos, $end - $this->pos );
		$this->pos = $end + 1;
		return $v;
	}

	/**
	 * Consume an exact literal.
	 *
	 * @param string $literal Expected text.
	 * @return void
	 * @throws \UnexpectedValueException On mismatch.
	 */
	private function expect( $literal ) {
		$n = strlen( $literal );
		if ( substr( $this->data, $this->pos, $n ) !== $literal ) {
			throw new \UnexpectedValueException( 'unexpected token' );
		}
		$this->pos += $n;
	}

	/**
	 * Current char.
	 *
	 * @return string
	 * @throws \UnexpectedValueException At end of data.
	 */
	private function peek() {
		if ( $this->pos >= $this->len ) {
			throw new \UnexpectedValueException( 'eof' );
		}
		return $this->data[ $this->pos ];
	}
}
