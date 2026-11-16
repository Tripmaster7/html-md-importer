<?php
/**
 * Uploads referenced files into the Media Library (deduplicated by content hash).
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Media Library import.
 */
final class Media {

	public const HASH_META   = '_hmi_source_hash';
	public const SOURCE_META = '_hmi_source_path';

	/**
	 * Imports one file of the session, or returns the existing attachment with identical content.
	 *
	 * @param string $relpath Session-relative path of the file.
	 * @param string $alt     Alternative text (only set if the attachment has none).
	 * @param int    $parent  Post the attachment is attached to (0 = unattached).
	 */
	public static function import( Session $session, string $relpath, string $alt = '', int $parent = 0 ): int|\WP_Error {
		$path = $session->file_path( $relpath );
		if ( ! is_file( $path ) ) {
			return new \WP_Error( 'hmi_media_missing', sprintf( /* translators: %s: file path */ __( 'File not found: %s', 'html-md-importer' ), $relpath ) );
		}
		$hash     = (string) md5_file( $path );
		$existing = self::find_by_hash( $hash );
		if ( $existing ) {
			self::maybe_set_alt( $existing, $alt );
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload() moves the file, so hand it a copy.
		$name = Paths::basename( $relpath );
		$tmp  = wp_tempnam( $name );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			return new \WP_Error( 'hmi_media_copy', __( 'Could not copy the file for upload.', 'html-md-importer' ) );
		}

		$title = (string) preg_replace( '/\.[^.]+$/', '', $name );
		$id    = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$parent,
			null,
			array( 'post_title' => $title )
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		update_post_meta( $id, self::HASH_META, $hash );
		update_post_meta( $id, self::SOURCE_META, $relpath );
		self::maybe_set_alt( $id, $alt );
		return (int) $id;
	}

	public static function find_by_hash( string $hash ): int {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Attaches an unattached attachment to a post.
	 */
	public static function attach( int $attachment_id, int $post_id ): void {
		$attachment = get_post( $attachment_id );
		if ( $attachment && 0 === (int) $attachment->post_parent ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $post_id,
				)
			);
		}
	}

	private static function maybe_set_alt( int $id, string $alt ): void {
		$alt = trim( $alt );
		if ( '' === $alt || ! wp_attachment_is_image( $id ) || '' !== (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) {
			return;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}
}
