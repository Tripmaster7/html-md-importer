<?php
/**
 * Builds the import plan from the uploaded folder tree.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the session's file tree and decides which page/post each folder and document becomes.
 *
 * Rules:
 * - Every folder that (directly or below) contains documents becomes an item; its children become child items.
 * - Inside a folder, documents with identical content (e.g. content.html / preview.html) are one item;
 *   the preferred file is index.* > content.* > preview.* > alphabetical.
 * - If a folder holds several different documents, the preferred one represents the folder and the
 *   others become child items. Without a preferred name the folder becomes an empty "container".
 * - The upload root itself only becomes an item if it contains a document.
 * - Folders without any documents (e.g. "images") are not items.
 */
final class Scanner {

	/** @var array<string, array{docs: string[], dirs: string[], files: string[]}> */
	private array $tree = array();

	/** @var array<string, bool> */
	private array $has_docs = array();

	/** @var array<int, array<string, mixed>> */
	private array $items = array();

	/** @var array<string, array{alt: string, size: int, used_by: string[]}> */
	private array $media = array();

	/** @var array<string, string> doc relpath => item key */
	private array $doc_map = array();

	/** @var array<string, string> folder relpath => item key */
	private array $folder_map = array();

	public function __construct( private readonly string $root ) {}

	/**
	 * @return array{items: array<int, array<string, mixed>>, media: array<string, array<string, mixed>>, doc_map: array<string, string>, folder_map: array<string, string>}
	 */
	public function scan(): array {
		$this->read_tree();
		if ( $this->has_docs( '' ) ) {
			$this->build_folder( '', null, 0, 1 );
		}
		return array(
			'items'      => $this->items,
			'media'      => $this->media,
			'doc_map'    => $this->doc_map,
			'folder_map' => $this->folder_map,
		);
	}

	private function read_tree(): void {
		$this->tree = array( '' => array( 'docs' => array(), 'dirs' => array(), 'files' => array() ) );
		if ( ! is_dir( $this->root ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$prefix = strlen( rtrim( str_replace( '\\', '/', $this->root ), '/' ) ) + 1;
		foreach ( $iterator as $info ) {
			/** @var \SplFileInfo $info */
			$rel = substr( str_replace( '\\', '/', $info->getPathname() ), $prefix );
			if ( $info->isDir() ) {
				$this->tree[ $rel ] ??= array( 'docs' => array(), 'dirs' => array(), 'files' => array() );
				$parent = Paths::dirname( $rel );
				$this->tree[ $parent ] ??= array( 'docs' => array(), 'dirs' => array(), 'files' => array() );
				$this->tree[ $parent ]['dirs'][] = $rel;
				continue;
			}
			if ( ! str_ends_with( $rel, Paths::STORE_SUFFIX ) ) {
				continue;
			}
			$rel    = substr( $rel, 0, -strlen( Paths::STORE_SUFFIX ) );
			$parent = Paths::dirname( $rel );
			$this->tree[ $parent ] ??= array( 'docs' => array(), 'dirs' => array(), 'files' => array() );
			$this->tree[ $parent ]['files'][] = $rel;
			if ( Paths::is_doc( $rel ) ) {
				$this->tree[ $parent ]['docs'][] = $rel;
			}
		}
	}

	private function has_docs( string $folder ): bool {
		if ( isset( $this->has_docs[ $folder ] ) ) {
			return $this->has_docs[ $folder ];
		}
		$node   = $this->tree[ $folder ] ?? null;
		$result = false;
		if ( $node ) {
			$result = array() !== $node['docs'];
			foreach ( $node['dirs'] as $dir ) {
				$result = $this->has_docs( $dir ) || $result;
			}
		}
		return $this->has_docs[ $folder ] = $result;
	}

	private function build_folder( string $folder, ?string $parent_key, int $depth, int $order ): void {
		$node = $this->tree[ $folder ];

		// Analyse and group the folder's documents.
		$docs = $node['docs'];
		usort( $docs, array( $this, 'compare_docs' ) );
		$analyses = array();
		$groups   = array(); // hash => relpaths (priority order).
		foreach ( $docs as $doc ) {
			$analyses[ $doc ]                      = $this->analyse( $doc );
			$groups[ $analyses[ $doc ]['hash'] ][] = $doc;
		}
		$groups = array_values( $groups );

		$primary = null;
		$extra   = array();
		if ( 1 === count( $groups ) ) {
			$primary = $groups[0];
		} elseif ( count( $groups ) > 1 ) {
			if ( $this->is_preferred( $groups[0][0] ) ) {
				$primary = array_shift( $groups );
			}
			$extra = $groups;
		}

		$key      = 'dir:' . $folder;
		$item_key = $parent_key;
		if ( null !== $primary ) {
			$this->add_doc_item( $key, 'folder', $primary, $analyses, $parent_key, $depth, $order, Paths::basename( $folder ) );
			$item_key = $key;
		} elseif ( '' !== $folder ) {
			$this->items[] = array(
				'key'        => $key,
				'kind'       => 'container',
				'parent'     => $parent_key,
				'depth'      => $depth,
				'order'      => $order,
				'title'      => Paths::humanize( Paths::basename( $folder ) ),
				'source'     => null,
				'duplicates' => array(),
				'media'      => array(),
				'missing'    => array(),
				'links'      => 0,
				'warnings'   => array(),
			);
			$item_key = $key;
		}
		if ( $item_key === $key ) {
			$this->folder_map[ $folder ] = $key;
		}
		$child_depth = $item_key === $key ? $depth + 1 : $depth;

		// Children: additional documents and sub-folders, in natural order of their names.
		$children = array();
		foreach ( $extra as $group ) {
			$children[] = array( 'name' => Paths::basename( $group[0] ), 'group' => $group );
		}
		foreach ( $node['dirs'] as $dir ) {
			if ( $this->has_docs( $dir ) ) {
				$children[] = array( 'name' => Paths::basename( $dir ), 'dir' => $dir );
			}
		}
		usort( $children, static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );

		foreach ( $children as $index => $child ) {
			if ( isset( $child['dir'] ) ) {
				$this->build_folder( $child['dir'], $item_key, $child_depth, $index + 1 );
			} else {
				$this->add_doc_item( 'file:' . $child['group'][0], 'doc', $child['group'], $analyses, $item_key, $child_depth, $index + 1, $child['name'] );
			}
		}
	}

	/**
	 * @param string[]                            $group    Relpaths with identical content, preferred first.
	 * @param array<string, array<string, mixed>> $analyses
	 */
	private function add_doc_item( string $key, string $kind, array $group, array $analyses, ?string $parent_key, int $depth, int $order, string $fallback_name ): void {
		$source   = $group[0];
		$analysis = $analyses[ $source ];

		// Prefer a title found in any of the duplicates (content.html has no <title>, preview.html does).
		$title = $analysis['title'];
		foreach ( $group as $doc ) {
			if ( '' === $title ) {
				$title = $analyses[ $doc ]['title'];
			}
		}
		if ( '' === $title ) {
			$title = Paths::humanize( '' === $fallback_name ? Paths::basename( $source ) : $fallback_name );
		}

		foreach ( $group as $doc ) {
			$this->doc_map[ $doc ] = $key;
		}
		foreach ( $analysis['media'] as $relpath => $alt ) {
			$this->media[ $relpath ] ??= array(
				'alt'     => $alt,
				'size'    => (int) @filesize( $this->root . '/' . $relpath . Paths::STORE_SUFFIX ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'used_by' => array(),
			);
			if ( '' === $this->media[ $relpath ]['alt'] && '' !== $alt ) {
				$this->media[ $relpath ]['alt'] = $alt;
			}
			$this->media[ $relpath ]['used_by'][] = $key;
		}

		$this->items[] = array(
			'key'        => $key,
			'kind'       => $kind,
			'parent'     => $parent_key,
			'depth'      => $depth,
			'order'      => is_numeric( $analysis['front_matter']['order'] ?? null ) ? (int) $analysis['front_matter']['order'] : $order,
			'title'      => $title,
			'source'     => $source,
			'duplicates' => array_slice( $group, 1 ),
			'media'      => array_keys( $analysis['media'] ),
			'missing'    => $analysis['missing'],
			'links'      => $analysis['links'],
			'warnings'   => array(),
		);
	}

	/**
	 * Parses a document and extracts what the plan needs.
	 *
	 * @return array{title: string, hash: string, media: array<string, string>, missing: string[], links: int, front_matter: array<string, mixed>}
	 */
	private function analyse( string $relpath ): array {
		$document = Document::load( $this->root . '/' . $relpath . Paths::STORE_SUFFIX, $relpath );
		( new Cleaner() )->clean( $document );

		$title = '';
		if ( isset( $document->front_matter['title'] ) && '' !== trim( (string) $document->front_matter['title'] ) ) {
			$title = trim( (string) $document->front_matter['title'] );
		} else {
			$title = Cleaner::first_h1_text( $document ) ?? $document->head_title;
		}

		$base    = Paths::dirname( $relpath );
		$media   = array();
		$missing = array();
		$srcs    = array();
		$links   = 0;

		foreach ( $document->body->getElementsByTagName( 'img' ) as $img ) {
			$src    = $img->getAttribute( 'src' );
			$srcs[] = $src;
			$target = Paths::resolve_reference( $src, $base );
			if ( null === $target ) {
				continue; // External or data: image, left as is.
			}
			if ( Paths::is_media( $target ) && is_file( $this->root . '/' . $target . Paths::STORE_SUFFIX ) ) {
				$media[ $target ] ??= trim( $img->getAttribute( 'alt' ) );
			} else {
				$missing[] = $src;
			}
		}
		foreach ( $document->body->getElementsByTagName( 'a' ) as $a ) {
			$target = Paths::resolve_reference( $a->getAttribute( 'href' ), $base );
			if ( null === $target ) {
				continue;
			}
			if ( Paths::is_media( $target ) ) {
				if ( is_file( $this->root . '/' . $target . Paths::STORE_SUFFIX ) ) {
					$media[ $target ] ??= '';
				} else {
					$missing[] = $a->getAttribute( 'href' );
				}
			} elseif ( Paths::is_doc( $target ) || isset( $this->tree[ $target ] ) ) {
				++$links;
			}
		}

		return array(
			'title'        => $title,
			'hash'         => md5( Cleaner::text( $document->body ) . '|' . implode( '|', $srcs ) ),
			'media'        => $media,
			'missing'      => array_values( array_unique( $missing ) ),
			'links'        => $links,
			'front_matter' => $document->front_matter,
		);
	}

	private function is_preferred( string $relpath ): bool {
		$name = strtolower( (string) preg_replace( '/\.[^.]+$/', '', Paths::basename( $relpath ) ) );
		return in_array( $name, Paths::PREFERRED_NAMES, true );
	}

	private function compare_docs( string $a, string $b ): int {
		$rank = static function ( string $path ): int {
			$name = strtolower( (string) preg_replace( '/\.[^.]+$/', '', Paths::basename( $path ) ) );
			$pos  = array_search( $name, Paths::PREFERRED_NAMES, true );
			return false === $pos ? 100 : (int) $pos;
		};
		return $rank( $a ) <=> $rank( $b ) ?: strnatcasecmp( $a, $b );
	}
}
