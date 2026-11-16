<?php
/**
 * Removes all presentation from imported HTML so the theme controls the look.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes a document body in place: semantic markup only, no styles, classes or scripts.
 */
final class Cleaner {

	/** Elements removed together with their content. */
	private const REMOVE = array(
		'script', 'style', 'link', 'meta', 'noscript', 'template', 'head', 'title', 'base',
		'form', 'input', 'button', 'select', 'textarea', 'option', 'label', 'fieldset', 'legend',
		'iframe', 'object', 'embed', 'applet', 'canvas', 'svg', 'math', 'video', 'audio', 'source', 'track', 'map', 'area',
		'frame', 'frameset', 'dialog',
	);

	/** Elements renamed to their semantic equivalent. */
	private const RENAME = array(
		'b'      => 'strong',
		'i'      => 'em',
		'strike' => 's',
		'del'    => 's',
	);

	/** Elements that are kept. Everything else is unwrapped (content kept, tag removed). */
	private const KEEP = array(
		'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption',
		'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'pre', 'code', 'hr', 'br',
		'a', 'strong', 'em', 's', 'sub', 'sup', 'mark', 'kbd', 'dl', 'dt', 'dd', 'body',
	);

	/** Allowed attributes per element ('*' applies to all). */
	private const ATTRIBUTES = array(
		'a'   => array( 'href', 'title' ),
		'img' => array( 'src', 'alt', 'title' ),
		'td'  => array( 'colspan', 'rowspan' ),
		'th'  => array( 'colspan', 'rowspan', 'scope' ),
		'ol'  => array( 'start', 'reversed' ),
		'h1'  => array( 'id' ),
		'h2'  => array( 'id' ),
		'h3'  => array( 'id' ),
		'h4'  => array( 'id' ),
		'h5'  => array( 'id' ),
		'h6'  => array( 'id' ),
		'p'   => array( 'id' ),
	);

	/** Elements whose leading/trailing whitespace is trimmed and which are dropped when empty. */
	private const TEXT_BLOCKS = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'figcaption', 'td', 'th', 'dt', 'dd', 'caption' );

	private const MERGEABLE = array( 'strong', 'em', 's', 'code', 'mark', 'sub', 'sup', 'kbd' );

	private \DOMDocument $dom;
	private \DOMXPath $xpath;

	/**
	 * Cleans the document body in place.
	 */
	public function clean( Document $document ): void {
		$this->dom   = $document->dom;
		$this->xpath = new \DOMXPath( $this->dom );
		$body        = $document->body;

		foreach ( $this->nodes( './/comment() | .//processing-instruction()', $body ) as $node ) {
			$node->parentNode?->removeChild( $node );
		}

		foreach ( $this->elements( $body ) as $el ) {
			if ( in_array( strtolower( $el->nodeName ), self::REMOVE, true ) ) {
				$el->parentNode?->removeChild( $el );
			}
		}

		foreach ( $this->elements( $body ) as $el ) {
			$name = strtolower( $el->nodeName );
			if ( isset( self::RENAME[ $name ] ) ) {
				$el = $this->rename( $el, self::RENAME[ $name ] );
			}
			if ( ! in_array( strtolower( $el->nodeName ), self::KEEP, true ) ) {
				$this->unwrap( $el );
			}
		}

		foreach ( $this->elements( $body ) as $el ) {
			$this->filter_attributes( $el );
		}

		$this->normalize_whitespace( $body );
		$this->merge_adjacent_inline( $body );
		$this->unwrap_heading_emphasis( $body );

		foreach ( $this->elements( $body ) as $el ) {
			if ( in_array( strtolower( $el->nodeName ), self::TEXT_BLOCKS, true ) ) {
				$this->trim_edges( $el );
			}
		}
		$this->remove_empty( $body );
	}

	/**
	 * Removes the first element of the body if it is an <h1> and returns its text.
	 */
	public static function extract_leading_h1( Document $document ): ?string {
		foreach ( $document->body->childNodes as $child ) {
			if ( $child instanceof \DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}
			if ( $child instanceof \DOMElement && 'h1' === strtolower( $child->nodeName ) ) {
				$title = self::text( $child );
				$child->parentNode?->removeChild( $child );
				return '' === $title ? null : $title;
			}
			return null;
		}
		return null;
	}

	/**
	 * Text of the first <h1> anywhere in the document (without removing it).
	 */
	public static function first_h1_text( Document $document ): ?string {
		$h1 = $document->body->getElementsByTagName( 'h1' )->item( 0 );
		if ( ! $h1 ) {
			return null;
		}
		$title = self::text( $h1 );
		return '' === $title ? null : $title;
	}

	/**
	 * Normalized text content of a node.
	 */
	public static function text( \DOMNode $node ): string {
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $node->textContent ) );
	}

	/**
	 * @return \DOMElement[]
	 */
	private function elements( \DOMElement $root ): array {
		$list = array();
		foreach ( $root->getElementsByTagName( '*' ) as $el ) {
			$list[] = $el;
		}
		return $list;
	}

	/**
	 * @return \DOMNode[]
	 */
	private function nodes( string $query, \DOMNode $context ): array {
		$list   = array();
		$result = $this->xpath->query( $query, $context );
		if ( $result ) {
			foreach ( $result as $node ) {
				$list[] = $node;
			}
		}
		return $list;
	}

	private function rename( \DOMElement $el, string $name ): \DOMElement {
		$new = $this->dom->createElement( $name );
		while ( $el->firstChild ) {
			$new->appendChild( $el->firstChild );
		}
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$new->setAttribute( $attr->nodeName, $attr->nodeValue ?? '' );
		}
		$el->parentNode?->replaceChild( $new, $el );
		return $new;
	}

	private function unwrap( \DOMElement $el ): void {
		$parent = $el->parentNode;
		if ( ! $parent ) {
			return;
		}
		while ( $el->firstChild ) {
			$parent->insertBefore( $el->firstChild, $el );
		}
		$parent->removeChild( $el );
	}

	private function filter_attributes( \DOMElement $el ): void {
		$allowed = self::ATTRIBUTES[ strtolower( $el->nodeName ) ] ?? array();
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name = strtolower( $attr->nodeName );
			if ( ! in_array( $name, $allowed, true ) ) {
				$el->removeAttribute( $attr->nodeName );
				continue;
			}
			if ( 'href' === $name || 'src' === $name ) {
				$value = trim( (string) $attr->nodeValue );
				if ( preg_match( '#^\s*(javascript|vbscript|data):#i', $value ) && ! preg_match( '#^data:image/(png|gif|jpe?g|webp);#i', $value ) ) {
					$el->removeAttribute( $attr->nodeName );
				}
			}
		}
	}

	private function normalize_whitespace( \DOMElement $body ): void {
		foreach ( $this->nodes( './/text()[not(ancestor::pre)]', $body ) as $text ) {
			/** @var \DOMText $text */
			$text->data = (string) preg_replace( '/[ \t\r\n\f]+/', ' ', $text->data );
		}
	}

	/**
	 * "<em>a </em> <em>b</em>" → "<em>a  b</em>" (typical for PDF conversions).
	 */
	private function merge_adjacent_inline( \DOMElement $body ): void {
		foreach ( $this->elements( $body ) as $el ) {
			$name = strtolower( $el->nodeName );
			if ( ! in_array( $name, self::MERGEABLE, true ) || ! $el->parentNode ) {
				continue;
			}
			while ( true ) {
				$next = $el->nextSibling;
				$gap  = null;
				if ( $next instanceof \DOMText && '' === trim( $next->data ) ) {
					$gap  = $next;
					$next = $next->nextSibling;
				}
				if ( ! $next instanceof \DOMElement || strtolower( $next->nodeName ) !== $name || $next->attributes->length > 0 ) {
					break;
				}
				if ( $gap ) {
					$el->appendChild( $gap );
				}
				while ( $next->firstChild ) {
					$el->appendChild( $next->firstChild );
				}
				$next->parentNode?->removeChild( $next );
			}
		}
		// Collapse double spaces created by merging.
		$this->normalize_whitespace( $body );
		foreach ( $this->nodes( './/text()[not(ancestor::pre)]', $body ) as $text ) {
			/** @var \DOMText $text */
			$text->data = (string) preg_replace( '/  +/', ' ', $text->data );
		}
	}

	/**
	 * <h2><strong>Title</strong></h2> → <h2>Title</h2>.
	 */
	private function unwrap_heading_emphasis( \DOMElement $body ): void {
		foreach ( $this->nodes( './/h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6', $body ) as $heading ) {
			$significant = array();
			foreach ( $heading->childNodes as $child ) {
				if ( $child instanceof \DOMText && '' === trim( $child->data ) ) {
					continue;
				}
				$significant[] = $child;
			}
			if ( 1 === count( $significant ) && $significant[0] instanceof \DOMElement
				&& in_array( strtolower( $significant[0]->nodeName ), array( 'strong', 'em' ), true ) ) {
				$this->unwrap( $significant[0] );
			}
		}
	}

	private function trim_edges( \DOMElement $el ): void {
		$this->trim_side( $el, true );
		$this->trim_side( $el, false );
	}

	private function trim_side( \DOMNode $node, bool $start ): void {
		while ( true ) {
			$child = $start ? $node->firstChild : $node->lastChild;
			if ( ! $child ) {
				return;
			}
			if ( $child instanceof \DOMText ) {
				$data = $start ? ltrim( $child->data, " \t\r\n" ) : rtrim( $child->data, " \t\r\n" );
				if ( '' === $data ) {
					$node->removeChild( $child );
					continue;
				}
				$child->data = $data;
				return;
			}
			if ( $child instanceof \DOMElement && in_array( strtolower( $child->nodeName ), array( 'strong', 'em', 's', 'a', 'mark', 'sub', 'sup', 'code', 'kbd' ), true ) ) {
				$this->trim_side( $child, $start );
				if ( ! $child->firstChild ) {
					$node->removeChild( $child );
					continue;
				}
			}
			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				$node->removeChild( $child );
				continue;
			}
			return;
		}
	}

	private function remove_empty( \DOMElement $body ): void {
		$candidates = array_merge( self::TEXT_BLOCKS, array( 'a', 'strong', 'em', 's', 'mark', 'sub', 'sup', 'code', 'kbd', 'ul', 'ol', 'blockquote', 'figure', 'tr', 'table' ) );
		// Children first so that emptied parents are detected as well.
		foreach ( array_reverse( $this->elements( $body ) ) as $el ) {
			$name = strtolower( $el->nodeName );
			if ( ! in_array( $name, $candidates, true ) || ! $el->parentNode ) {
				continue;
			}
			if ( in_array( $name, array( 'td', 'th' ), true ) ) {
				continue; // Empty table cells are meaningful.
			}
			if ( '' !== self::text( $el ) || $el->getElementsByTagName( 'img' )->length > 0 || $el->getElementsByTagName( 'hr' )->length > 0 ) {
				continue;
			}
			if ( 'tr' === $name || 'table' === $name ) {
				if ( $el->getElementsByTagName( 'td' )->length + $el->getElementsByTagName( 'th' )->length > 0 ) {
					continue;
				}
			}
			$el->parentNode->removeChild( $el );
		}
	}
}
