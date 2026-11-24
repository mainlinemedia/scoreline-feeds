/**
 * Syndication readiness panel (Fix 11). No build step — wp globals only.
 * Warns, never blocks.
 *
 * The panel's verdict always comes from the server (the REST check runs the
 * same code as the feed, on saved content) and is refreshed after every
 * save. A featured-image swap adds a small note of its own beside that
 * verdict — it never replaces it: an instant "looks fine" on the image must
 * not erase a warning about an affiliate link or an undersized inline photo.
 */
( function() {
	if ( ! window.wp || ! wp.data || ! wp.apiFetch ) {
		return;
	}
	var panel = document.getElementById( 'mmgrf-syn-panel' );
	if ( ! panel ) {
		return;
	}
	var select = wp.data.select( 'core/editor' );
	if ( ! select ) {
		return; // classic editor: server-side render is the whole feature
	}

	var NOTICE_ID = 'mmgrf-syndication-image';
	var NOTE_ID   = 'mmgrf-syn-image-note';
	var ready        = false; // false until the editor store knows the post
	var lastMedia    = null;
	var wasPublished = false;
	var wasSaving    = false;
	var mediaTimer   = null;
	var checkTimer   = null;
	var requestSeq   = 0;

	function line( className, text ) {
		var p = document.createElement( 'p' );
		p.className = className;
		p.textContent = text; // never innerHTML: messages and numbers are data
		return p;
	}

	function clearNote() {
		var note = document.getElementById( NOTE_ID );
		if ( note && note.parentNode ) {
			note.parentNode.removeChild( note );
		}
	}

	function setNote( className, text ) {
		clearNote();
		var note = line( className, text );
		note.id = NOTE_ID;
		panel.insertBefore( note, panel.firstChild );
	}

	function render( result ) {
		if ( ! result || ! result.enabled ) {
			return;
		}
		panel.className = '';
		panel.textContent = '';
		if ( ! result.issues || result.issues.length === 0 ) {
			panel.appendChild( line( 'mmgrf-syn-ok', '✓ Ready for syndication' ) );
			return;
		}
		result.issues.forEach( function( issue ) {
			panel.appendChild( line( 'mmgrf-syn-warn', String( issue.message || '' ) ) );
		} );
	}

	function fullCheck( thenMaybeNotice ) {
		var postId = select.getCurrentPostId();
		if ( ! postId ) {
			return;
		}
		var seq = ++requestSeq;
		wp.apiFetch( { path: '/mmgrf/v1/syndication-check?post_id=' + encodeURIComponent( postId ) } ).then( function( result ) {
			if ( seq !== requestSeq ) {
				return; // a newer check is already on its way
			}
			render( result );
			if ( thenMaybeNotice ) {
				maybePublishNotice( result );
			}
		} ).catch( function() { /* panel keeps its last state */ } );
	}

	// Pre-publish notice: the lead-image check only (the missing/undersized
	// lead image costs the most distribution; three notices on one save is noise).
	function maybePublishNotice( result ) {
		var imageIssue = ( result.issues || [] ).filter( function( i ) {
			return i.code === 'no_featured_image' || i.code === 'image_below_minimum' || i.code === 'image_dimensions_unknown' || i.code === 'image_file_missing';
		} )[ 0 ];
		var notices = wp.data.dispatch( 'core/notices' );
		if ( imageIssue && notices ) {
			notices.createWarningNotice( String( imageIssue.message || '' ), {
				id: NOTICE_ID, // stable id: repeated saves replace, never stack
				isDismissible: true,
			} );
		}
	}

	// A note about the image just chosen. Only ever ADDS a line.
	function imageNote( mediaId ) {
		if ( ! mediaId ) {
			setNote( 'mmgrf-syn-warn', 'Featured image removed. Save to re-check syndication readiness.' );
			return;
		}
		wp.apiFetch( { path: '/wp/v2/media/' + encodeURIComponent( mediaId ) + '?_fields=media_details' } ).then( function( media ) {
			if ( select.getEditedPostAttribute( 'featured_media' ) !== mediaId ) {
				return; // changed again while this was loading
			}
			var d = ( media && media.media_details ) || {};
			var w = parseInt( d.width, 10 ) || 0;
			var h = parseInt( d.height, 10 ) || 0;
			if ( w && h && ( w < 1280 || h < 720 ) ) {
				setNote( 'mmgrf-syn-warn', 'New featured image is ' + w + '×' + h + ' — under the 1280×720 that Yahoo requires. Save to re-check.' );
			} else {
				setNote( 'mmgrf-syn-muted', 'Featured image changed. Save to re-check syndication readiness.' );
			}
		} ).catch( function() {} );
	}

	// subscribe fires on every keystroke and store change. The save edge is
	// read synchronously, every time — sampling it inside a debounce missed
	// any save that finished between two quiet moments.
	wp.data.subscribe( function() {
		if ( ! select.getCurrentPostId() ) {
			return; // store not initialised yet
		}
		if ( ! ready ) {
			ready        = true;
			lastMedia    = select.getEditedPostAttribute( 'featured_media' );
			wasPublished = select.isCurrentPostPublished();
			wasSaving    = select.isSavingPost() && ! select.isAutosavingPost();
			return;
		}

		var saving = select.isSavingPost() && ! select.isAutosavingPost();
		if ( wasSaving && ! saving ) {
			var failed        = select.didPostSaveRequestFail && select.didPostSaveRequestFail();
			var isPublished   = select.isCurrentPostPublished();
			var justPublished = isPublished && ! wasPublished;
			wasPublished = isPublished;
			if ( ! failed ) {
				// Saved content is now authoritative for the image too.
				lastMedia = select.getEditedPostAttribute( 'featured_media' );
				if ( checkTimer ) {
					clearTimeout( checkTimer );
				}
				checkTimer = setTimeout( function() {
					fullCheck( justPublished );
				}, 300 );
			}
		}
		wasSaving = saving;

		var media = select.getEditedPostAttribute( 'featured_media' );
		if ( media !== lastMedia ) {
			lastMedia = media;
			if ( mediaTimer ) {
				clearTimeout( mediaTimer );
			}
			mediaTimer = setTimeout( function() {
				imageNote( media );
			}, 400 );
		}
	} );
} )();
