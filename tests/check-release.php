<?php
/**
 * Release metadata consistency — run by CI and before every release.
 *
 * A mismatch here is a silent fleet-wide failure: a header/constant skew
 * makes WordPress offer the same update forever, and a manifest whose
 * package does not match its version is rejected by every site's updater.
 *
 *   php tests/check-release.php            check the repository
 *   php tests/check-release.php --tagged   also require the manifest to
 *                                          announce the plugin's version
 */

$root   = dirname( __DIR__ );
$main   = file_get_contents( $root . '/scoreline-feeds/scoreline-feeds.php' );
$errors = [];

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $h );
preg_match( "/define\(\s*'MMGRF_VERSION',\s*'([^']+)'\s*\)/", $main, $c );
$header   = $h[1] ?? '';
$constant = $c[1] ?? '';

if ( $header === '' || $constant === '' ) {
    $errors[] = 'could not read the plugin version from the header and/or MMGRF_VERSION';
} elseif ( $header !== $constant ) {
    $errors[] = "plugin header says $header but MMGRF_VERSION is $constant";
}
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $constant ) ) {
    $errors[] = "version \"$constant\" is not MAJOR.MINOR.PATCH";
}
if ( strpos( $main, 'Update URI:  https://github.com/mainlinemedia/scoreline-feeds' ) === false ) {
    $errors[] = 'the Update URI header is missing or changed — sites would stop receiving updates';
}

$manifest = json_decode( (string) file_get_contents( $root . '/manifest.json' ), true );
if ( ! is_array( $manifest ) || empty( $manifest['version'] ) || empty( $manifest['package'] ) ) {
    $errors[] = 'manifest.json is not valid JSON with "version" and "package"';
} else {
    $v        = $manifest['version'];
    $expected = "https://github.com/mainlinemedia/scoreline-feeds/releases/download/v$v/scoreline-feeds-$v.zip";
    if ( $manifest['package'] !== $expected ) {
        $errors[] = "manifest package does not match its version (expected $expected)";
    }
    if ( version_compare( $v, $constant, '>' ) ) {
        $errors[] = "manifest announces $v, which is newer than the code ($constant)";
    }
    if ( in_array( '--tagged', $argv, true ) && $v !== $constant ) {
        $errors[] = "manifest announces $v but the code is $constant";
    }
}

if ( ! preg_match( '/^## \[?' . preg_quote( $constant, '/' ) . '\]?/m', (string) @file_get_contents( $root . '/CHANGELOG.md' ) ) ) {
    $errors[] = "CHANGELOG.md has no entry for $constant";
}

foreach ( $errors as $e ) {
    fwrite( STDERR, "release check: $e\n" );
}
echo $errors ? '' : "release metadata OK ($constant; manifest announces " . ( $manifest['version'] ?? '?' ) . ")\n";
exit( $errors ? 1 : 0 );
