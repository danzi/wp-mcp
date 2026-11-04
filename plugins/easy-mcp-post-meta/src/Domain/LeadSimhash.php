<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Domain;

final class LeadSimhash {

	public const HEX_PATTERN = '/^[0-9a-f]{16}$/';
	public const DEFAULT_HAMMING = 12;
	public const BITS = 64;

	public function compute( string $title, string $content ): string {
		return $this->simhash_tokens( $this->weighted_lead_tokens( $title, $content ) );
	}

	public function normalize_hex( $raw ): string {
		$hash = strtolower( trim( (string) $raw ) );
		if ( $hash === '' ) {
			return '';
		}
		if ( ! preg_match( self::HEX_PATTERN, $hash ) ) {
			throw new Exception\MetaException( 'lead_simhash must be 16 lowercase hex characters.', 'invalid_lead_simhash' );
		}
		return $hash;
	}

	public function hamming( string $left, string $right ): int {
		$a = strtolower( trim( $left ) );
		$b = strtolower( trim( $right ) );
		if ( ! preg_match( self::HEX_PATTERN, $a ) || ! preg_match( self::HEX_PATTERN, $b ) ) {
			return self::BITS;
		}
		$x = hex2bin( $a );
		$y = hex2bin( $b );
		if ( $x === false || $y === false ) {
			return self::BITS;
		}
		$xor = $x ^ $y;
		$n   = 0;
		$len = strlen( $xor );
		for ( $i = 0; $i < $len; $i++ ) {
			$n += substr_count( decbin( ord( $xor[ $i ] ) ), '1' );
		}
		return $n;
	}

	/**
	 * @return string[]
	 */
	private function weighted_lead_tokens( string $title, string $content ): array {
		$head = $this->tokens_of( $this->strip_markup( $title ) );
		$body = $this->strip_markup( $content );
		$sentences = $this->sentences_of( $body );
		$first_src = isset( $sentences[0] ) ? $sentences[0] : $this->slice( $body, 0, 400 );
		$first     = $this->tokens_of( $first_src );
		$second    = ( count( $first ) < 12 && isset( $sentences[1] ) )
			? $this->tokens_of( $sentences[1] )
			: [];
		$out = [];
		foreach ( $head as $token ) {
			$out[] = $token;
			$out[] = $token;
		}
		foreach ( $first as $token ) {
			$out[] = $token;
		}
		foreach ( $second as $token ) {
			$out[] = $token;
		}
		return $out;
	}

	/**
	 * @param string[] $tokens
	 */
	private function simhash_tokens( array $tokens ): string {
		if ( count( $tokens ) < 4 ) {
			return '';
		}
		$freq = [];
		foreach ( $tokens as $token ) {
			if ( ! isset( $freq[ $token ] ) ) {
				$freq[ $token ] = 0;
			}
			$freq[ $token ]++;
		}
		$weights = array_fill( 0, 64, 0 );
		foreach ( $freq as $token => $weight ) {
			$bits = $this->token_bits( (string) $token );
			for ( $bit = 0; $bit < 64; $bit++ ) {
				$weights[ $bit ] += $bits[ $bit ] === 1 ? $weight : -$weight;
			}
		}
		$nibbles = [];
		for ( $nibble = 0; $nibble < 16; $nibble++ ) {
			$val = 0;
			for ( $i = 0; $i < 4; $i++ ) {
				$bit = ( $nibble * 4 ) + $i;
				if ( $weights[ $bit ] > 0 ) {
					$val |= 1 << $i;
				}
			}
			$nibbles[ $nibble ] = dechex( $val );
		}
		return implode( '', array_reverse( $nibbles ) );
	}

	/**
	 * @return int[]
	 */
	private function token_bits( string $token ): array {
		$bin = md5( $token, true );
		$bytes = unpack( 'C8', substr( $bin, 0, 8 ) );
		$bits  = [];
		for ( $bit = 0; $bit < 64; $bit++ ) {
			$byte_pos = 7 - (int) floor( $bit / 8 );
			$shift    = $bit % 8;
			$bits[ $bit ] = ( $bytes[ $byte_pos + 1 ] >> $shift ) & 1;
		}
		return $bits;
	}

	/**
	 * @return string[]
	 */
	private function tokens_of( string $text ): array {
		$normalized = $this->normalize_content( $text );
		if ( $normalized === '' ) {
			return [];
		}
		if ( ! preg_match_all( '/[\p{L}\p{N}]{2,}/u', $normalized, $m ) ) {
			return [];
		}
		return $m[0];
	}

	/**
	 * @return string[]
	 */
	private function sentences_of( string $body ): array {
		$parts = preg_split( '/(?<=[.!?…])\s+/u', (string) $body );
		if ( ! is_array( $parts ) ) {
			$parts = [ (string) $body ];
		}
		$out = [];
		foreach ( $parts as $item ) {
			$item = trim( (string) $item );
			if ( $this->len( $item ) >= 20 ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	private function strip_markup( string $value ): string {
		$text = (string) $value;
		$text = preg_replace( '/<[^>]+>/', ' ', $text ) ?? $text;
		$text = preg_replace( '/&nbsp;/i', ' ', $text ) ?? $text;
		$text = preg_replace( '/&amp;/i', '&', $text ) ?? $text;
		$text = preg_replace( '/&lt;/i', '<', $text ) ?? $text;
		$text = preg_replace( '/&gt;/i', '>', $text ) ?? $text;
		$text = preg_replace( '/&quot;/i', '"', $text ) ?? $text;
		$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;
		return trim( $text );
	}

	private function normalize_content( string $value ): string {
		$text = $this->strip_markup( $value );
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}
		return strtolower( $text );
	}

	private function slice( string $text, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, $start, $length, 'UTF-8' );
		}
		return (string) substr( $text, $start, $length );
	}

	private function len( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}
		return strlen( $text );
	}
}
