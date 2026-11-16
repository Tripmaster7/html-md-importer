<?php
/**
 * End-to-end run of the import flow against minimal in-memory WordPress stubs.
 *
 * Exercises Session (chunked storage, ZIP extraction), Scanner, Importer (media, items, finalize)
 * without a database. It catches PHP errors and logic bugs – it does NOT replace a test on a real site
 * (no block validation, no real media processing).
 *
 * Usage: php tests/integration-stubbed.php <zip-or-folder> [page|post]
 */

declare(strict_types=1);

// ----------------------------------------------------------------------------- environment
$tmp = sys_get_temp_dir() . '/hmi-int-' . getmypid();
@mkdir( $tmp . '/wp/wp-admin/includes', 0777, true );
foreach ( array( 'file', 'media', 'image', 'post' ) as $inc ) {
	file_put_contents( "$tmp/wp/wp-admin/includes/$inc.php", '<?php' );
}
define( 'ABSPATH', $tmp . '/wp/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1024 * 1024 );
define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
define( 'DAY_IN_SECONDS', 86400 );

// ----------------------------------------------------------------------------- stubs
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_message(): string { return $this->message; }
	public function get_error_data( $code = '' ) { return $this->data; }
}
final class DB {
	public static array $posts = array();
	public static array $meta  = array();
	public static array $terms = array();
	public static int $next    = 1;
}
function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function wp_mkdir_p( $d ): bool { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_upload_dir( $a = null, $b = true ) { global $tmp; return array( 'basedir' => $tmp . '/uploads', 'error' => false ); }
function get_current_user_id(): int { return 1; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( iconv( 'UTF-8', 'ASCII//TRANSLIT', (string) $s ) ) ), '-' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url( $u ) { return str_replace( '&', '&#038;', $u ); }
function current_user_can( $c ): bool { return true; }
function get_post_type_object( $t ) { return (object) array( 'cap' => (object) array( 'publish_posts' => 'publish', 'create_posts' => 'edit' ) ); }
function update_post_meta( $id, $k, $v ) { DB::$meta[ $id ][ $k ] = wp_unslash( $v ); }
function get_post_meta( $id, $k, $single = false ) { return DB::$meta[ $id ][ $k ] ?? ''; }
function get_post( $id ) { return isset( DB::$posts[ $id ] ) ? (object) DB::$posts[ $id ] : null; }
function get_post_type( $id ) { return DB::$posts[ $id ]['post_type'] ?? false; }
function get_post_status( $id ) { return DB::$posts[ $id ]['post_status'] ?? false; }
function get_the_title( $id ) { return DB::$posts[ $id ]['post_title'] ?? ''; }
function get_edit_post_link( $id, $c = '' ) { return "https://example.test/wp-admin/post.php?post=$id&action=edit"; }
function get_permalink( $id ) { return 'https://example.test/?p=' . $id; }
function get_sample_permalink( $id ) {
	$path = array();
	for ( $p = DB::$posts[ $id ]['post_parent'] ?? 0; $p; $p = DB::$posts[ $p ]['post_parent'] ?? 0 ) {
		array_unshift( $path, DB::$posts[ $p ]['post_name'] );
	}
	return array( 'https://example.test/' . ( $path ? implode( '/', $path ) . '/' : '' ) . '%pagename%/', DB::$posts[ $id ]['post_name'] );
}
function get_posts( $args ) {
	$out = array();
	foreach ( DB::$posts as $id => $p ) {
		if ( (array) $args['post_type'] !== array( $p['post_type'] ) && ! in_array( $p['post_type'], (array) $args['post_type'], true ) ) { continue; }
		if ( ( DB::$meta[ $id ][ $args['meta_key'] ] ?? null ) === $args['meta_value'] ) { $out[] = $id; }
	}
	return array_slice( $out, 0, 1 );
}
function wp_insert_post( $arr, $wp_error = false ) {
	$arr = wp_unslash( $arr );
	$id  = $arr['ID'] ?? DB::$next++;
	DB::$posts[ $id ] = array_merge( DB::$posts[ $id ] ?? array( 'post_parent' => 0, 'post_name' => '' ), $arr, array( 'ID' => $id ) );
	foreach ( $arr['meta_input'] ?? array() as $k => $v ) { DB::$meta[ $id ][ $k ] = $v; }
	return $id;
}
function wp_update_post( $arr, $wp_error = false ) { return wp_insert_post( $arr, $wp_error ); }
function wp_tempnam( $n = '' ) { return tempnam( sys_get_temp_dir(), 'hmi' ); }
function media_handle_sideload( $file, $parent = 0, $desc = null, $data = array() ) {
	$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $parent, 'post_title' => $data['post_title'] ?? '', 'guid' => 'https://example.test/wp-content/uploads/' . $file['name'] ) );
	unlink( $file['tmp_name'] );
	return $id;
}
function wp_get_attachment_url( $id ) { return DB::$posts[ $id ]['guid'] ?? false; }
function wp_attachment_is_image( $id ) { return true; }
function set_post_thumbnail( $p, $a ) { DB::$meta[ $p ]['_thumbnail_id'] = $a; }
function term_exists( $name, $tax = '', $parent = 0 ) {
	foreach ( DB::$terms as $id => $t ) {
		if ( is_int( $name ) ? $id === $name : ( $t['name'] === $name && $t['parent'] === (int) $parent ) ) { return array( 'term_id' => $id ); }
	}
	return null;
}
function wp_insert_term( $name, $tax, $args = array() ) { $id = 1000 + count( DB::$terms ); DB::$terms[ $id ] = array( 'name' => $name, 'parent' => (int) ( $args['parent'] ?? 0 ) ); return array( 'term_id' => $id ); }
function wp_set_post_categories( $id, $cats ) { DB::$meta[ $id ]['_cats'] = $cats; }
function wp_set_post_tags( $id, $tags ) { DB::$meta[ $id ]['_tags'] = $tags; }

// ----------------------------------------------------------------------------- plugin
$base = dirname( __DIR__ ) . '/html-md-importer';
require $base . '/lib/parsedown/Parsedown.php';
foreach ( glob( $base . '/includes/class-*.php' ) as $file ) {
	if ( ! str_contains( $file, 'class-admin' ) && ! str_contains( $file, 'class-rest' ) ) {
		require $file;
	}
}
use HtmlMdImporter\{Session, Scanner, Importer, Paths};

$fail = function ( string $msg ) { fwrite( STDERR, "FAIL: $msg\n" ); exit( 1 ); };
$src  = $argv[1] ?? $fail( 'usage' );
$type = $argv[2] ?? 'page';

$settings = Importer::sanitize_settings( array( 'post_type' => $type, 'status' => 'draft', 'featured_image' => true ) );

/** One complete import like the admin screen runs it: upload in chunks → scan → media → items → finalize. */
$full_run = function () use ( $src, $settings, $fail ) {
	$session = Session::create();
	is_wp_error( $session ) && $fail( $session->message );

	$chunk_upload = function ( string $data, string $relpath ) use ( $session, $fail ) {
		$size = 65536;
		for ( $offset = 0; $offset < max( 1, strlen( $data ) ); $offset += $size ) {
			$t = tempnam( sys_get_temp_dir(), 'chk' );
			file_put_contents( $t, substr( $data, $offset, $size ) );
			$r = $session->store_chunk( $relpath, $t, $offset );
			// Simulate a retried chunk: must not be appended twice.
			if ( 0 === $offset % ( 3 * $size ) ) { $r = $session->store_chunk( $relpath, $t, $offset ); }
			unlink( $t );
			is_wp_error( $r ) && $fail( $r->message );
		}
	};
	if ( is_file( $src ) ) {
		$chunk_upload( file_get_contents( $src ), '__upload.zip' );
		$res = $session->extract_zip();
		is_wp_error( $res ) && $fail( $res->message );
		echo "ZIP: extracted {$res['extracted']}, skipped {$res['skipped']}\n";
	} else {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$rel = Paths::sanitize_relpath( basename( $src ) . '/' . substr( strtr( $f->getPathname(), chr( 92 ), '/' ), strlen( rtrim( $src, '/' ) ) + 1 ) );
			if ( $rel ) { $chunk_upload( file_get_contents( $f->getPathname() ), $rel ); }
		}
	}

	$plan = ( new Scanner( $session->files_dir() ) )->scan();
	$session->update_manifest( function ( $m ) use ( $plan ) { $m['plan'] = $plan; return $m; } );

	foreach ( array_keys( $plan['media'] ) as $rel ) {
		$r = Importer::import_media( $session, $rel );
		is_wp_error( $r ) && $fail( $r->message );
	}
	foreach ( $plan['items'] as $item ) {
		$r = Importer::import_item( $session, $item['key'], $settings );
		is_wp_error( $r ) && $fail( $r->message );
		if ( $r['warnings'] ) { echo "  warnings for {$item['title']}: " . implode( ' | ', $r['warnings'] ) . "\n"; }
	}
	$fin = Importer::finalize( $session );
	echo 'Finalize: ' . json_encode( $fin ) . "\n";
	is_dir( $session->files_dir() ) && $fail( 'uploaded files not deleted after finalize' );
};

$full_run();
$count_first = count( DB::$posts );

$print = function ( int $parent, int $depth ) use ( &$print ) {
	foreach ( DB::$posts as $id => $p ) {
		if ( 'attachment' !== $p['post_type'] && (int) $p['post_parent'] === $parent ) {
			printf( "%s#%d %s [%s, slug=%s, order=%d, %d bytes, thumb=%s, cats=%s]\n", str_repeat( '  ', $depth ), $id, $p['post_title'], $p['post_type'], $p['post_name'] ?? '', $p['menu_order'] ?? 0, strlen( $p['post_content'] ), DB::$meta[ $id ]['_thumbnail_id'] ?? '-', implode( ',', DB::$meta[ $id ]['_cats'] ?? array() ) );
			$print( $id, $depth + 1 );
		}
	}
};
$print( 0, 0 );
echo 'Attachments: ' . count( array_filter( DB::$posts, fn( $p ) => 'attachment' === $p['post_type'] ) ) . ', attached: ' . count( array_filter( DB::$posts, fn( $p ) => 'attachment' === $p['post_type'] && $p['post_parent'] ) ) . "\n";
if ( DB::$terms ) { echo 'Terms: ' . json_encode( DB::$terms, JSON_UNESCAPED_UNICODE ) . "\n"; }

$leftover = array_filter( DB::$posts, fn( $p ) => str_contains( (string) ( $p['post_content'] ?? '' ), '#hmi-link-' ) || str_contains( (string) ( $p['post_content'] ?? '' ), 'data-hmi' ) );
$leftover && $fail( 'placeholders or internal attributes left in content' );

echo "\n--- Re-import ---\n";
$full_run();
count( DB::$posts ) !== $count_first && $fail( 'Re-import created new posts/attachments: ' . $count_first . ' → ' . count( DB::$posts ) );
echo "Re-import updated in place ($count_first posts incl. attachments)\n";

$first = array_values( array_filter( DB::$posts, fn( $p ) => 'attachment' !== $p['post_type'] ) )[0];
echo "\n--- Content of first item (first 1500 chars) ---\n" . substr( $first['post_content'], 0, 1500 ) . "\n";
echo "\nOK\n";
