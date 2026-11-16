<?php
/**
 * Offline test of the WordPress-independent pipeline (scanner, cleaner, block converter).
 *
 * Usage: php tests/offline.php <folder-or-zip> [item-key-substring]
 * Copies the sample into a temp "session" (adding the .hmi suffix like the plugin does),
 * prints the import plan and the block markup of one item.
 */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
$base = dirname( __DIR__ ) . '/html-md-importer';
require $base . '/lib/parsedown/Parsedown.php';
foreach ( array( 'paths', 'front-matter', 'document', 'cleaner', 'block-converter', 'scanner' ) as $f ) {
	require "$base/includes/class-$f.php";
}
use HtmlMdImporter\{Paths, Scanner, Document, Cleaner, BlockConverter};

$src = $argv[1] ?? '';
$tmp = sys_get_temp_dir() . '/hmi-offline-' . getmypid();
mkdir( $tmp, 0777, true );

$add = function ( string $rel, string $data ) use ( $tmp ) {
	$rel = Paths::sanitize_relpath( $rel );
	if ( null === $rel ) { return; }
	@mkdir( dirname( "$tmp/$rel" ), 0777, true );
	file_put_contents( "$tmp/$rel" . Paths::STORE_SUFFIX, $data );
};
if ( is_file( $src ) ) {
	$zip = new ZipArchive(); $zip->open( $src );
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( ! str_ends_with( $name, '/' ) ) { $add( $name, (string) $zip->getFromIndex( $i ) ); }
	}
} else {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) { $add( substr( strtr( $f->getPathname(), chr( 92 ), '/' ), strlen( rtrim( $src, '/' ) ) + 1 ), file_get_contents( $f->getPathname() ) ); }
}

$plan = ( new Scanner( $tmp ) )->scan();
foreach ( $plan['items'] as $item ) {
	printf( "%s%-45s [%s] order=%d src=%s media=%d missing=%d links=%d dup=%s\n",
		str_repeat( '  ', $item['depth'] ), $item['title'], $item['kind'], $item['order'], $item['source'] ?? '-',
		count( $item['media'] ), count( $item['missing'] ), $item['links'], implode( ',', $item['duplicates'] ) );
}
printf( "media total: %d\n", count( $plan['media'] ) );

$want = $argv[2] ?? null;
if ( $want ) {
	foreach ( $plan['items'] as $item ) {
		if ( $item['source'] && str_contains( $item['key'], $want ) ) {
			$doc = Document::load( "$tmp/{$item['source']}" . Paths::STORE_SUFFIX, $item['source'] );
			( new Cleaner() )->clean( $doc );
			$i = 100;
			foreach ( $doc->body->getElementsByTagName( 'img' ) as $img ) { $img->setAttribute( 'data-hmi-id', (string) $i++ ); }
			echo "TITLE: ", Cleaner::extract_leading_h1( $doc ), "\n";
			$conv = new BlockConverter();
			$out  = $conv->convert( $doc );
			file_put_contents( $tmp . '.blocks.html', $out );
			echo "BLOCKS written: $tmp.blocks.html (" . strlen( $out ) . " bytes)\nwarnings: " . implode( '; ', array_unique( $conv->warnings ) ) . "\n";
			preg_match_all( '/<!-- wp:([a-z\-]+)/', $out, $m );
			print_r( array_count_values( $m[1] ) );
			break;
		}
	}
}
