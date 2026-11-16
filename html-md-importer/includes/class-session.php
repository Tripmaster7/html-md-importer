<?php
/**
 * Temporary storage for one import run.
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

/**
 * An import session lives in wp-content/uploads/hmi-sessions/<random id>/ and holds:
 *   files/…            the uploaded tree (every file with the .hmi suffix, never executable or served as HTML)
 *   manifest.json.hmi  owner, plan, created posts/attachments
 * Sessions older than one day are removed by a daily cron job.
 */
final class Session {

	private const DIR_NAME          = 'hmi-sessions';
	private const MANIFEST          = 'manifest.json' . Paths::STORE_SUFFIX;
	private const MAX_FILE_SIZE     = 256 * MB_IN_BYTES;
	private const MAX_ZIP_ENTRIES   = 10000;
	private const MAX_ZIP_TOTAL     = 2 * GB_IN_BYTES;
	public const  EXPIRY            = DAY_IN_SECONDS;

	private function __construct( public readonly string $id, private readonly string $dir ) {}

	/**
	 * Creates a new session for the current user.
	 */
	public static function create(): self|\WP_Error {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$id  = bin2hex( random_bytes( 16 ) );
		$dir = $base . '/' . $id;
		if ( ! wp_mkdir_p( $dir . '/files' ) ) {
			return new \WP_Error( 'hmi_storage', __( 'Could not create the temporary import folder.', 'html-md-importer' ) );
		}
		$session = new self( $id, $dir );
		$session->write_manifest(
			array(
				'user'    => get_current_user_id(),
				'created' => time(),
				'plan'    => null,
				'posts'   => array(),
				'terms'   => array(),
				'links'   => array(),
			)
		);
		return $session;
	}

	/**
	 * Opens an existing session owned by the current user.
	 */
	public static function open( string $id ): self|\WP_Error {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) || ! is_dir( $base . '/' . $id ) ) {
			return new \WP_Error( 'hmi_session', __( 'The import session does not exist or has expired.', 'html-md-importer' ), array( 'status' => 404 ) );
		}
		$session  = new self( $id, $base . '/' . $id );
		$manifest = $session->manifest();
		if ( (int) ( $manifest['user'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'hmi_session', __( 'This import session belongs to another user.', 'html-md-importer' ), array( 'status' => 403 ) );
		}
		return $session;
	}

	public function files_dir(): string {
		return $this->dir . '/files';
	}

	/**
	 * Absolute path of a stored file.
	 */
	public function file_path( string $relpath ): string {
		return $this->files_dir() . '/' . $relpath . Paths::STORE_SUFFIX;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function manifest(): array {
		$raw = @file_get_contents( $this->dir . '/' . self::MANIFEST ); // phpcs:ignore
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string, mixed> $manifest
	 */
	public function write_manifest( array $manifest ): void {
		file_put_contents( $this->dir . '/' . self::MANIFEST, (string) wp_json_encode( $manifest ), LOCK_EX ); // phpcs:ignore
	}

	/**
	 * Read-modify-write of the manifest under an exclusive lock.
	 *
	 * @param callable(array<string, mixed>): array<string, mixed> $callback
	 * @return array<string, mixed>
	 */
	public function update_manifest( callable $callback ): array {
		$lock = fopen( $this->dir . '/manifest.lock', 'c' ); // phpcs:ignore
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}
		$manifest = $callback( $this->manifest() );
		$this->write_manifest( $manifest );
		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore
		}
		return $manifest;
	}

	/**
	 * Stores one chunk of an uploaded file at the given byte offset.
	 *
	 * The file is truncated to $offset first, so a retried chunk never gets appended twice.
	 *
	 * @param string $relpath  Sanitized relative path (or '__upload.zip' for a ZIP archive).
	 * @param string $tmp_file PHP upload temp file holding the chunk.
	 * @param int    $offset   Byte offset of the chunk within the file.
	 */
	public function store_chunk( string $relpath, string $tmp_file, int $offset ): true|\WP_Error {
		$target = '__upload.zip' === $relpath ? $this->dir . '/upload.zip' . Paths::STORE_SUFFIX : $this->file_path( $relpath );
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new \WP_Error( 'hmi_storage', __( 'Could not create the temporary import folder.', 'html-md-importer' ) );
		}
		clearstatcache( true, $target );
		$existing = is_file( $target ) ? (int) filesize( $target ) : 0;
		if ( $offset > $existing ) {
			return new \WP_Error( 'hmi_chunk_order', __( 'Upload chunks arrived out of order.', 'html-md-importer' ), array( 'status' => 409 ) );
		}
		if ( $offset + (int) filesize( $tmp_file ) > ( '__upload.zip' === $relpath ? self::MAX_ZIP_TOTAL : self::MAX_FILE_SIZE ) ) {
			return new \WP_Error( 'hmi_too_large', __( 'The file is too large.', 'html-md-importer' ), array( 'status' => 413 ) );
		}
		$in  = fopen( $tmp_file, 'rb' ); // phpcs:ignore
		$out = fopen( $target, 'c+b' ); // phpcs:ignore
		if ( ! $in || ! $out ) {
			return new \WP_Error( 'hmi_storage', __( 'Could not write the uploaded file.', 'html-md-importer' ) );
		}
		ftruncate( $out, $offset );
		fseek( $out, $offset );
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore
		fclose( $out ); // phpcs:ignore
		return true;
	}

	/**
	 * Extracts the uploaded ZIP into files/, keeping only allowed file types.
	 *
	 * @return array{extracted: int, skipped: int}|\WP_Error
	 */
	public function extract_zip(): array|\WP_Error {
		$zip_path = $this->dir . '/upload.zip' . Paths::STORE_SUFFIX;
		if ( ! class_exists( \ZipArchive::class ) ) {
			return new \WP_Error( 'hmi_zip', __( 'The PHP zip extension is not available on this server. Please upload the folder instead.', 'html-md-importer' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'hmi_zip', __( 'The ZIP file could not be opened.', 'html-md-importer' ) );
		}
		if ( $zip->numFiles > self::MAX_ZIP_ENTRIES ) {
			$zip->close();
			return new \WP_Error( 'hmi_zip', __( 'The ZIP file contains too many files.', 'html-md-importer' ) );
		}

		$extracted = 0;
		$skipped   = 0;
		$total     = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i, \ZipArchive::FL_ENC_RAW );
			if ( ! $stat || str_ends_with( $stat['name'], '/' ) ) {
				continue;
			}
			$name = $stat['name'];
			if ( ! mb_check_encoding( $name, 'UTF-8' ) ) {
				$name = mb_convert_encoding( $name, 'UTF-8', 'CP850' ); // Legacy Windows ZIPs (e.g. "Übersicht").
			}
			$relpath = Paths::sanitize_relpath( $name );
			if ( null === $relpath || $stat['size'] > self::MAX_FILE_SIZE ) {
				++$skipped;
				continue;
			}
			$total += (int) $stat['size'];
			if ( $total > self::MAX_ZIP_TOTAL ) {
				$zip->close();
				return new \WP_Error( 'hmi_zip', __( 'The ZIP file is too large when extracted.', 'html-md-importer' ) );
			}
			$target = $this->file_path( $relpath );
			wp_mkdir_p( dirname( $target ) );
			$in  = $zip->getStream( $stat['name'] );
			$out = fopen( $target, 'wb' ); // phpcs:ignore
			if ( ! $in || ! $out ) {
				++$skipped;
				continue;
			}
			// Copy with a hard limit in case the declared size is wrong (ZIP bombs).
			$written = stream_copy_to_stream( $in, $out, self::MAX_FILE_SIZE + 1 );
			fclose( $in ); // phpcs:ignore
			fclose( $out ); // phpcs:ignore
			if ( false === $written || $written > self::MAX_FILE_SIZE ) {
				wp_delete_file( $target );
				++$skipped;
				continue;
			}
			++$extracted;
		}
		$zip->close();
		wp_delete_file( $zip_path );
		return array(
			'extracted' => $extracted,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Deletes the uploaded files but keeps the manifest (needed for the result list).
	 */
	public function delete_files(): void {
		self::rmdir( $this->files_dir() );
	}

	/**
	 * Deletes the whole session.
	 */
	public function destroy(): void {
		self::rmdir( $this->dir );
	}

	/**
	 * Cron callback: removes sessions older than EXPIRY.
	 */
	public static function cleanup_expired(): void {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return;
		}
		foreach ( glob( $base . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( preg_match( '/[a-f0-9]{32}$/', $dir ) && filemtime( $dir ) < time() - self::EXPIRY ) {
				self::rmdir( $dir );
			}
		}
	}

	/**
	 * Base directory for all sessions; created and protected on first use.
	 */
	private static function base_dir(): string|\WP_Error {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'hmi_storage', (string) $uploads['error'] );
		}
		$base = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/' . self::DIR_NAME;
		if ( ! is_dir( $base ) ) {
			if ( ! wp_mkdir_p( $base ) ) {
				return new \WP_Error( 'hmi_storage', __( 'Could not create the temporary import folder.', 'html-md-importer' ) );
			}
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			file_put_contents( $base . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		return $base;
	}

	private static function rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $info ) {
			$info->isDir() ? @rmdir( $info->getPathname() ) : @unlink( $info->getPathname() ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
