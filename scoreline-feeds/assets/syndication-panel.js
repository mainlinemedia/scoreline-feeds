/**
 * Syndication readiness panel (Fix 11). No build step — wp globals only.
 * Warns, never blocks. Checks B/C evaluate saved content (authoritative REST
 * result after each save); Check A additionally gets an instant client-side
 * verdict when the featured image changes, without waiting for a save.
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

	var NOTICE_ID   = 'mmgrf-syndication-image';
	var lastMedia   = select.getEditedPostAttribute( 'featured_media' );
	var wasSaving   = false;
	var wasPublished = select.isCurrentPostPublished();
	var debounceTimer = null;

	function render( result ) {
		if ( ! result || ! result.enabled ) {
			return;
		}
		if ( ! result.issues || result.issues.length === 0 ) {
			panel.innerHTML = '<p class="mmgrf-syn-ok">✓ Ready for syndication</p>';
			return;
		}
		panel.innerHTML = result.issues.map( function( issue ) {
			var p = document.createElement( 'p' );
			p.className = 'mmgrf-syn-warn';
			p.textContent = issue.message;
			return p.outerHTML;
		} ).join( '' );
	}

	function fullCheck( thenMaybeNotice ) {
		var postId = select.getCurrentPostId();
		if ( ! postId ) {
			return;
		}
		wp.apiFetch( { path: '/mmgrf/v1/syndication-check?post_id=' + postId } ).then( function( result ) {
			render( result );
			if ( thenMaybeNotice ) {
				maybePublishNotice( result );
			}
		} ).catch( function() { /* panel keeps its last state */ } );
	}

	// Pre-publish notice: Check A only (the missing/undersized lead image
	// costs the most distribution; three notices on one save is noise).
	function maybePublishNotice( result ) {
		var imageIssue = ( result.issues || [] ).find( function( i ) {
			return i.code === 'no_featured_image' || i.code === 'image_below_minimum' || i.code === 'image_dimensions_unknown';
		} );
		if ( imageIssue && wp.data.dispatch( 'core/notices' ) ) {
			wp.data.dispatch( 'core/notices' ).createWarningNotice( imageIssue.message, {
				id: NOTICE_ID, // stable id: repeated saves replace, never stack
				isDismissible: true,
			} );
		}
	}

	// Instant client-side verdict on featured-image swap (Check A only).
	function clientImageCheck( mediaId ) {
		if ( ! mediaId ) {
			panel.innerHTML = '<p class="mmgrf-syn-warn">No featured image set. This article will syndicate without an image, which significantly reduces its distribution.</p>';
			return;
		}
		wp.apiFetch( { path: '/wp/v2/media/' + mediaId } ).then( function( media ) {
			var d = media.media_details || {};
			if ( d.width >= 1280 && d.height >= 720 ) {
				panel.innerHTML = '<p class="mmgrf-syn-ok">✓ Ready for syndication</p>';
			} else if ( d.width ) {
				panel.innerHTML = '<p class="mmgrf-syn-warn">Featured image is ' + d.width + '×' + d.height +
					'. Yahoo requires at least 1280×720, so this article will syndicate without an image. Try re-uploading at a larger size.</p>';
			}
		} ).catch( function() {} );
	}

	// subscribe fires on every keystroke — debounce ~500ms.
	wp.data.subscribe( function() {
		if ( debounceTimer ) {
			clearTimeout( debounceTimer );
		}
		debounceTimer = setTimeout( function() {
			var media = select.getEditedPostAttribute( 'featured_media' );
			if ( media !== lastMedia ) {
				lastMedia = media;
				clientImageCheck( media );
			}
			var saving = select.isSavingPost() && ! select.isAutosavingPost();
			if ( wasSaving && ! saving ) {
				// Save completed → authoritative full result (Checks A+B+C).
				var isPublished = select.isCurrentPostPublished();
				var justPublished = isPublished && ! wasPublished;
				wasPublished = isPublished;
				fullCheck( justPublished );
			}
			wasSaving = saving;
		}, 500 );
	} );
} )();
