<?php
/**
 * Loads an HTML or Markdown file into a DOM.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * A parsed source document: DOM body + metadata.
 */
final class Document {

	/** Text of the <title> element, if any. */
	public string $head_title = '';

	/**
	 * Front matter of Markdown files (title, slug, status, type, categories, tags, excerpt, order, date).
	 *
	 * @var array<string, mixed>
	 */
	public array $front_matter = array();

	private function __construct(
		public readonly \DOMDocument $dom,
		public readonly \DOMElement $body,
		public readonly string $relpath
	) {}

	/**
	 * Loads a stored file.
	 *
	 * @param string $abs_path Absolute path on disk.
	 * @param string $relpath  Session-relative path (decides HTML vs Markdown).
	 */
	public static function load( string $abs_path, string $relpath ): self {
		$raw = (string) file_get_contents( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return self::from_string( $raw, $relpath );
	}

	/**
	 * Parses a document from a string.
	 */
	public static function from_string( string $raw, string $relpath ): self {
		$raw = self::to_utf8( $raw );

		if ( Paths::is_markdown( $relpath ) ) {
			[ $front_matter, $markdown ] = FrontMatter::split( $raw );
			$parser = new \HMI_Parsedown();
			$parser->setSafeMode( false );
			$html     = $parser->text( $markdown );
			$document = self::from_html( $html, $relpath );
			$document->front_matter = $front_matter;
			return $document;
		}

		return self::from_html( $raw, $relpath );
	}

	private static function from_html( string $html, string $relpath ): self {
		$head_title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$head_title = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}
		// Use only the body of full documents; fragments are used as they are.
		if ( preg_match( '#<body[^>]*>(.*)</body>#is', $html, $m ) ) {
			$html = $m[1];
		} elseif ( preg_match( '#<body[^>]*>(.*)$#is', $html, $m ) ) {
			$html = $m[1];
		} else {
			$html = (string) preg_replace( '#<!doctype[^>]*>|</?html[^>]*>|<head\b.*?</head>#is', '', $html );
		}

		$dom      = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_COMPACT
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			$body = $dom->createElement( 'body' );
			$dom->appendChild( $body );
		}

		$document             = new self( $dom, $body, $relpath );
		$document->head_title = $head_title;
		return $document;
	}

	/**
	 * Inner HTML of the body.
	 */
	public function body_html(): string {
		$html = '';
		foreach ( $this->body->childNodes as $child ) {
			$html .= $this->dom->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Converts legacy encodings to UTF-8 and strips a BOM.
	 */
	private static function to_utf8( string $raw ): string {
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( mb_check_encoding( $raw, 'UTF-8' ) ) {
			return $raw;
		}
		$charset = 'Windows-1252';
		if ( preg_match( '/<meta[^>]+charset=["\']?([\w\-]+)/i', $raw, $m ) && ! preg_match( '/^utf-?8$/i', $m[1] ) ) {
			$charset = $m[1];
		}
		$converted = @mb_convert_encoding( $raw, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $converted ? mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' ) : $converted;
	}
}
