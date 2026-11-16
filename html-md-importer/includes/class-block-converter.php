<?php
/**
 * Converts cleaned HTML into serialized Gutenberg blocks.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Produces core blocks (paragraph, heading, list, quote, image, table, code, separator)
 * so the content is styled entirely by the active theme. Anything that cannot be mapped
 * becomes a core/html block and is reported as a warning.
 *
 * Images are expected to carry a `data-hmi-id` attribute with the attachment ID
 * (set by the importer after uploading the file).
 */
final class BlockConverter {

	private const INLINE = array( 'a', 'strong', 'em', 's', 'sub', 'sup', 'mark', 'kbd', 'code', 'br', 'img' );

	private \DOMDocument $dom;

	/** @var string[] */
	public array $warnings = array();

	/** Attachment ID of the first image block (for the featured image). */
	public ?int $first_image_id = null;

	/** @var array<string, true> Generated heading anchors. */
	private array $anchors = array();

	public function convert( Document $document ): string {
		$this->dom            = $document->dom;
		$this->warnings       = array();
		$this->first_image_id = null;
		$this->anchors        = array();
		return implode( "\n\n", $this->blocks_from_children( $document->body ) );
	}

	/**
	 * @return string[]
	 */
	private function blocks_from_children( \DOMNode $parent ): array {
		$blocks = array();
		$inline = array();

		$flush = function () use ( &$inline, &$blocks ): void {
			$html = '';
			foreach ( $inline as $node ) {
				$html .= $this->inline_node_html( $node );
			}
			$inline = array();
			$html   = trim( (string) preg_replace( '#^(\s|<br\s*/?>)+|(\s|<br\s*/?>)+$#i', '', $html ) );
			if ( '' !== $html ) {
				$blocks[] = $this->block( 'paragraph', array(), '<p>' . $html . '</p>' );
			}
		};

		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( $node instanceof \DOMText ) {
				if ( '' !== trim( $node->data ) || array() !== $inline ) {
					$inline[] = $node;
				}
				continue;
			}
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$name = strtolower( $node->nodeName );

			if ( in_array( $name, self::INLINE, true ) && ! $this->is_standalone_image( $node ) ) {
				$inline[] = $node;
				continue;
			}
			$flush();
			array_push( $blocks, ...$this->element_blocks( $node, $name ) );
		}
		$flush();
		return $blocks;
	}

	/**
	 * @return string[]
	 */
	private function element_blocks( \DOMElement $el, string $name ): array {
		switch ( $name ) {
			case 'p':
				if ( $this->only_images( $el ) ) {
					return $this->image_blocks( $el, null );
				}
				$html = $this->inline_html( $el );
				if ( '' === trim( $html ) ) {
					return array();
				}
				return array( $this->block( 'paragraph', array(), '<p' . $this->id_attr( $el ) . '>' . $html . '</p>' ) );

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $name, 1 );
				$attrs = 2 === $level ? array() : array( 'level' => $level );
				if ( ! $el->hasAttribute( 'id' ) ) {
					$el->setAttribute( 'id', $this->anchor( Cleaner::text( $el ) ) );
				}
				return array( $this->block( 'heading', $attrs, "<{$name} class=\"wp-block-heading\"" . $this->id_attr( $el ) . '>' . $this->inline_html( $el ) . "</{$name}>" ) );

			case 'ul':
			case 'ol':
				return array( $this->list_block( $el ) );

			case 'blockquote':
				$inner = $this->blocks_from_children( $el );
				if ( array() === $inner ) {
					return array();
				}
				return array( $this->block( 'quote', array(), '<blockquote class="wp-block-quote">' . implode( "\n\n", $inner ) . '</blockquote>' ) );

			case 'figure':
				return $this->figure_blocks( $el );

			case 'img':
			case 'a':
				return $this->image_blocks( $el, null );

			case 'table':
				return array( $this->table_block( $el, null ) );

			case 'pre':
				return array( $this->pre_block( $el ) );

			case 'hr':
				return array( $this->block( 'separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ) );

			case 'figcaption':
			case 'caption':
			case 'dt':
			case 'dd':
				$html = $this->inline_html( $el );
				return '' === trim( $html ) ? array() : array( $this->block( 'paragraph', array(), '<p>' . $html . '</p>' ) );

			case 'li':
			case 'tr':
			case 'td':
			case 'th':
			case 'thead':
			case 'tbody':
			case 'tfoot':
				// Stray fragments outside their container: keep the content.
				return $this->blocks_from_children( $el );

			default:
				$this->warnings[] = sprintf( 'Kept <%s> as Custom HTML block.', $name );
				return array( $this->block( 'html', array(), $this->strip_internal( $this->dom->saveHTML( $el ) ?: '' ) ) );
		}
	}

	private function list_block( \DOMElement $list ): string {
		$ordered = 'ol' === strtolower( $list->nodeName );
		$attrs   = array();
		$extra   = '';
		if ( $ordered ) {
			$attrs['ordered'] = true;
			$start            = (int) $list->getAttribute( 'start' );
			if ( $list->hasAttribute( 'start' ) && 1 !== $start ) {
				$attrs['start'] = $start;
				$extra         .= ' start="' . $start . '"';
			}
			if ( $list->hasAttribute( 'reversed' ) ) {
				$attrs['reversed'] = true;
				$extra            .= ' reversed';
			}
		}
		$tag   = $ordered ? 'ol' : 'ul';
		$items = array();
		foreach ( iterator_to_array( $list->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement && 'li' === strtolower( $child->nodeName ) ) {
				$items[] = $this->list_item_block( $child );
			} elseif ( $child instanceof \DOMElement && in_array( strtolower( $child->nodeName ), array( 'ul', 'ol' ), true ) ) {
				// Invalid but common: nested list directly inside a list.
				$items[] = $this->block( 'list-item', array(), '<li>' . $this->list_block( $child ) . '</li>' );
			} elseif ( '' !== trim( $child->textContent ) ) {
				$items[] = $this->block( 'list-item', array(), '<li>' . $this->inline_node_html( $child ) . '</li>' );
			}
		}
		return $this->block( 'list', $attrs, "<{$tag} class=\"wp-block-list\"{$extra}>" . implode( "\n\n", $items ) . "</{$tag}>" );
	}

	private function list_item_block( \DOMElement $li ): string {
		$content = '';
		$nested  = '';
		foreach ( iterator_to_array( $li->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement && in_array( strtolower( $child->nodeName ), array( 'ul', 'ol' ), true ) ) {
				$nested .= $this->list_block( $child );
				continue;
			}
			$content .= $this->inline_node_html( $child );
		}
		$content = trim( (string) preg_replace( '#^(\s|<br\s*/?>)+|(\s|<br\s*/?>)+$#i', '', $content ) );
		return $this->block( 'list-item', array(), '<li>' . $content . $nested . '</li>' );
	}

	/**
	 * @return string[]
	 */
	private function figure_blocks( \DOMElement $figure ): array {
		$caption_el = null;
		foreach ( $figure->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'figcaption' === strtolower( $child->nodeName ) ) {
				$caption_el = $child;
				break;
			}
		}
		$caption = $caption_el ? $this->inline_html( $caption_el ) : null;

		$table = $figure->getElementsByTagName( 'table' )->item( 0 );
		if ( $table instanceof \DOMElement ) {
			return array( $this->table_block( $table, $caption ) );
		}
		if ( $figure->getElementsByTagName( 'img' )->length > 0 ) {
			if ( $caption_el ) {
				$caption_el->parentNode?->removeChild( $caption_el );
			}
			return $this->image_blocks( $figure, $caption );
		}
		return $this->blocks_from_children( $figure );
	}

	/**
	 * One image block per <img> inside $container; the caption goes to the last one.
	 *
	 * @return string[]
	 */
	private function image_blocks( \DOMElement $container, ?string $caption ): array {
		$images = 'img' === strtolower( $container->nodeName )
			? array( $container )
			: iterator_to_array( $container->getElementsByTagName( 'img' ) );
		$blocks = array();
		$last   = count( $images ) - 1;
		foreach ( array_values( $images ) as $index => $img ) {
			$blocks[] = $this->image_block( $img, $index === $last ? $caption : null );
		}
		return $blocks;
	}

	private function image_block( \DOMElement $img, ?string $caption ): string {
		$src   = $img->getAttribute( 'src' );
		$alt   = $img->getAttribute( 'alt' );
		$id    = (int) $img->getAttribute( 'data-hmi-id' );
		$attrs = array();
		if ( $id > 0 ) {
			$attrs['id'] = $id;
			$this->first_image_id ??= $id;
		}
		$attrs['sizeSlug'] = 'full';

		$link   = null;
		$parent = $img->parentNode;
		if ( $parent instanceof \DOMElement && 'a' === strtolower( $parent->nodeName ) && $parent->hasAttribute( 'href' ) ) {
			$link = $parent->getAttribute( 'href' );
		}
		if ( null === $link ) {
			$attrs['linkDestination'] = 'none';
		} elseif ( $parent instanceof \DOMElement && $parent->hasAttribute( 'data-hmi-media' ) ) {
			$attrs['linkDestination'] = 'media';
		} else {
			$attrs['linkDestination'] = 'custom';
		}

		$img_html = '<img src="' . $this->attr( $src ) . '" alt="' . $this->attr( $alt ) . '"' . ( $id > 0 ? ' class="wp-image-' . $id . '"' : '' ) . '/>';
		if ( null !== $link ) {
			$img_html = '<a href="' . $this->attr( $link ) . '">' . $img_html . '</a>';
		}
		$caption_html = ( null !== $caption && '' !== trim( $caption ) ) ? '<figcaption class="wp-element-caption">' . trim( $caption ) . '</figcaption>' : '';

		return $this->block( 'image', $attrs, '<figure class="wp-block-image size-full">' . $img_html . $caption_html . '</figure>' );
	}

	private function table_block( \DOMElement $table, ?string $caption ): string {
		// Table cells may only contain inline content; otherwise keep the table as HTML.
		$xpath = new \DOMXPath( $this->dom );
		$complex = $xpath->query( './/td//*[self::table or self::ul or self::ol or self::img or self::blockquote or self::pre] | .//th//*[self::table or self::ul or self::ol or self::img]', $table );
		if ( $complex && $complex->length > 0 ) {
			$this->warnings[] = 'Kept a complex table as Custom HTML block.';
			return $this->block( 'html', array(), $this->strip_internal( $this->dom->saveHTML( $table ) ?: '' ) );
		}

		if ( null === $caption ) {
			$caption_el = $table->getElementsByTagName( 'caption' )->item( 0 );
			$caption    = $caption_el ? $this->inline_html( $caption_el ) : null;
		}

		$sections = array( 'thead' => array(), 'tbody' => array(), 'tfoot' => array() );
		foreach ( $table->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$name = strtolower( $child->nodeName );
			if ( 'tr' === $name ) {
				$sections['tbody'][] = $child;
			} elseif ( isset( $sections[ $name ] ) ) {
				foreach ( $child->childNodes as $row ) {
					if ( $row instanceof \DOMElement && 'tr' === strtolower( $row->nodeName ) ) {
						$sections[ $name ][] = $row;
					}
				}
			}
		}

		$html = '<figure class="wp-block-table"><table>';
		foreach ( $sections as $section => $rows ) {
			if ( array() === $rows ) {
				continue;
			}
			$html .= "<{$section}>";
			foreach ( $rows as $row ) {
				$html .= '<tr>';
				foreach ( $row->childNodes as $cell ) {
					if ( ! $cell instanceof \DOMElement || ! in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
						continue;
					}
					$tag = strtolower( $cell->nodeName );
					if ( 'thead' === $section ) {
						$tag = 'th';
					}
					$span = '';
					foreach ( array( 'colspan', 'rowspan' ) as $attr ) {
						$value = (int) $cell->getAttribute( $attr );
						if ( $value > 1 ) {
							$span .= " {$attr}=\"{$value}\"";
						}
					}
					$html .= "<{$tag}{$span}>" . $this->inline_html( $cell ) . "</{$tag}>";
				}
				$html .= '</tr>';
			}
			$html .= "</{$section}>";
		}
		$html .= '</table>';
		if ( null !== $caption && '' !== trim( $caption ) ) {
			$html .= '<figcaption class="wp-element-caption">' . trim( $caption ) . '</figcaption>';
		}
		$html .= '</figure>';

		return $this->block( 'table', array( 'hasFixedLayout' => false ), $html );
	}

	private function pre_block( \DOMElement $pre ): string {
		$code = $pre->getElementsByTagName( 'code' )->item( 0 );
		if ( $code ) {
			$text = htmlspecialchars( rtrim( $pre->textContent, "\r\n" ), ENT_NOQUOTES, 'UTF-8' );
			return $this->block( 'code', array(), '<pre class="wp-block-code"><code>' . $text . '</code></pre>' );
		}
		return $this->block( 'preformatted', array(), '<pre class="wp-block-preformatted">' . $this->inline_html( $pre ) . '</pre>' );
	}

	/**
	 * Inline HTML of an element's children; block-level children are flattened with line breaks.
	 */
	private function inline_html( \DOMNode $el ): string {
		$segments = array();
		$current  = '';
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && ! in_array( strtolower( $child->nodeName ), self::INLINE, true ) ) {
				if ( '' !== trim( $current ) ) {
					$segments[] = trim( $current );
				}
				$current = '';
				$nested  = $this->inline_html( $child );
				if ( '' !== trim( $nested ) ) {
					$segments[] = trim( $nested );
				}
				continue;
			}
			$current .= $this->inline_node_html( $child );
		}
		if ( '' !== trim( $current ) ) {
			$segments[] = trim( $current );
		}
		return implode( '<br>', $segments );
	}

	private function inline_node_html( \DOMNode $node ): string {
		if ( $node instanceof \DOMElement && ! in_array( strtolower( $node->nodeName ), self::INLINE, true ) ) {
			return $this->inline_html( $node );
		}
		return $this->strip_internal( $this->dom->saveHTML( $node ) ?: '' );
	}

	/**
	 * Removes the importer's internal data-hmi-* attributes from serialized HTML.
	 */
	private function strip_internal( string $html ): string {
		return (string) preg_replace( '/\s+data-hmi-[a-z]+="[^"]*"/', '', $html );
	}

	private function is_standalone_image( \DOMElement $el ): bool {
		$name = strtolower( $el->nodeName );
		if ( 'img' === $name ) {
			return true;
		}
		return 'a' === $name && $this->only_images( $el );
	}

	/**
	 * True when the element contains images (optionally wrapped in links) and nothing else but whitespace/<br>.
	 */
	private function only_images( \DOMElement $el ): bool {
		if ( 0 === $el->getElementsByTagName( 'img' )->length ) {
			return false;
		}
		if ( '' !== Cleaner::text( $el ) ) {
			return false;
		}
		foreach ( $el->getElementsByTagName( '*' ) as $child ) {
			if ( ! in_array( strtolower( $child->nodeName ), array( 'img', 'a', 'br' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * GitHub-style heading anchor ("2.1 Über uns" → "21-über-uns"), unique within the document.
	 */
	private function anchor( string $text ): string {
		$slug = mb_strtolower( $text );
		$slug = (string) preg_replace( '/[^\p{L}\p{N}\s\-_]/u', '', $slug );
		$slug = (string) preg_replace( '/\s/u', '-', trim( $slug ) );
		if ( '' === $slug ) {
			$slug = 'section';
		}
		$candidate = $slug;
		for ( $i = 1; isset( $this->anchors[ $candidate ] ) || null !== $this->dom->getElementById( $candidate ); $i++ ) {
			$candidate = $slug . '-' . $i;
		}
		$this->anchors[ $candidate ] = true;
		return $candidate;
	}

	private function id_attr( \DOMElement $el ): string {
		$id = $el->getAttribute( 'id' );
		return '' === $id ? '' : ' id="' . $this->attr( $id ) . '"';
	}

	private function attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false );
	}

	/**
	 * Serializes a core block like WordPress' serialize_block() does.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private function block( string $name, array $attrs, string $html ): string {
		$json = '';
		if ( array() !== $attrs ) {
			$encoded = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$encoded = str_replace( array( '--', '<', '>', '&', '\\"' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ), $encoded );
			$json    = ' ' . $encoded;
		}
		return "<!-- wp:{$name}{$json} -->\n{$html}\n<!-- /wp:{$name} -->";
	}
}
