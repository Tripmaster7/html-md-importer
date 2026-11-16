<?php
/**
 * Creates/updates one post or page per plan item and rewrites links afterwards.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Import orchestration.
 */
final class Importer {

	public const SOURCE_META      = '_hmi_source';
	public const SOURCE_FILE_META = '_hmi_source_file';
	private const LINK_PREFIX     = '#hmi-link-';

	/**
	 * Normalizes the settings sent by the admin screen.
	 *
	 * @param array<string, mixed> $raw
	 * @return array{post_type: string, status: string, parent_page: int, parent_cat: int, update_existing: bool, strip_h1: bool, featured_image: bool, folder_categories: bool, containers: bool}
	 */
	public static function sanitize_settings( array $raw ): array {
		$type   = in_array( $raw['post_type'] ?? '', array( 'page', 'post' ), true ) ? (string) $raw['post_type'] : 'page';
		$status = in_array( $raw['status'] ?? '', array( 'draft', 'pending', 'private', 'publish' ), true ) ? (string) $raw['status'] : 'draft';
		$bool   = static fn( string $key, bool $default ) => isset( $raw[ $key ] ) ? filter_var( $raw[ $key ], FILTER_VALIDATE_BOOLEAN ) : $default;
		return array(
			'post_type'         => $type,
			'status'            => $status,
			'parent_page'       => max( 0, (int) ( $raw['parent_page'] ?? 0 ) ),
			'parent_cat'        => max( 0, (int) ( $raw['parent_cat'] ?? 0 ) ),
			'update_existing'   => $bool( 'update_existing', true ),
			'strip_h1'          => $bool( 'strip_h1', true ),
			'featured_image'    => $bool( 'featured_image', false ),
			'folder_categories' => $bool( 'folder_categories', true ),
			'containers'        => $bool( 'containers', true ),
		);
	}

	/**
	 * Imports one media file of the plan (phase 1, one request per file to avoid timeouts).
	 *
	 * @return array{relpath: string, id: int, url: string}|\WP_Error
	 */
	public static function import_media( Session $session, string $relpath ): array|\WP_Error {
		$manifest = $session->manifest();
		$info     = $manifest['plan']['media'][ $relpath ] ?? null;
		if ( null === $info ) {
			return new \WP_Error( 'hmi_unknown_media', __( 'This file is not part of the import plan.', 'html-md-importer' ), array( 'status' => 400 ) );
		}
		$id = Media::import( $session, $relpath, (string) ( $info['alt'] ?? '' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$session->update_manifest(
			static function ( array $m ) use ( $relpath, $id ): array {
				$m['media_ids'][ $relpath ] = $id;
				return $m;
			}
		);
		return array(
			'relpath' => $relpath,
			'id'      => $id,
			'url'     => (string) wp_get_attachment_url( $id ),
		);
	}

	/**
	 * Imports one plan item (phase 2). Items must be imported parents first.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function import_item( Session $session, string $key, array $settings, string $title_override = '' ): array|\WP_Error {
		$manifest = $session->manifest();
		$plan     = $manifest['plan'] ?? null;
		$item     = null;
		foreach ( (array) ( $plan['items'] ?? array() ) as $candidate ) {
			if ( $candidate['key'] === $key ) {
				$item = $candidate;
				break;
			}
		}
		if ( null === $item ) {
			return new \WP_Error( 'hmi_unknown_item', __( 'This item is not part of the import plan.', 'html-md-importer' ), array( 'status' => 400 ) );
		}

		$warnings     = array();
		$links        = array();
		$media_ids    = array();
		$front_matter = array();
		$content      = '';
		$title        = '' !== trim( $title_override ) ? trim( $title_override ) : (string) $item['title'];
		$first_image  = null;
		$post_type    = $settings['post_type'];

		// Containers: an empty parent page, or just a category for posts.
		if ( 'container' === $item['kind'] ) {
			if ( 'post' === $post_type ) {
				$term = self::ensure_folder_term( $session, $item, $settings, $title );
				return array(
					'key'      => $key,
					'post_id'  => 0,
					'term_id'  => $term,
					'title'    => $title,
					'warnings' => array(),
				);
			}
			if ( ! $settings['containers'] ) {
				return array(
					'key'      => $key,
					'post_id'  => 0,
					'title'    => $title,
					'skipped'  => true,
					'warnings' => array(),
				);
			}
		}

		if ( null !== $item['source'] ) {
			$document = Document::load( $session->file_path( $item['source'] ), $item['source'] );
			( new Cleaner() )->clean( $document );
			$front_matter = $document->front_matter;

			$h1 = $settings['strip_h1'] ? Cleaner::extract_leading_h1( $document ) : null;
			if ( '' === trim( $title_override ) ) {
				$title = (string) ( $front_matter['title'] ?? $h1 ?? $item['title'] );
			}

			$media_ids = self::resolve_references( $session, $document, $manifest, $links, $warnings );

			$converter   = new BlockConverter();
			$content     = $converter->convert( $document );
			$first_image = $converter->first_image_id;
			array_push( $warnings, ...array_unique( $converter->warnings ) );
		}

		if ( isset( $front_matter['type'] ) && in_array( $front_matter['type'], array( 'page', 'post' ), true ) ) {
			$post_type = (string) $front_matter['type'];
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_status'  => self::status( $front_matter, $settings['status'], $post_type ),
			'post_title'   => $title,
			'post_content' => $content,
			'post_author'  => get_current_user_id(),
			'meta_input'   => array(
				self::SOURCE_META      => $key,
				self::SOURCE_FILE_META => (string) $item['source'],
			),
		);
		if ( 'page' === $post_type ) {
			$postarr['post_parent'] = self::parent_post_id( $manifest, $item, $settings );
			$postarr['menu_order']  = (int) $item['order'];
		}
		if ( ! empty( $front_matter['excerpt'] ) ) {
			$postarr['post_excerpt'] = (string) $front_matter['excerpt'];
		}
		if ( ! empty( $front_matter['date'] ) && false !== strtotime( (string) $front_matter['date'] ) ) {
			$postarr['post_date'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $front_matter['date'] ) );
		}

		$existing = $settings['update_existing'] ? self::find_existing( $key, $post_type ) : 0;
		if ( $existing ) {
			$postarr['ID'] = $existing;
		} else {
			$postarr['post_name'] = sanitize_title( (string) ( $front_matter['slug'] ?? $title ) );
		}

		$post_id = $existing ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;

		foreach ( $media_ids as $attachment_id ) {
			Media::attach( $attachment_id, $post_id );
		}
		if ( $settings['featured_image'] && $first_image ) {
			set_post_thumbnail( $post_id, $first_image );
		}

		if ( 'post' === $post_type ) {
			$categories = array();
			if ( $settings['folder_categories'] ) {
				$folder_item = 'folder' === $item['kind'] ? $item : self::find_item( $plan, $item['parent'] );
				$term        = $folder_item ? self::ensure_folder_term( $session, $folder_item, $settings, 'folder' === $item['kind'] ? $title : (string) $folder_item['title'] ) : 0;
				if ( $term ) {
					$categories[] = $term;
				} elseif ( $settings['parent_cat'] ) {
					$categories[] = $settings['parent_cat'];
				}
			} elseif ( $settings['parent_cat'] ) {
				$categories[] = $settings['parent_cat'];
			}
			foreach ( (array) ( $front_matter['categories'] ?? array() ) as $name ) {
				$term = self::ensure_term( (string) $name, 0 );
				if ( $term ) {
					$categories[] = $term;
				}
			}
			if ( $categories ) {
				wp_set_post_categories( $post_id, array_values( array_unique( $categories ) ) );
			}
			if ( ! empty( $front_matter['tags'] ) ) {
				wp_set_post_tags( $post_id, array_map( 'strval', (array) $front_matter['tags'] ) );
			}
		}

		$session->update_manifest(
			static function ( array $m ) use ( $key, $post_id, $links ): array {
				$m['posts'][ $key ] = $post_id;
				$m['links']         = array_merge( (array) ( $m['links'] ?? array() ), $links );
				return $m;
			}
		);

		return array(
			'key'       => $key,
			'post_id'   => $post_id,
			'title'     => get_the_title( $post_id ),
			'post_type' => $post_type,
			'status'    => get_post_status( $post_id ),
			'updated'   => (bool) $existing,
			'edit_url'  => (string) get_edit_post_link( $post_id, 'raw' ),
			'view_url'  => self::permalink( $post_id ),
			'warnings'  => array_values( $warnings ),
		);
	}

	/**
	 * Phase 3: rewrites links between imported documents and removes the uploaded files.
	 *
	 * @return array{updated: int, unresolved: int}
	 */
	public static function finalize( Session $session ): array {
		$manifest   = $session->manifest();
		$posts      = (array) ( $manifest['posts'] ?? array() );
		$links      = (array) ( $manifest['links'] ?? array() );
		$updated    = 0;
		$unresolved = 0;

		foreach ( array_unique( array_values( $posts ) ) as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || ! str_contains( $post->post_content, self::LINK_PREFIX ) ) {
				continue;
			}
			$content = (string) preg_replace_callback(
				'#<a href="' . preg_quote( self::LINK_PREFIX, '#' ) . '([a-f0-9]{12})"([^>]*)>(.*?)</a>#s',
				static function ( array $m ) use ( $links, $posts, &$unresolved ): string {
					$link   = $links[ $m[1] ] ?? null;
					$target = $link ? (int) ( $posts[ $link['key'] ] ?? 0 ) : 0;
					if ( ! $target ) {
						++$unresolved;
						return $m[3]; // Target not imported: keep the text only.
					}
					$url = self::permalink( $target ) . ( '' !== $link['fragment'] ? '#' . rawurlencode( $link['fragment'] ) : '' );
					return '<a href="' . esc_url( $url ) . '"' . $m[2] . '>' . $m[3] . '</a>';
				},
				$post->post_content
			);
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => $content,
					)
				)
			);
			++$updated;
		}

		$session->delete_files();
		return array(
			'updated'    => $updated,
			'unresolved' => $unresolved,
		);
	}

	/**
	 * Uploads/rewrites images and media links and replaces links to other documents with placeholders.
	 *
	 * @param array<string, mixed>                           $manifest
	 * @param array<string, array{key: string, fragment: string}> $links    Receives link placeholders.
	 * @param string[]                                       $warnings
	 * @return int[] Attachment IDs used by the document.
	 */
	private static function resolve_references( Session $session, Document $document, array $manifest, array &$links, array &$warnings ): array {
		$base       = Paths::dirname( $document->relpath );
		$plan       = $manifest['plan'];
		$media_ids  = (array) ( $manifest['media_ids'] ?? array() );
		$used       = array();
		$attachment = static function ( string $target, string $alt ) use ( $session, $plan, &$media_ids, &$warnings ): int {
			if ( isset( $media_ids[ $target ] ) && get_post( (int) $media_ids[ $target ] ) ) {
				return (int) $media_ids[ $target ];
			}
			if ( ! isset( $plan['media'][ $target ] ) && ! is_file( $session->file_path( $target ) ) ) {
				return 0;
			}
			$id = Media::import( $session, $target, $alt );
			if ( is_wp_error( $id ) ) {
				$warnings[] = $target . ': ' . $id->get_error_message();
				return 0;
			}
			$media_ids[ $target ] = $id;
			return $id;
		};

		foreach ( iterator_to_array( $document->body->getElementsByTagName( 'img' ) ) as $img ) {
			$src    = $img->getAttribute( 'src' );
			$target = Paths::resolve_reference( $src, $base );
			if ( null === $target ) {
				continue; // External image: keep.
			}
			$id = Paths::is_media( $target ) ? $attachment( $target, $img->getAttribute( 'alt' ) ) : 0;
			if ( ! $id ) {
				/* translators: %s: image path */
				$warnings[] = sprintf( __( 'Image not found and removed: %s', 'html-md-importer' ), $src );
				$img->parentNode?->removeChild( $img );
				continue;
			}
			$used[] = $id;
			$img->setAttribute( 'src', (string) wp_get_attachment_url( $id ) );
			$img->setAttribute( 'data-hmi-id', (string) $id );
		}

		foreach ( iterator_to_array( $document->body->getElementsByTagName( 'a' ) ) as $a ) {
			$fragment = '';
			$target   = Paths::resolve_reference( $a->getAttribute( 'href' ), $base, $fragment );
			if ( null === $target ) {
				continue;
			}
			if ( Paths::is_media( $target ) ) {
				$id = $attachment( $target, '' );
				if ( $id ) {
					$used[] = $id;
					$a->setAttribute( 'href', (string) wp_get_attachment_url( $id ) );
					$a->setAttribute( 'data-hmi-media', '1' );
					continue;
				}
			}
			$item_key = $plan['doc_map'][ $target ] ?? $plan['folder_map'][ rtrim( $target, '/' ) ] ?? null;
			if ( null !== $item_key ) {
				$token           = substr( md5( $item_key . '#' . $fragment ), 0, 12 );
				$links[ $token ] = array(
					'key'      => $item_key,
					'fragment' => $fragment,
				);
				$a->setAttribute( 'href', self::LINK_PREFIX . $token );
				continue;
			}
			// Local link to something that is not imported: keep the text only.
			/* translators: %s: link target */
			$warnings[] = sprintf( __( 'Link target not found, link removed: %s', 'html-md-importer' ), $a->getAttribute( 'href' ) );
			while ( $a->firstChild ) {
				$a->parentNode?->insertBefore( $a->firstChild, $a );
			}
			$a->parentNode?->removeChild( $a );
		}

		if ( $media_ids !== (array) ( $manifest['media_ids'] ?? array() ) ) {
			$session->update_manifest(
				static function ( array $m ) use ( $media_ids ): array {
					$m['media_ids'] = array_merge( (array) ( $m['media_ids'] ?? array() ), $media_ids );
					return $m;
				}
			);
		}
		return array_values( array_unique( $used ) );
	}

	/**
	 * Nearest imported ancestor, else the selected parent page.
	 *
	 * @param array<string, mixed> $manifest
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $settings
	 */
	private static function parent_post_id( array $manifest, array $item, array $settings ): int {
		$posts  = (array) ( $manifest['posts'] ?? array() );
		$parent = $item['parent'];
		while ( null !== $parent ) {
			if ( ! empty( $posts[ $parent ] ) && 'page' === get_post_type( (int) $posts[ $parent ] ) ) {
				return (int) $posts[ $parent ];
			}
			$parent_item = self::find_item( $manifest['plan'], $parent );
			$parent      = $parent_item['parent'] ?? null;
		}
		return (int) $settings['parent_page'];
	}

	/**
	 * Category for a folder item (created on demand, nested like the folders).
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $settings
	 */
	private static function ensure_folder_term( Session $session, array $item, array $settings, string $name ): int {
		$manifest = $session->manifest();
		if ( ! empty( $manifest['terms'][ $item['key'] ] ) && term_exists( (int) $manifest['terms'][ $item['key'] ], 'category' ) ) {
			return (int) $manifest['terms'][ $item['key'] ];
		}
		$parent_term = (int) $settings['parent_cat'];
		$parent      = $item['parent'];
		while ( null !== $parent ) {
			if ( ! empty( $manifest['terms'][ $parent ] ) ) {
				$parent_term = (int) $manifest['terms'][ $parent ];
				break;
			}
			$parent_item = self::find_item( $manifest['plan'], $parent );
			if ( $parent_item && in_array( $parent_item['kind'], array( 'folder', 'container' ), true ) ) {
				$parent_term = self::ensure_folder_term( $session, $parent_item, $settings, (string) $parent_item['title'] );
				break;
			}
			$parent = $parent_item['parent'] ?? null;
		}
		$term = self::ensure_term( $name, $parent_term );
		if ( $term ) {
			$session->update_manifest(
				static function ( array $m ) use ( $item, $term ): array {
					$m['terms'][ $item['key'] ] = $term;
					return $m;
				}
			);
		}
		return $term;
	}

	private static function ensure_term( string $name, int $parent ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}
		$exists = term_exists( $name, 'category', $parent );
		if ( is_array( $exists ) ) {
			return (int) $exists['term_id'];
		}
		$created = wp_insert_term( $name, 'category', array( 'parent' => $parent ) );
		if ( is_wp_error( $created ) ) {
			$existing = $created->get_error_data( 'term_exists' );
			return is_numeric( $existing ) ? (int) $existing : 0;
		}
		return (int) $created['term_id'];
	}

	private static function find_existing( string $key, string $post_type ): int {
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * @param array<string, mixed>|null $plan
	 * @return array<string, mixed>|null
	 */
	private static function find_item( ?array $plan, ?string $key ): ?array {
		if ( null === $key || null === $plan ) {
			return null;
		}
		foreach ( (array) $plan['items'] as $item ) {
			if ( $item['key'] === $key ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $front_matter
	 */
	private static function status( array $front_matter, string $default, string $post_type ): string {
		$status = (string) ( $front_matter['status'] ?? '' );
		if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
			$status = $default;
		}
		$type_object = get_post_type_object( $post_type );
		if ( in_array( $status, array( 'publish', 'private' ), true ) && $type_object && ! current_user_can( $type_object->cap->publish_posts ) ) {
			return 'pending';
		}
		return $status;
	}

	/**
	 * Pretty permalink even for drafts (so links stay valid after publishing).
	 */
	public static function permalink( int $post_id ): string {
		$status = get_post_status( $post_id );
		if ( in_array( $status, array( 'publish', 'private' ), true ) ) {
			return (string) get_permalink( $post_id );
		}
		require_once ABSPATH . 'wp-admin/includes/post.php';
		[ $template, $name ] = get_sample_permalink( $post_id );
		if ( '' === (string) $name || ! str_contains( (string) $template, '%' ) ) {
			return (string) get_permalink( $post_id );
		}
		return str_replace( array( '%pagename%', '%postname%' ), $name, (string) $template );
	}
}
