<?php
/**
 * Finds and replaces text in one value.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Engine;

/**
 * Plain, regular-expression or "change domain" matching.
 *
 * Written for PHP 7.4+: no union types, no match, no str_contains.
 */
final class Matcher {

	const MODE_PLAIN  = 'plain';
	const MODE_REGEX  = 'regex';
	const MODE_DOMAIN = 'domain';

	/**
	 * @var string
	 */
	private $mode;

	/**
	 * Literal search text (plain mode).
	 *
	 * @var string
	 */
	private $search = '';

	/**
	 * Replacement text or template (plain / regex).
	 *
	 * @var string
	 */
	private $replace = '';

	/**
	 * @var bool
	 */
	private $case_sensitive = true;

	/**
	 * Regex body without delimiters (regex and domain modes).
	 *
	 * @var string
	 */
	private $body = '';

	/**
	 * Domain mode: lower-cased variant => replacement.
	 *
	 * @var array<string,string>
	 */
	private $map = array();

	/**
	 * Number of values skipped because PCRE gave up (backtrack limit etc.).
	 *
	 * @var int
	 */
	private $errors = 0;

	/**
	 * @param string $mode Mode constant.
	 */
	private function __construct( $mode ) {
		$this->mode = $mode;
	}

	/**
	 * Literal search.
	 *
	 * @param string $search         Text to find.
	 * @param string $replace        Replacement (literal).
	 * @param bool   $case_sensitive Exact case when true.
	 * @return self
	 */
	public static function plain( $search, $replace, $case_sensitive ) {
		$m                 = new self( self::MODE_PLAIN );
		$m->search         = (string) $search;
		$m->replace        = (string) $replace;
		$m->case_sensitive = (bool) $case_sensitive;
		$m->body           = preg_quote( $m->search, '~' );
		return $m;
	}

	/**
	 * Regular expression search. The pattern is entered without delimiters.
	 *
	 * @param string $pattern        Pattern body, e.g. `colou?r`.
	 * @param string $replace        Replacement; $1, ${1} and \1 refer to groups.
	 * @param bool   $case_sensitive Exact case when true.
	 * @return self
	 */
	public static function regex( $pattern, $replace, $case_sensitive ) {
		$m                 = new self( self::MODE_REGEX );
		$m->search         = (string) $pattern;
		$m->replace        = (string) $replace;
		$m->case_sensitive = (bool) $case_sensitive;
		$m->body           = self::escape_delimiter( (string) $pattern );
		return $m;
	}

	/**
	 * Several literal variants, each with its own replacement, matched in one pass
	 * (case-insensitive) so a replacement can never be replaced again.
	 *
	 * @param array<string,string> $pairs   Variant => replacement.
	 * @param bool                 $boundary Do not match when the variant continues as a longer host/path.
	 * @return self
	 */
	public static function domain( array $pairs, $boundary = true ) {
		$m                 = new self( self::MODE_DOMAIN );
		$m->case_sensitive = false;
		$variants          = array_keys( $pairs );
		// Longest first so `https://x` wins over `//x` at the same position.
		usort(
			$variants,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		$alts = array();
		foreach ( $variants as $variant ) {
			$variant = (string) $variant;
			if ( '' === $variant ) {
				continue;
			}
			$m->map[ strtolower( $variant ) ] = (string) $pairs[ $variant ];
			$alts[]                           = preg_quote( $variant, '~' );
		}
		$m->body = '(?:' . implode( '|', $alts ) . ')';
		if ( $boundary ) {
			// Stop old.com matching old.com.au, old.community or old.com-shop.
			$m->body .= '(?![A-Za-z0-9_-]|\.[A-Za-z0-9])';
		}
		$m->search = implode( ' | ', $variants );
		return $m;
	}

	/**
	 * @return string
	 */
	public function mode() {
		return $this->mode;
	}

	/**
	 * @return bool
	 */
	public function is_case_sensitive() {
		return $this->case_sensitive;
	}

	/**
	 * Values skipped because the pattern failed on them.
	 *
	 * @return int
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * Validation error for this matcher, or '' when usable.
	 *
	 * @return string Error code: empty_search|invalid_regex|empty_match|''.
	 */
	public function validate() {
		if ( self::MODE_DOMAIN === $this->mode ) {
			return empty( $this->map ) ? 'empty_search' : '';
		}
		if ( '' === $this->search ) {
			return 'empty_search';
		}
		if ( self::MODE_REGEX === $this->mode ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid user pattern raises a warning; we report it instead.
			$ok = @preg_match( $this->pattern( false ), '' );
			if ( false === $ok ) {
				return 'invalid_regex';
			}
			if ( 1 === $ok ) {
				return 'empty_match';
			}
		}
		return '';
	}

	/**
	 * Literal fragments a matching value must contain, for a SQL LIKE pre-filter.
	 * Empty array means "cannot pre-filter" (regex).
	 *
	 * @return array<int,string>
	 */
	public function like_terms() {
		if ( self::MODE_PLAIN === $this->mode ) {
			return array( $this->search );
		}
		if ( self::MODE_DOMAIN === $this->mode ) {
			return array_keys( $this->map );
		}
		return array();
	}

	/**
	 * Replace every match.
	 *
	 * @param string $subject Text.
	 * @return array{0:string,1:int} New text and number of replacements. On a PCRE failure the text is returned unchanged with 0.
	 */
	public function replace( $subject ) {
		$subject = (string) $subject;
		if ( '' === $subject ) {
			return array( $subject, 0 );
		}

		if ( self::MODE_PLAIN === $this->mode && $this->case_sensitive ) {
			$count = substr_count( $subject, $this->search );
			if ( 0 === $count ) {
				return array( $subject, 0 );
			}
			return array( str_replace( $this->search, $this->replace, $subject ), $count );
		}

		$count  = 0;
		$result = preg_replace_callback(
			$this->pattern( self::is_utf8( $subject ) ),
			function ( $m ) {
				return $this->replacement_for( $m );
			},
			$subject,
			-1,
			$count
		);
		if ( null === $result ) {
			++$this->errors;
			return array( $subject, 0 );
		}
		return array( $result, (int) $count );
	}

	/**
	 * First match position.
	 *
	 * @param string $subject Text.
	 * @return array{0:int,1:int}|null Byte offset and length, or null.
	 */
	public function first_match( $subject ) {
		$subject = (string) $subject;
		if ( self::MODE_PLAIN === $this->mode && $this->case_sensitive ) {
			$pos = strpos( $subject, $this->search );
			return false === $pos ? null : array( $pos, strlen( $this->search ) );
		}
		$found = preg_match( $this->pattern( self::is_utf8( $subject ) ), $subject, $m, PREG_OFFSET_CAPTURE );
		if ( 1 !== $found ) {
			return null;
		}
		return array( (int) $m[0][1], strlen( (string) $m[0][0] ) );
	}

	/**
	 * Split text into plain and matched parts, for highlighting.
	 *
	 * @param string $subject Text.
	 * @return array<int,array{0:string,1:string,2?:string}> Each item: ['t', text] or ['m', matched, replacement].
	 */
	public function segments( $subject ) {
		$subject = (string) $subject;
		$out     = array();
		$pos     = 0;

		if ( self::MODE_PLAIN === $this->mode && $this->case_sensitive ) {
			$len = strlen( $this->search );
			while ( false !== ( $hit = strpos( $subject, $this->search, $pos ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( $hit > $pos ) {
					$out[] = array( 't', substr( $subject, $pos, $hit - $pos ) );
				}
				$out[] = array( 'm', $this->search, $this->replace );
				$pos   = $hit + $len;
			}
		} else {
			$found = preg_match_all( $this->pattern( self::is_utf8( $subject ) ), $subject, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
			if ( $found ) {
				foreach ( $all as $set ) {
					$text   = (string) $set[0][0];
					$offset = (int) $set[0][1];
					if ( $offset < $pos ) {
						continue;
					}
					if ( $offset > $pos ) {
						$out[] = array( 't', substr( $subject, $pos, $offset - $pos ) );
					}
					$groups = array();
					foreach ( $set as $k => $g ) {
						$groups[ $k ] = is_array( $g ) ? (string) $g[0] : '';
					}
					$out[] = array( 'm', $text, $this->replacement_for( $groups ) );
					$pos   = $offset + strlen( $text );
				}
			}
		}

		if ( $pos < strlen( $subject ) ) {
			$out[] = array( 't', substr( $subject, $pos ) );
		}
		return $out;
	}

	/**
	 * Replacement for one match.
	 *
	 * @param array<int|string,string> $m Match groups.
	 * @return string
	 */
	private function replacement_for( array $m ) {
		if ( self::MODE_DOMAIN === $this->mode ) {
			$key = strtolower( (string) $m[0] );
			return isset( $this->map[ $key ] ) ? $this->map[ $key ] : (string) $m[0];
		}
		if ( self::MODE_REGEX === $this->mode ) {
			return self::expand( $this->replace, $m );
		}
		return $this->replace;
	}

	/**
	 * Expand $1, ${1} and \1 in a regex replacement template.
	 *
	 * @param string                   $template Template.
	 * @param array<int|string,string> $m        Groups.
	 * @return string
	 */
	public static function expand( $template, array $m ) {
		return (string) preg_replace_callback(
			'~\\\\(\\\\)|\$\{(\d{1,2})\}|\$(\d{1,2})|\\\\(\d{1,2})~',
			static function ( $t ) use ( $m ) {
				if ( '' !== $t[1] ) {
					return '\\';
				}
				$n = '';
				foreach ( array( 2, 3, 4 ) as $i ) {
					if ( isset( $t[ $i ] ) && '' !== $t[ $i ] ) {
						$n = $t[ $i ];
						break;
					}
				}
				$n = (int) $n;
				return isset( $m[ $n ] ) ? (string) $m[ $n ] : '';
			},
			$template
		);
	}

	/**
	 * Full PCRE pattern.
	 *
	 * @param bool $utf8 Add the u modifier.
	 * @return string
	 */
	private function pattern( $utf8 ) {
		$flags = '';
		if ( ! $this->case_sensitive ) {
			$flags .= 'i';
		}
		if ( $utf8 && self::is_utf8( $this->body ) ) {
			$flags .= 'u';
		}
		return '~' . $this->body . '~' . $flags;
	}

	/**
	 * Escape unescaped `~` in a user pattern so it can sit between ~ delimiters.
	 *
	 * @param string $pattern Pattern body.
	 * @return string
	 */
	public static function escape_delimiter( $pattern ) {
		$out    = '';
		$length = strlen( $pattern );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $pattern[ $i ];
			if ( '\\' === $c && $i + 1 < $length ) {
				$out .= $c . $pattern[ $i + 1 ];
				++$i;
				continue;
			}
			$out .= ( '~' === $c ) ? '\\~' : $c;
		}
		// A trailing lone backslash is left as-is: it makes the pattern invalid and validate() reports it.
		return $out;
	}

	/**
	 * Valid UTF-8?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_utf8( $text ) {
		return 1 === preg_match( '//u', (string) $text );
	}
}
