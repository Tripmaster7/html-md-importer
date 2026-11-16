<?php
/**
 * Minimal YAML front matter parser for Markdown files.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Supports the subset that is common in Markdown front matter:
 *   key: value            (strings, quoted strings, numbers, true/false)
 *   key: [a, b, "c d"]    (inline lists)
 *   key:                  (block lists)
 *     - a
 *     - b
 */
final class FrontMatter {

	/**
	 * Splits "---\nyaml\n---\nbody" into [data, body].
	 *
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	public static function split( string $text ): array {
		if ( ! preg_match( '/\A---[ \t]*\R(.*?)\R(?:---|\.\.\.)[ \t]*(?:\R|\z)/s', $text, $m ) ) {
			return array( array(), $text );
		}
		return array( self::parse( $m[1] ), substr( $text, strlen( $m[0] ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function parse( string $yaml ): array {
		$data        = array();
		$current_key = null;
		foreach ( preg_split( '/\R/', $yaml ) ?: array() as $line ) {
			if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}
			if ( null !== $current_key && preg_match( '/^\s+-\s*(.*)$/', $line, $m ) ) {
				$data[ $current_key ][] = self::scalar( $m[1] );
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z0-9_\-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				continue;
			}
			$key   = strtolower( $m[1] );
			$value = trim( $m[2] );
			if ( '' === $value ) {
				$data[ $key ] = array();
				$current_key  = $key;
				continue;
			}
			$current_key = null;
			if ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) ) {
				$items = str_getcsv( substr( $value, 1, -1 ), ',', '"', '\\' );
				$data[ $key ] = array_values( array_filter( array_map( array( self::class, 'scalar' ), $items ), static fn( $v ) => '' !== $v ) );
				continue;
			}
			$data[ $key ] = self::scalar( $value );
		}
		return $data;
	}

	private static function scalar( string $value ): string|int|float|bool {
		$value = trim( $value );
		if ( preg_match( '/^"(.*)"$/s', $value, $m ) ) {
			return stripcslashes( $m[1] );
		}
		if ( preg_match( "/^'(.*)'$/s", $value, $m ) ) {
			return str_replace( "''", "'", $m[1] );
		}
		// Strip trailing comments.
		$value = trim( (string) preg_replace( '/\s+#.*$/', '', $value ) );
		$lower = strtolower( $value );
		if ( 'true' === $lower || 'false' === $lower ) {
			return 'true' === $lower;
		}
		if ( is_numeric( $value ) ) {
			return str_contains( $value, '.' ) ? (float) $value : (int) $value;
		}
		return $value;
	}
}
