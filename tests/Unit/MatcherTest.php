<?php
/**
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Tests\Unit;

use IWorksIE\SearchReplace\Engine\DomainPairs;
use IWorksIE\SearchReplace\Engine\Matcher;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase {

	public function test_plain_exact_case() {
		$m = Matcher::plain( 'Apple', 'Pear', true );
		$this->assertSame( array( 'Pear apple APPLE Pear', 2 ), $m->replace( 'Apple apple APPLE Apple' ) );
	}

	public function test_plain_ignore_case_replaces_with_literal() {
		$m = Matcher::plain( 'apple', 'Pear', false );
		$this->assertSame( array( 'Pear Pear Pear', 3 ), $m->replace( 'Apple apple APPLE' ) );
	}

	public function test_plain_ignore_case_multibyte() {
		$m = Matcher::plain( 'élan', 'x', false );
		$this->assertSame( array( 'x x', 2 ), $m->replace( 'ÉLAN élan' ) );
	}

	public function test_plain_dollar_and_backslash_are_literal() {
		$m = Matcher::plain( 'price', '$1 \\1 ${1}', false );
		$this->assertSame( array( '$1 \\1 ${1}', 1 ), $m->replace( 'PRICE' ) );
	}

	public function test_plain_special_regex_chars_are_literal() {
		$m = Matcher::plain( 'a.b*(c)~', 'X', false );
		$this->assertSame( array( 'aXb X', 1 ), $m->replace( 'aXb a.b*(c)~' ) );
	}

	public function test_invalid_utf8_subject_still_matches() {
		$m       = Matcher::plain( 'foo', 'bar', false );
		$subject = "\xff\xfe FOO";
		$this->assertSame( array( "\xff\xfe bar", 1 ), $m->replace( $subject ) );
	}

	public function test_regex_backrefs() {
		$m = Matcher::regex( '(\d{4})-(\d{2})', '$2/${1} \\1', true );
		$this->assertSame( array( '11/2026 2026', 1 ), $m->replace( '2026-11' ) );
	}

	public function test_regex_with_tilde_delimiter() {
		$m = Matcher::regex( 'a~b', 'c', true );
		$this->assertSame( '', $m->validate() );
		$this->assertSame( array( 'c', 1 ), $m->replace( 'a~b' ) );
	}

	public function test_regex_validation() {
		$this->assertSame( 'invalid_regex', Matcher::regex( '(unclosed', 'x', true )->validate() );
		$this->assertSame( 'invalid_regex', Matcher::regex( 'trailing\\', 'x', true )->validate() );
		$this->assertSame( 'empty_match', Matcher::regex( 'x*', 'y', true )->validate() );
		$this->assertSame( '', Matcher::regex( 'x+', 'y', true )->validate() );
		$this->assertSame( 'empty_search', Matcher::plain( '', 'y', true )->validate() );
	}

	public function test_regex_failure_leaves_value_unchanged() {
		$old = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '10' );
		ini_set( 'pcre.jit', '0' );
		$m = Matcher::regex( '(\\w+\\s?)+$', 'x', true );
		$r = $m->replace( str_repeat( 'a', 30 ) . '!' );
		ini_set( 'pcre.backtrack_limit', (string) $old );
		ini_set( 'pcre.jit', '1' );
		$this->assertSame( 0, $r[1] );
		$this->assertSame( 1, $m->errors() );
	}

	public function test_like_terms() {
		$this->assertSame( array( 'abc' ), Matcher::plain( 'abc', 'x', true )->like_terms() );
		$this->assertSame( array(), Matcher::regex( 'abc', 'x', true )->like_terms() );
	}

	public function test_segments_plain() {
		$m = Matcher::plain( 'cat', 'dog', true );
		$this->assertSame(
			array( array( 't', 'a ' ), array( 'm', 'cat', 'dog' ), array( 't', ' and ' ), array( 'm', 'cat', 'dog' ) ),
			$m->segments( 'a cat and cat' )
		);
	}

	public function test_segments_regex_expands_replacement() {
		$m = Matcher::regex( '(\w+)@old', '$1@new', true );
		$this->assertSame(
			array( array( 't', 'mail ' ), array( 'm', 'bob@old', 'bob@new' ) ),
			$m->segments( 'mail bob@old' )
		);
	}

	public function test_first_match() {
		$this->assertSame( array( 2, 3 ), Matcher::plain( 'CAT', 'x', false )->first_match( 'a cat' ) );
		$this->assertNull( Matcher::plain( 'dog', 'x', false )->first_match( 'a cat' ) );
	}

	public function test_domain_all_forms_single_pass() {
		$m = Matcher::domain( DomainPairs::build( 'http://old.com', 'https://new.com', true ) );
		$in  = 'a http://old.com/x b https://www.old.com c //old.com d https:\\/\\/old.com\\/y e https%3A%2F%2Fold.com%2Fz f HTTP://OLD.COM';
		$out = 'a https://new.com/x b https://new.com c //new.com d https:\\/\\/new.com\\/y e https%3A%2F%2Fnew.com%2Fz f https://new.com';
		$this->assertSame( $out, $m->replace( $in )[0] );
	}

	public function test_domain_boundaries() {
		$m = Matcher::domain( DomainPairs::build( 'https://old.com', 'https://new.com', false ) );
		$in = 'https://old.com.au https://old.community https://old.com-shop https://old.com. https://old.com:8080 https://old.com?a';
		$this->assertSame(
			'https://old.com.au https://old.community https://old.com-shop https://new.com. https://new.com:8080 https://new.com?a',
			$m->replace( $in )[0]
		);
	}

	public function test_domain_new_contains_old_is_not_reprocessed() {
		$m = Matcher::domain( DomainPairs::build( 'https://example.com', 'https://staging.example.com', true ) );
		$this->assertSame( array( 'https://staging.example.com/a', 1 ), $m->replace( 'https://example.com/a' ) );
		$this->assertSame( 0, $m->replace( 'https://staging.example.com/a' )[1] );
	}

	public function test_domain_subfolder() {
		$m = Matcher::domain( DomainPairs::build( 'https://old.com/blog', 'https://new.com', false ) );
		$this->assertSame( 'https://new.com/post https://old.com/blogger https://old.com/other', $m->replace( 'https://old.com/blog/post https://old.com/blogger https://old.com/other' )[0] );
	}

	public function test_domain_pairs_validation() {
		$this->assertSame( array(), DomainPairs::build( 'old.com', 'https://new.com' ) );
		$this->assertSame( array(), DomainPairs::build( 'ftp://old.com', 'https://new.com' ) );
		$this->assertSame( array(), DomainPairs::build( 'https://old.com/', 'https://OLD.com' ) );
		$this->assertNotEmpty( DomainPairs::build( 'http://old.com', 'https://old.com' ) );
	}

	public function test_http_to_https_same_host() {
		$m = Matcher::domain( DomainPairs::build( 'http://site.ie', 'https://site.ie', true ) );
		$this->assertSame( 'https://site.ie https://site.ie //site.ie', $m->replace( 'http://site.ie https://site.ie //site.ie' )[0] );
	}
}
