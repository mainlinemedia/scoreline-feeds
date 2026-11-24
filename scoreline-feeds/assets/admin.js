/**
 * Scoreline Feeds admin screens — tabs, copy buttons, log filters.
 * Progressive enhancement only: without this script every section is shown
 * on one page and every control still works.
 */
( function() {
	'use strict';

	var wrap = document.querySelector( '.mmgrf-wrap' );
	if ( ! wrap ) {
		return;
	}

	// ── Tabs ──────────────────────────────────────────────────────
	var tabs   = wrap.querySelectorAll( '.mmgrf-tabs .nav-tab' );
	var panels = wrap.querySelectorAll( '.mmgrf-panel' );
	var save   = wrap.querySelector( '.mmgrf-save' );

	function show( name, updateHash ) {
		var found = false;
		Array.prototype.forEach.call( panels, function( panel ) {
			var on = panel.getAttribute( 'data-panel' ) === name;
			panel.classList.toggle( 'is-current', on );
			found = found || on;
		} );
		if ( ! found ) {
			return show( 'networks', false );
		}
		Array.prototype.forEach.call( tabs, function( tab ) {
			var on = tab.getAttribute( 'data-tab' ) === name;
			tab.classList.toggle( 'nav-tab-active', on );
			if ( on ) {
				tab.setAttribute( 'aria-current', 'page' );
			} else {
				tab.removeAttribute( 'aria-current' );
			}
		} );
		if ( save ) {
			var covers = ( save.getAttribute( 'data-save-for' ) || '' ).split( ' ' );
			save.classList.toggle( 'is-hidden', covers.indexOf( name ) === -1 );
		}
		if ( updateHash && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + name );
		}
		// After saving, WordPress returns to the referring URL: carry the
		// tab along so the operator lands where they were.
		var referer = wrap.querySelector( 'input[name="_wp_http_referer"]' );
		if ( referer ) {
			referer.value = referer.value.replace( /#.*$/, '' ) + '#' + name;
		}
	}

	if ( tabs.length && panels.length ) {
		document.documentElement.classList.add( 'mmgrf-js' );
		Array.prototype.forEach.call( tabs, function( tab ) {
			tab.addEventListener( 'click', function( event ) {
				event.preventDefault();
				show( tab.getAttribute( 'data-tab' ), true );
			} );
		} );
		show( ( window.location.hash || '#networks' ).slice( 1 ), false );
		window.addEventListener( 'hashchange', function() {
			show( ( window.location.hash || '#networks' ).slice( 1 ), false );
		} );
	}

	// ── Copy a feed URL ───────────────────────────────────────────
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			}
			document.body.removeChild( area );
		} );
	}

	wrap.addEventListener( 'click', function( event ) {
		var button = event.target.closest ? event.target.closest( '.mmgrf-copy' ) : null;
		if ( button ) {
			copyText( button.getAttribute( 'data-copy' ) || '' ).then( function() {
				var label = button.textContent;
				button.textContent = 'Copied';
				button.classList.add( 'is-copied' );
				setTimeout( function() {
					button.textContent = label;
					button.classList.remove( 'is-copied' );
				}, 1600 );
			} ).catch( function() {} );
			return;
		}
		var confirmLink = event.target.closest ? event.target.closest( '[data-confirm]' ) : null;
		if ( confirmLink && ! window.confirm( confirmLink.getAttribute( 'data-confirm' ) ) ) {
			event.preventDefault();
			return;
		}
		var toLog = event.target.closest ? event.target.closest( '.mmgrf-goto-log' ) : null;
		if ( toLog ) {
			event.preventDefault();
			var select = document.getElementById( 'mmgrf-log-network' );
			var level  = document.getElementById( 'mmgrf-log-level' );
			if ( select ) {
				select.value = toLog.getAttribute( 'data-network' ) || '';
			}
			if ( level ) {
				level.value = 'skip';
			}
			show( 'log', true );
			filterLog();
		}
	} );

	// ── Log filters ───────────────────────────────────────────────
	var logNetwork = document.getElementById( 'mmgrf-log-network' );
	var logLevel   = document.getElementById( 'mmgrf-log-level' );
	var logSearch  = document.getElementById( 'mmgrf-log-search' );
	var logCount   = wrap.querySelector( '.mmgrf-log-count' );

	function filterLog() {
		var rows = wrap.querySelectorAll( '.mmgrf-log tbody tr' );
		if ( ! rows.length ) {
			return;
		}
		var network = logNetwork ? logNetwork.value : '';
		var level   = logLevel ? logLevel.value : '';
		var query   = logSearch ? logSearch.value.trim().toLowerCase() : '';
		var shown   = 0;
		Array.prototype.forEach.call( rows, function( row ) {
			var rowNet = row.getAttribute( 'data-network' ) || '';
			var match  = ( ! network || rowNet === network || rowNet.indexOf( network + '-' ) === 0 ) &&
				( ! level || row.getAttribute( 'data-level' ) === level ) &&
				( ! query || row.textContent.toLowerCase().indexOf( query ) !== -1 );
			row.classList.toggle( 'is-filtered', ! match );
			shown += match ? 1 : 0;
		} );
		if ( logCount ) {
			logCount.textContent = shown === rows.length ? rows.length + ' entries' : shown + ' of ' + rows.length + ' entries';
		}
	}

	[ logNetwork, logLevel ].forEach( function( control ) {
		if ( control ) {
			control.addEventListener( 'change', filterLog );
		}
	} );
	if ( logSearch ) {
		logSearch.addEventListener( 'input', filterLog );
	}
	filterLog();
} )();
