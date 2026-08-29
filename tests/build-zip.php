<?php
// Builds a WordPress-compliant plugin zip (forward-slash entry paths — PS 5.1
// Compress-Archive writes backslashes, which breaks WP's extractor).
$root = dirname( __DIR__ );
$src  = $root . '/scoreline-feeds';
// Version-stamped from the plugin header so the artifact name never goes stale.
preg_match( '/Version:\s+([\d.]+)/', file_get_contents( $src . '/scoreline-feeds.php' ), $vm );
$out = $root . '/scoreline-feeds-' . ( $vm[1] ?? 'dev' ) . '.zip';

// First, show what the OLD zip contained (diagnosis).
if ( file_exists( $out ) ) {
    $old = new ZipArchive();
    if ( $old->open( $out ) === true ) {
        echo "Old zip entries (first 3):\n";
        for ( $i = 0; $i < min( 3, $old->numFiles ); $i++ ) {
            echo '  [' . $old->getNameIndex( $i ) . "]\n";
        }
        $old->close();
    }
}

@unlink( $out );
$zip = new ZipArchive();
if ( $zip->open( $out, ZipArchive::CREATE ) !== true ) {
    fwrite( STDERR, "cannot create zip\n" );
    exit( 1 );
}
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
$count = 0;
foreach ( $it as $file ) {
    if ( ! $file->isFile() ) continue;
    $rel = 'scoreline-feeds/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $src ) + 1 ) );
    $zip->addFile( $file->getPathname(), $rel );
    $count++;
}
$zip->close();

// Verify the new zip.
$check = new ZipArchive();
$check->open( $out );
echo "\nNew zip: {$check->numFiles} entries ($count files added)\n";
$bad = 0;
for ( $i = 0; $i < $check->numFiles; $i++ ) {
    $name = $check->getNameIndex( $i );
    if ( strpos( $name, '\\' ) !== false ) { $bad++; }
    if ( $i < 3 ) echo '  [' . $name . "]\n";
}
$has_main = $check->locateName( 'scoreline-feeds/scoreline-feeds.php' ) !== false;
$check->close();
echo $bad === 0 ? "All entry paths use forward slashes.\n" : "ERROR: $bad entries contain backslashes!\n";
echo $has_main ? "Main plugin file present at scoreline-feeds/scoreline-feeds.php.\n" : "ERROR: main plugin file missing!\n";
exit( ( $bad === 0 && $has_main ) ? 0 : 1 );
