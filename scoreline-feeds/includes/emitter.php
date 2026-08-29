<?php
/**
 * Layer 3 — XML emission helpers.
 *
 * Values inside CDATA are emitted RAW (never esc_html'd — that was defect D1 in
 * v2.1.0: entities like &amp; rendered literally on partner sites). An embedded
 * "]]>" is split across two CDATA sections, which is lossless for the parser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function mmgrf_cdata( $value ) {
    return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $value ) . ']]>';
}

function mmgrf_xml( $value ) {
    return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
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
