<?php
// Emitter: CDATA-safe output (defect D1), XML text escaping, RFC 822 GMT dates.

t( 'cdata wraps raw value without entity escaping', function() {
    expect_eq( mmgrf_cdata( "Bill & Ted's Excellent Adventure" ), "<![CDATA[Bill & Ted's Excellent Adventure]]>" );
} );

t( 'cdata splits embedded ]]> so the document stays well-formed', function() {
    $out = mmgrf_cdata( 'evil ]]> payload' );
    // round-trip through a real XML parser proves well-formedness AND losslessness
    $xml = simplexml_load_string( "<x>$out</x>" );
    expect_true( $xml !== false, 'document must stay well-formed' );
    expect_eq( (string) $xml, 'evil ]]> payload', 'round-trip' );
} );

t( 'xml text escaping handles & < > quotes', function() {
    expect_eq( mmgrf_xml( "a & b < c > d \"q\" 'a'" ), 'a &amp; b &lt; c &gt; d &quot;q&quot; &#039;a&#039;' );
} );

t( 'rfc822 renders GMT with +0000 suffix', function() {
    $ts = strtotime( '2026-02-15 17:58:16 UTC' );
    expect_eq( mmgrf_rfc822( $ts ), 'Sun, 15 Feb 2026 17:58:16 +0000' );
} );

t( 'element helper renders plain, cdata, and attribute forms', function() {
    expect_eq( mmgrf_el( 'title', 'A & B' ), '<title>A &amp; B</title>' );
    expect_eq( mmgrf_el( 'title', "A & B's", [], true ), "<title><![CDATA[A & B's]]></title>" );
    expect_eq(
        mmgrf_el( 'media:content', null, [ 'url' => 'https://x.com/a.jpg?a=1&b=2', 'width' => 1280 ] ),
        '<media:content url="https://x.com/a.jpg?a=1&amp;b=2" width="1280"/>'
    );
} );
