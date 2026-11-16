<?php
/**
 * REST API used by the admin screen.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (namespace html-md-importer/v1):
 *   POST   /sessions                    create a session
 *   POST   /sessions/{id}/upload        upload a file chunk (multipart: chunk, path, offset, kind=file|zip)
 *   POST   /sessions/{id}/extract       extract an uploaded ZIP
 *   POST   /sessions/{id}/scan          build the import plan
 *   POST   /sessions/{id}/media         import one media file   (path)
 *   POST   /sessions/{id}/item          import one plan item    (key, title, settings)
 *   POST   /sessions/{id}/finalize      rewrite links, delete uploaded files
 *   DELETE /sessions/{id}               discard the session
 */
final class Rest {

	public const NAMESPACE = 'html-md-importer/v1';

	public static function register(): void {
		$session_arg = array(
			'id' => array(
				'type'     => 'string',
				'pattern'  => '^[a-f0-9]{32}$',
				'required' => true,
			),
		);
		$routes = array(
			array( '/sessions', 'POST', 'create', array() ),
			array( '/sessions/(?P<id>[a-f0-9]{32})/upload', 'POST', 'upload', $session_arg ),
			array( '/sessions/(?P<id>[a-f0-9]{32})/extract', 'POST', 'extract', $session_arg ),
			array( '/sessions/(?P<id>[a-f0-9]{32})/scan', 'POST', 'scan', $session_arg ),
			array( '/sessions/(?P<id>[a-f0-9]{32})/media', 'POST', 'media', $session_arg ),
			array( '/sessions/(?P<id>[a-f0-9]{32})/item', 'POST', 'item', $session_arg ),
			array( '/sessions/(?P<id>[a-f0-9]{32})/finalize', 'POST', 'finalize', $session_arg ),
			array( '/sessions/(?P<id>[a-f0-9]{32})', 'DELETE', 'discard', $session_arg ),
		);
		foreach ( $routes as [ $route, $method, $callback, $args ] ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'can_import' ),
					'args'                => $args,
				)
			);
		}
	}

	public static function can_import(): bool {
		return current_user_can( 'upload_files' ) && ( current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' ) );
	}

	public static function create(): \WP_REST_Response|\WP_Error {
		$session = Session::create();
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return rest_ensure_response( array( 'id' => $session->id ) );
	}

	public static function upload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = Session::open( (string) $request['id'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$files = $request->get_file_params();
		$chunk = $files['chunk'] ?? null;
		if ( ! is_array( $chunk ) || UPLOAD_ERR_OK !== (int) ( $chunk['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $chunk['tmp_name'] ) ) {
			return new \WP_Error( 'hmi_upload', __( 'The upload failed. The file may exceed the server limit.', 'html-md-importer' ), array( 'status' => 400 ) );
		}

		if ( 'zip' === $request->get_param( 'kind' ) ) {
			$relpath = '__upload.zip';
		} else {
			$relpath = Paths::sanitize_relpath( (string) $request->get_param( 'path' ) );
			if ( null === $relpath ) {
				return new \WP_Error( 'hmi_path', __( 'File type or path not allowed.', 'html-md-importer' ), array( 'status' => 400 ) );
			}
		}

		$result = $session->store_chunk( $relpath, (string) $chunk['tmp_name'], max( 0, (int) $request->get_param( 'offset' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'stored' => $relpath ) );
	}

	public static function extract( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = Session::open( (string) $request['id'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$result = $session->extract_zip();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function scan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = Session::open( (string) $request['id'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$plan = ( new Scanner( $session->files_dir() ) )->scan();
		if ( array() === $plan['items'] ) {
			return new \WP_Error( 'hmi_empty', __( 'No HTML or Markdown files were found.', 'html-md-importer' ), array( 'status' => 400 ) );
		}
		$session->update_manifest(
			static function ( array $m ) use ( $plan ): array {
				$m['plan'] = $plan;
				return $m;
			}
		);

		// Mark items that were imported before (re-import updates them).
		$items = array();
		foreach ( $plan['items'] as $item ) {
			$existing = get_posts(
				array(
					'post_type'        => array( 'page', 'post' ),
					'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'fields'           => 'ids',
					'posts_per_page'   => 1,
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => Importer::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $item['key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			$item['existing'] = $existing ? array(
				'id'        => (int) $existing[0],
				'post_type' => get_post_type( (int) $existing[0] ),
				'edit_url'  => (string) get_edit_post_link( (int) $existing[0], 'raw' ),
			) : null;
			$items[] = $item;
		}

		$media = array();
		foreach ( $plan['media'] as $relpath => $info ) {
			$media[] = array(
				'path'    => $relpath,
				'size'    => $info['size'],
				'used_by' => array_values( array_unique( $info['used_by'] ) ),
			);
		}
		return rest_ensure_response(
			array(
				'items' => $items,
				'media' => $media,
			)
		);
	}

	public static function media( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = Session::open( (string) $request['id'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$result = Importer::import_media( $session, (string) $request->get_param( 'path' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = Session::open( (string) $request['id'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$settings = Importer::sanitize_settings( (array) $request->get_param( 'settings' ) );
		$type     = get_post_type_object( $settings['post_type'] );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			return new \WP_Error( 'hmi_forbidden', __( 'You are not allowed to create this content type.', 'html-md-importer' ), array( 'status' => 403 ) );
		}
		$result = Importer::import_item(
			$session,
			(string) $request->get_param( 'key' ),
			$settings,
			sanitize_text_field( (string) $request->get_param( 'title' ) )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function finalize( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = Session::open( (string) $request['id'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return rest_ensure_response( Importer::finalize( $session ) );
	}

	public static function discard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$session = Session::open( (string) $request['id'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$session->destroy();
		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
