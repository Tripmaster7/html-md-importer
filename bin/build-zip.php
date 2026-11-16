<?php
/**
 * Builds build/html-md-importer.zip, ready for Plugins → Add New → Upload Plugin.
 *
 * Usage: php bin/build-zip.php
 */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$source  = $root . '/html-md-importer';
$target  = $root . '/build/html-md-importer.zip';
$exclude = array( '.DS_Store', 'Thumbs.db' );

if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0777, true );
}
if ( is_file( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot create $target\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
$count    = 0;
foreach ( $iterator as $file ) {
	if ( in_array( $file->getFilename(), $exclude, true ) ) {
		continue;
	}
	$relative = 'html-md-importer/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $source ) + 1 ) );
	$zip->addFile( $file->getPathname(), $relative );
	++$count;
}
$zip->close();
printf( "%s (%d files, %.1f KB)\n", $target, $count, filesize( $target ) / 1024 );
