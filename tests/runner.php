<?php
/** Micro test runner: register tests with t(), assert with expect_*(), summary + exit code. */

$GLOBALS['mmgrf_runner'] = [ 'tests' => [], 'pass' => 0, 'fail' => 0, 'failures' => [] ];

function t( $name, callable $fn ) {
    $GLOBALS['mmgrf_runner']['tests'][] = [ $name, $fn ];
}

class MMGRF_AssertFail extends Exception {}

function expect_eq( $actual, $expected, $label = '' ) {
    if ( $actual !== $expected ) {
        throw new MMGRF_AssertFail(
            ( $label ? "$label: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
        );
    }
}
function expect_true( $cond, $label = '' ) {
    if ( ! $cond ) throw new MMGRF_AssertFail( ( $label ?: 'condition' ) . ' expected true' );
}
function expect_false( $cond, $label = '' ) {
    if ( $cond ) throw new MMGRF_AssertFail( ( $label ?: 'condition' ) . ' expected false' );
}
function expect_contains( $haystack, $needle, $label = '' ) {
    if ( strpos( (string) $haystack, (string) $needle ) === false ) {
        throw new MMGRF_AssertFail( ( $label ? "$label: " : '' ) . 'expected to contain ' . var_export( $needle, true ) . "\n--- in ---\n" . mb_substr( (string) $haystack, 0, 2000 ) );
    }
}
function expect_not_contains( $haystack, $needle, $label = '' ) {
    if ( strpos( (string) $haystack, (string) $needle ) !== false ) {
        throw new MMGRF_AssertFail( ( $label ? "$label: " : '' ) . 'expected NOT to contain ' . var_export( $needle, true ) . "\n--- in ---\n" . mb_substr( (string) $haystack, 0, 2000 ) );
    }
}
function expect_match( $haystack, $pattern, $label = '' ) {
    if ( ! preg_match( $pattern, (string) $haystack ) ) {
        throw new MMGRF_AssertFail( ( $label ? "$label: " : '' ) . 'expected to match ' . $pattern . "\n--- in ---\n" . mb_substr( (string) $haystack, 0, 2000 ) );
    }
}

/**
 * Strict well-formedness: zero libxml errors. (simplexml_load_string() !== false
 * was the old oracle — it happily returns an object for a document with
 * undeclared namespace prefixes.)
 */
function expect_well_formed( $xml, $label = '' ) {
    $prev = libxml_use_internal_errors( true );
    libxml_clear_errors();
    $doc = new DOMDocument();
    $doc->loadXML( (string) $xml );
    $errors = array_map( fn( $e ) => trim( $e->message ) . ' (line ' . $e->line . ')', libxml_get_errors() );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );
    if ( $errors ) {
        throw new MMGRF_AssertFail( ( $label ? "$label: " : '' ) . 'XML is not well-formed: ' . implode( '; ', array_slice( $errors, 0, 3 ) ) );
    }
}

/** Every <img src> and media url inside one <item> block, in order. */
function item_image_urls( $item_xml ) {
    preg_match_all( '/<img\b[^>]*?\ssrc="([^"]+)"|<media:(?:content|thumbnail)\b[^>]*?\surl="([^"]+)"/i', (string) $item_xml, $m, PREG_SET_ORDER );
    return array_map( fn( $x ) => html_entity_decode( $x[1] !== '' ? $x[1] : $x[2] ), $m );
}

function run_all_tests() {
    $r = &$GLOBALS['mmgrf_runner'];
    // A warning, notice or deprecation anywhere in the plugin fails the
    // test that triggered it (that is how a PHP-version regression shows up
    // in CI). @-suppressed calls are respected.
    set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
        if ( ! ( error_reporting() & $errno ) ) {
            return false;
        }
        throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
    } );
    // MMGRF_TEST_ORDER=reverse|shuffle[:seed] — every test must pass in any
    // order (shared state once made the suite green only alphabetically).
    $order = (string) getenv( 'MMGRF_TEST_ORDER' );
    if ( $order === 'reverse' ) {
        $r['tests'] = array_reverse( $r['tests'] );
    } elseif ( strpos( $order, 'shuffle' ) === 0 ) {
        $seed = (int) ( explode( ':', $order )[1] ?? random_int( 1, 999999 ) );
        mt_srand( $seed );
        shuffle( $r['tests'] );
        fwrite( STDOUT, "Shuffled test order, seed $seed (re-run with MMGRF_TEST_ORDER=shuffle:$seed)\n" );
    }
    foreach ( $r['tests'] as [ $name, $fn ] ) {
        mmgrf_test_reset();
        try {
            $fn();
            $r['pass']++;
            fwrite( STDOUT, "PASS  $name\n" );
        } catch ( MMGRF_AssertFail $e ) {
            $r['fail']++;
            $r['failures'][] = [ $name, $e->getMessage() ];
            fwrite( STDOUT, "FAIL  $name\n      " . str_replace( "\n", "\n      ", $e->getMessage() ) . "\n" );
        } catch ( Throwable $e ) {
            $r['fail']++;
            $r['failures'][] = [ $name, get_class( $e ) . ': ' . $e->getMessage() ];
            fwrite( STDOUT, "ERROR $name\n      " . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" );
        }
    }
    fwrite( STDOUT, "\n" . $r['pass'] . ' passed, ' . $r['fail'] . " failed\n" );
    exit( $r['fail'] > 0 ? 1 : 0 );
}
