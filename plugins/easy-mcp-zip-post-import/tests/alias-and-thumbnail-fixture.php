<?php
/**
 * Fixture test (no WordPress): JSON aliases + thumbnail locate.
 * Run: php tests/alias-and-thumbnail-fixture.php
 */
declare(strict_types=1);

$root = dirname( __DIR__ );
define( 'EMPZI_PLUGIN_DIR', $root . '/' );
require_once $root . '/src/Infrastructure/Autoloader.php';
\EasyMCP\ZipPostImport\Autoloader::register();

use EasyMCP\ZipPostImport\Application\UseCase\ManifestAliasNormalizer;
use EasyMCP\ZipPostImport\Infrastructure\Zip\ThumbnailLocator;

$failed = 0;
$pass   = static function ( string $name ) {
	echo "OK  {$name}\n";
};
$fail = static function ( string $name, string $why ) use ( &$failed ) {
	$failed++;
	echo "FAIL {$name}: {$why}\n";
};

$aliases = new ManifestAliasNormalizer();
$fixture = json_decode( (string) file_get_contents( $root . '/fixtures/user-shape-post.json' ), true );
if ( ! is_array( $fixture ) ) {
	fwrite( STDERR, "Cannot read fixtures/user-shape-post.json\n" );
	exit( 1 );
}

if ( $aliases->images_folder( $fixture ) === 'images' ) {
	$pass( 'images alias → images_folder' );
} else {
	$fail( 'images alias', $aliases->images_folder( $fixture ) );
}

$cats = $aliases->category_names( $fixture );
if ( $cats === [ 'Nachrichten' ] ) {
	$pass( 'category string → category_names' );
} else {
	$fail( 'category alias', json_encode( $cats ) ?: '' );
}

if ( $aliases->thumbnail( $fixture ) === 'thumbnail.jpg' ) {
	$pass( 'thumbnail field' );
} else {
	$fail( 'thumbnail field', $aliases->thumbnail( $fixture ) );
}

$cats2 = $aliases->category_names( [ 'categories' => [ 'A', 'B' ], 'category_names' => [ 'A' ] ] );
if ( $cats2 === [ 'A', 'B' ] ) {
	$pass( 'categories + category_names merge' );
} else {
	$fail( 'categories merge', json_encode( $cats2 ) ?: '' );
}

$tmp = sys_get_temp_dir() . '/empzi-fixture-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp . '/slug/images', 0777, true );
file_put_contents( $tmp . '/slug/images/thumbnail.jpg', 'fake-jpeg' );

$locator = new ThumbnailLocator();
$found   = $locator->locate( $tmp . '/slug', $tmp . '/slug/images', 'thumbnail.jpg', $tmp );
if ( is_string( $found ) && basename( $found ) === 'thumbnail.jpg' ) {
	$pass( 'locator finds nested thumbnail.jpg from JSON' );
} else {
	$fail( 'locator nested', (string) $found );
}

$none_dir = $tmp . '/empty';
mkdir( $none_dir );
$skipped = $locator->locate( $none_dir, $none_dir, '', $none_dir );
if ( $skipped === null ) {
	$pass( 'no thumbnail in JSON or ZIP → skip (not throw)' );
} else {
	$fail( 'optional skip', (string) $skipped );
}

$promised_missing = $locator->locate( $none_dir, $none_dir, 'thumbnail.jpg', $none_dir );
if ( $promised_missing === null ) {
	$pass( 'JSON thumbnail named but file absent → skip featured image' );
} else {
	$fail( 'promised missing skip', (string) $promised_missing );
}

$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::CHILD_FIRST
);
foreach ( $it as $file ) {
	$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
}
@rmdir( $tmp );

exit( $failed === 0 ? 0 : 1 );
