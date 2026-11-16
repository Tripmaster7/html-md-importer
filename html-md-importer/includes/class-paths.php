<?php
/**
 * Path helpers: validation of uploaded relative paths and file-type rules.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for relative paths inside an import session.
 */
final class Paths {

	/** Suffix appended to every stored file so the web server never serves it as HTML/script. */
	public const STORE_SUFFIX = '.hmi';

	public const DOC_EXTENSIONS   = array( 'html', 'htm', 'md', 'markdown' );
	public const MEDIA_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf' );
	public const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

	/** Preferred document names per folder, highest priority first. */
	public const PREFERRED_NAMES = array( 'index', 'content', 'preview' );

	/**
	 * All extensions that may be uploaded.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions(): array {
		return array_merge( self::DOC_EXTENSIONS, self::MEDIA_EXTENSIONS );
	}

	/**
	 * Lower-case extension of a path (without dot).
	 */
	public static function extension( string $path ): string {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	public static function is_doc( string $path ): bool {
		return in_array( self::extension( $path ), self::DOC_EXTENSIONS, true );
	}

	public static function is_media( string $path ): bool {
		return in_array( self::extension( $path ), self::MEDIA_EXTENSIONS, true );
	}

	public static function is_markdown( string $path ): bool {
		return in_array( self::extension( $path ), array( 'md', 'markdown' ), true );
	}

	/**
	 * Validates and normalizes a relative path coming from the browser or a ZIP entry.
	 *
	 * Returns the normalized path ("a/b/c.png") or null when the path is unsafe,
	 * hidden, or has a disallowed extension.
	 */
	public static function sanitize_relpath( string $path ): ?string {
		$path = str_replace( '\\', '/', $path );
		$path = trim( $path, '/' );
		if ( '' === $path || strlen( $path ) > 1024 || str_contains( $path, "\0" ) ) {
			return null;
		}
		$segments = explode( '/', $path );
		if ( count( $segments ) > 24 ) {
			return null;
		}
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
			// Hidden files/folders and macOS resource forks.
			if ( str_starts_with( $segment, '.' ) || '__MACOSX' === $segment ) {
				return null;
			}
			if ( preg_match( '/[\x00-\x1F\x7F:*?"<>|]/', $segment ) || strlen( $segment ) > 255 ) {
				return null;
			}
		}
		if ( ! in_array( self::extension( $path ), self::allowed_extensions(), true ) ) {
			return null;
		}
		return implode( '/', $segments );
	}

	/**
	 * Resolves a reference (src/href) found in a document against the document's folder.
	 *
	 * Returns a session-relative path, or null for external/anchor/data references
	 * or references escaping the session root.
	 *
	 * @param string $ref      Raw attribute value.
	 * @param string $base_dir Session-relative folder of the referencing document ('' for root).
	 * @param string $fragment Receives the "#fragment" part (without '#').
	 */
	public static function resolve_reference( string $ref, string $base_dir, ?string &$fragment = null ): ?string {
		$fragment = '';
		$ref      = trim( html_entity_decode( $ref, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $ref || str_starts_with( $ref, '#' ) ) {
			return null;
		}
		// Any scheme (http:, mailto:, data:, javascript:, file:) or protocol-relative URL.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $ref ) || str_starts_with( $ref, '//' ) ) {
			return null;
		}
		$hash = strpos( $ref, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $ref, $hash + 1 );
			$ref      = substr( $ref, 0, $hash );
		}
		$query = strpos( $ref, '?' );
		if ( false !== $query ) {
			$ref = substr( $ref, 0, $query );
		}
		$ref = rawurldecode( str_replace( '\\', '/', $ref ) );
		if ( '' === $ref ) {
			return null;
		}
		if ( str_starts_with( $ref, '/' ) ) {
			// Root-relative references cannot be mapped reliably; treat them relative to the session root.
			$parts = array();
		} else {
			$parts = '' === $base_dir ? array() : explode( '/', $base_dir );
		}
		foreach ( explode( '/', $ref ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( array() === $parts ) {
					return null;
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		return array() === $parts ? null : implode( '/', $parts );
	}

	/**
	 * Parent folder of a relative path ('' for root).
	 */
	public static function dirname( string $relpath ): string {
		$dir = dirname( $relpath );
		return ( '.' === $dir || '/' === $dir || '\\' === $dir ) ? '' : str_replace( '\\', '/', $dir );
	}

	/**
	 * Last segment of a relative path.
	 */
	public static function basename( string $relpath ): string {
		$pos = strrpos( $relpath, '/' );
		return false === $pos ? $relpath : substr( $relpath, $pos + 1 );
	}

	/**
	 * Turns a folder or file name into a human readable title:
	 * "01 Einleitung_2026_2" → "Einleitung 2026 2", "my-page.md" → "My page".
	 */
	public static function humanize( string $name ): string {
		$name = preg_replace( '/\.(html?|md|markdown)$/i', '', $name ) ?? $name;
		$name = preg_replace( '/^\d+[\s._\-]+/', '', $name ) ?? $name;
		$name = trim( (string) preg_replace( '/[\s_\-]+/', ' ', $name ) );
		if ( '' === $name ) {
			return '';
		}
		return mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
	}
}
