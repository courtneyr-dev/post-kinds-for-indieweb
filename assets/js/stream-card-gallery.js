/**
 * Stream card gallery — swap the hero image and its caption.
 *
 * Progressive enhancement only. The card is already correct without this
 * file: the hero, its caption and the thumbnail row are all server-rendered,
 * which is what a feed reader or an unfurler receives. This adds the ability
 * to look at another image without leaving the feed.
 *
 * Thumbnails are already <button> elements, so keyboard support comes from the
 * platform rather than from key handlers here. The caption region is
 * aria-live="polite" so the new caption is announced without interrupting, and
 * the hero's alt text is swapped alongside it — announcing a caption that
 * describes a different image than the one on screen would be worse than not
 * announcing at all.
 *
 * @package
 */

( function () {
	'use strict';

	/**
	 * Swap the hero image and caption to the chosen thumbnail's.
	 *
	 * @param {HTMLElement} media  The .pk-media--stream container.
	 * @param {HTMLElement} button The activated thumbnail button.
	 */
	function activate( media, button ) {
		const hero = media.querySelector( '.wp-post-image' );
		if ( ! hero ) {
			return;
		}
		const caption = media.querySelector( '.pk-media__caption' );

		const full = button.getAttribute( 'data-pk-full' );
		const alt = button.getAttribute( 'data-pk-alt' ) || '';
		const text = button.getAttribute( 'data-pk-caption' ) || '';

		if ( full ) {
			hero.setAttribute( 'src', full );
			hero.removeAttribute( 'srcset' );
			hero.removeAttribute( 'sizes' );
		}
		// Alt and caption move together or they describe different pictures.
		hero.setAttribute( 'alt', alt );
		if ( caption ) {
			caption.textContent = text;
		}

		media
			.querySelectorAll( '.pk-media__thumb-button' )
			.forEach( function ( other ) {
				other.setAttribute(
					'aria-pressed',
					other === button ? 'true' : 'false'
				);
			} );
	}

	function init( media ) {
		const buttons = media.querySelectorAll( '.pk-media__thumb-button' );
		if ( ! buttons.length ) {
			return;
		}
		// Only meaningful once the swap can happen, so the state is added here
		// rather than server-side where it would lie for a no-JS reader.
		buttons.forEach( function ( button ) {
			button.setAttribute( 'aria-pressed', 'false' );
			button.addEventListener( 'click', function () {
				activate( media, button );
			} );
		} );
	}

	function boot() {
		document.querySelectorAll( '.pk-media--stream' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
