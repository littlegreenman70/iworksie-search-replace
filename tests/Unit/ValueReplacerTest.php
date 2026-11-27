<?php
/**
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Tests\Unit;

use IWorksIE\SearchReplace\Engine\DomainPairs;
use IWorksIE\SearchReplace\Engine\Matcher;
use IWorksIE\SearchReplace\Engine\SerializedWalker;
use IWorksIE\SearchReplace\Engine\Snippet;
use IWorksIE\SearchReplace\Engine\ValueReplacer;
use PHPUnit\Framework\TestCase;

/**
 * Class whose wakeup must never run.
 */
final class IwiesrBomb {
	/**
	 * @var string
	 */
	public $url = 'http://old.com';

	/**
	 * @var bool
	 */
	public static $woke = false;

	public function __wakeup() {
		self::$woke = true;
	}
}

final class ValueReplacerTest extends TestCase {

	private function replacer( $search = 'http://old.com', $replace = 'https://new.example.com' ) {
		return new ValueReplacer( Matcher::plain( $search, $replace, true ) );
	}

	public function test_plain_value() {
		$r = $this->replacer()->process( 'see http://old.com/x' );
		$this->assertSame( 'see https://new.example.com/x', $r['value'] );
		$this->assertSame( 1, $r['count'] );
		$this->assertSame( '', $r['skipped'] );
	}

	public function test_serialized_array_lengths_fixed() {
		$data = serialize(
			array(
				'home'  => 'http://old.com',
				'list'  => array( 'http://old.com/a', 5, 1.5, true, null ),
				'http://old.com' => 'key is not replaced',
			)
		);
		$r    = $this->replacer()->process( $data );
		$back = unserialize( $r['value'] );
		$this->assertSame( 'https://new.example.com', $back['home'] );
		$this->assertSame( 'https://new.example.com/a', $back['list'][0] );
		$this->assertSame( array( 5, 1.5, true, null ), array_slice( $back['list'], 1 ) );
		$this->assertArrayHasKey( 'http://old.com', $back );
		$this->assertSame( 2, $r['count'] );
	}

	public function test_nested_serialized_inside_serialized() {
		$inner = serialize( array( 'u' => 'http://old.com' ) );
		$outer = serialize( array( 'blob' => $inner, 'x' => 'http://old.com' ) );
		$r     = $this->replacer()->process( $outer );
		$back  = unserialize( $r['value'] );
		$this->assertSame( 'https://new.example.com', $back['x'] );
		$this->assertSame( array( 'u' => 'https://new.example.com' ), unserialize( $back['blob'] ) );
		$this->assertSame( 2, $r['count'] );
	}

	public function test_objects_are_never_instantiated() {
		IwiesrBomb::$woke = false;
		$obj              = new IwiesrBomb();
		$data             = serialize( array( 'o' => $obj, 's' => new \stdClass() ) );
		$r                = $this->replacer()->process( $data );
		$this->assertFalse( IwiesrBomb::$woke, 'replacing must not run __wakeup' );
		$this->assertSame( 1, $r['count'] );
		$this->assertStringContainsString( 's:23:"https://new.example.com"', $r['value'] );
		$this->assertStringContainsString( 'IwiesrBomb', $r['value'] );
	}

	public function test_private_and_protected_property_names_kept() {
		$data = 'O:3:"Foo":2:{s:6:"' . "\0" . 'Foo' . "\0" . 'a";s:14:"http://old.com";s:4:"' . "\0*\0" . 'b";i:1;}';
		$r    = $this->replacer()->process( $data );
		$this->assertSame( 'O:3:"Foo":2:{s:6:"' . "\0" . 'Foo' . "\0" . 'a";s:23:"https://new.example.com";s:4:"' . "\0*\0" . 'b";i:1;}', $r['value'] );
	}

	public function test_custom_serializable_payload_is_copied_unchanged() {
		$data = 'a:2:{i:0;C:3:"Bar":14:{http://old.com}i:1;s:14:"http://old.com";}';
		$r    = $this->replacer()->process( $data );
		$this->assertSame( 'a:2:{i:0;C:3:"Bar":14:{http://old.com}i:1;s:23:"https://new.example.com";}', $r['value'] );
		$this->assertSame( 1, $r['count'] );
	}

	public function test_multibyte_lengths_are_bytes() {
		$data = serialize( array( 'café http://old.com ☕' ) );
		$r    = $this->replacer()->process( $data );
		$this->assertSame( array( 'café https://new.example.com ☕' ), unserialize( $r['value'] ) );
	}

	public function test_text_that_only_looks_serialized_is_plain_text() {
		$r = $this->replacer()->process( 's:99:"http://old.com";' );
		$this->assertSame( 's:99:"https://new.example.com";', $r['value'] );
		$this->assertSame( 1, $r['count'] );
	}

	public function test_replacement_containing_quotes_and_semicolons() {
		$m    = new ValueReplacer( Matcher::plain( 'X', '";s:1:"', true ) );
		$data = serialize( array( 'aXb' ) );
		$r    = $m->process( $data );
		$this->assertSame( array( 'a";s:1:"b' ), unserialize( $r['value'] ) );
	}

	public function test_whitespace_around_serialized_kept() {
		$data = "  \n" . serialize( 'http://old.com' ) . "\n";
		$r    = $this->replacer()->process( $data );
		$this->assertSame( "  \n" . serialize( 'https://new.example.com' ) . "\n", $r['value'] );
	}

	public function test_no_match_returns_identical_value() {
		$data = serialize( array( 'a' => 'b', 'n' => 1 ) );
		$r    = $this->replacer()->process( $data );
		$this->assertSame( $data, $r['value'] );
		$this->assertSame( 0, $r['count'] );
	}

	public function test_walker_rejects_malformed() {
		$w  = new SerializedWalker();
		$id = static function ( $s ) {
			return $s;
		};
		$this->assertNull( $w->walk( 'a:1:{s:1:"a";}', $id ) );
		$this->assertNull( $w->walk( 's:5:"abc";', $id ) );
		$this->assertNull( $w->walk( 'a:0:{}junk', $id ) );
		$this->assertSame( 'b:0;', $w->walk( 'b:0;', $id ) );
		$this->assertSame( 'd:-1.5E+25;', $w->walk( 'd:-1.5E+25;', $id ) );
		$this->assertSame( 'a:1:{i:0;a:1:{i:0;R:2;}}', $w->walk( 'a:1:{i:0;a:1:{i:0;R:2;}}', $id ) );
	}

	public function test_walker_round_trips_complex_data() {
		$w    = new SerializedWalker();
		$data = serialize(
			array(
				'int'   => PHP_INT_MAX,
				'neg'   => -3,
				'float' => 0.1,
				'inf'   => INF,
				'nan'   => NAN,
				'bool'  => false,
				'null'  => null,
				'obj'   => (object) array( 'a' => array( 1, 2 ) ),
				'empty' => '',
				'deep'  => array( array( array( 'x' ) ) ),
			)
		);
		$this->assertSame(
			$data,
			$w->walk(
				$data,
				static function ( $s ) {
					return $s;
				}
			)
		);
	}

	public function test_domain_inside_serialized_json() {
		$json = wp_json_encode_compat( array( 'link' => 'https://old.com/page' ) );
		$data = serialize( array( 'settings' => $json ) );
		$m    = new ValueReplacer( Matcher::domain( DomainPairs::build( 'https://old.com', 'https://new.com' ) ) );
		$r    = $m->process( $data );
		$back = unserialize( $r['value'] );
		$this->assertSame( array( 'link' => 'https://new.com/page' ), json_decode( $back['settings'], true ) );
	}

	public function test_snippet_window_and_utf8_safety() {
		$m = Matcher::plain( 'needle', 'pin', true );
		$v = str_repeat( 'é', 100 ) . 'needle' . str_repeat( 'ü', 100 );
		$s = Snippet::build( $m, $v, 15 );
		$this->assertNotNull( $s );
		$this->assertTrue( $s['cut_start'] );
		$this->assertTrue( $s['cut_end'] );
		foreach ( $s['segments'] as $seg ) {
			$this->assertSame( 1, preg_match( '//u', $seg[1] ), 'segment must be valid UTF-8' );
		}
		$this->assertSame( array( 'm', 'needle', 'pin' ), $s['segments'][1] );
		$this->assertNull( Snippet::build( $m, 'nothing here' ) );
	}

	public function test_snippet_shows_readable_text_inside_serialized_data() {
		$m    = Matcher::plain( 'old.com', 'new.com', true );
		$data = serialize( array( 'old.com' => 'key only', 'v' => serialize( array( 'x' => 'see old.com now' ) ) ) );
		$s    = Snippet::build( $m, $data );
		$this->assertSame( array( array( 't', 'see ' ), array( 'm', 'old.com', 'new.com' ), array( 't', ' now' ) ), $s['segments'] );
	}

	public function test_snippet_cleans_invalid_bytes() {
		$this->assertSame( 1, preg_match( '//u', Snippet::clean( "ab\xff\x01c" ) ) );
	}
}

/**
 * JSON with escaped slashes, like WordPress stores it by default.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_compat( $data ) {
	return (string) json_encode( $data );
}
