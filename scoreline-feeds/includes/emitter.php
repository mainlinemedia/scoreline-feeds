<?php
/**
 * Layer 3 — XML emission helpers.
 *
 * Values inside CDATA are emitted RAW (never esc_html'd — that was defect D1 in
 * v2.1.0: entities like &amp; rendered literally on partner sites). An embedded
 * "]]>" is split across two CDATA sections, which is lossless for the parser.
 *
 * Every emitted value passes through mmgrf_xml_chars(): one vertical tab
 * pasted from a PDF, or one invalid UTF-8 byte from a wire import, makes the
 * WHOLE document ill-formed — the partner then loses every item in the feed
 * until that post ages out of the window, not just the bad one.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Valid UTF-8, with any invalid byte sequences replaced. */
function mmgrf_utf8( $value ) {
    $value = (string) $value;
    if ( $value === '' || preg_match( '//u', $value ) ) {
        return $value; // already valid — the overwhelmingly common case
    }
    if ( function_exists( 'mb_scrub' ) ) {
        return mb_scrub( $value, 'UTF-8' );
    }
    $clean = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'UTF-8//IGNORE', $value ) : false;
    return is_string( $clean ) ? $clean : '';
}

/** Strip everything XML 1.0 forbids: invalid UTF-8, C0 controls, surrogates, U+FFFE/FFFF. */
function mmgrf_xml_chars( $value ) {
    $value = (string) $value;
    if ( ! preg_match( '/[^\x09\x0A\x0D\x20-\x7E]/', $value ) ) {
        return $value; // printable ASCII only — nothing to check
    }
    $value = mmgrf_utf8( $value );
    $clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
    return is_string( $clean ) ? $clean : '';
}

function mmgrf_cdata( $value ) {
    return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', mmgrf_xml_chars( $value ) ) . ']]>';
}

function mmgrf_xml( $value ) {
    return htmlspecialchars( mmgrf_xml_chars( $value ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function mmgrf_rfc822( $timestamp ) {
    return gmdate( 'D, d M Y H:i:s', (int) $timestamp ) . ' +0000';
}

/**
 * Render one element. $value null => self-closing. $cdata wraps the raw value.
 */
function mmgrf_el( $name, $value = null, $attrs = [], $cdata = false ) {
    $out = '<' . $name;
    foreach ( $attrs as $k => $v ) {
        $out .= ' ' . $k . '="' . mmgrf_xml( $v ) . '"';
    }
    if ( $value === null ) {
        return $out . '/>';
    }
    $out .= '>';
    $out .= $cdata ? mmgrf_cdata( $value ) : mmgrf_xml( $value );
    return $out . '</' . $name . '>';
}
