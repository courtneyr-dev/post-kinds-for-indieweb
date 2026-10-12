( () => {
	'use strict';

	const i18n = window.wp && window.wp.i18n ? window.wp.i18n : {};
	const __ = i18n.__ || ( ( text ) => text );
	const sprintf =
		i18n.sprintf ||
		( ( format, ...args ) => {
			let index = 0;
			return format.replace( /%1\$d|%2\$d|%d/g, () => args[ index++ ] );
		} );

	function enhance( projector ) {
		if ( projector.classList.contains( 'pkiw-projector--enhanced' ) ) {
			return;
		}

		const mounts = Array.from(
			projector.querySelectorAll( '.pkiw-projector__mount' )
		);
		if ( mounts.length < 2 ) {
			return;
		}

		let current = 0;
		projector.classList.add( 'pkiw-projector--enhanced' );

		const controls = document.createElement( 'div' );
		controls.className = 'pkiw-projector__controls';

		const previous = document.createElement( 'button' );
		previous.type = 'button';
		previous.className =
			'pkiw-projector__button pkiw-projector__button--prev';
		previous.textContent = __(
			'Previous image',
			'post-kinds-for-indieweb-in-block-themes'
		);

		const next = document.createElement( 'button' );
		next.type = 'button';
		next.className = 'pkiw-projector__button pkiw-projector__button--next';
		next.textContent = __(
			'Next image',
			'post-kinds-for-indieweb-in-block-themes'
		);

		const status = document.createElement( 'div' );
		status.className = 'pkiw-projector__status';
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'aria-atomic', 'true' );

		const counter = document.createElement( 'p' );
		counter.className = 'pkiw-projector__counter';

		const caption = document.createElement( 'p' );
		caption.className = 'pkiw-projector__caption';

		status.append( counter, caption );
		controls.append( previous, status, next );

		const rail = document.createElement( 'div' );
		rail.className = 'pkiw-projector__rail';
		const thumbs = mounts.map( ( mount, index ) => {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'pkiw-projector__thumb';
			button.setAttribute(
				'aria-label',
				sprintf(
					/* translators: 1: image number, 2: image count. */
					__(
						'Show image %1$d of %2$d',
						'post-kinds-for-indieweb-in-block-themes'
					),
					index + 1,
					mounts.length
				)
			);

			const source = mount.querySelector( 'img' );
			const image = document.createElement( 'img' );
			image.src =
				mount.getAttribute( 'data-pkiw-thumb-src' ) ||
				( source ? source.getAttribute( 'src' ) : '' );
			const srcset = mount.getAttribute( 'data-pkiw-thumb-srcset' );
			if ( srcset ) {
				image.srcset = srcset;
			}
			image.sizes = '44px';
			image.alt = '';
			image.loading = 'lazy';
			image.decoding = 'async';

			const ordinal = document.createElement( 'span' );
			ordinal.className = 'pkiw-projector__ordinal';
			ordinal.textContent = String( index + 1 );

			const currentLabel = document.createElement( 'span' );
			currentLabel.className = 'pkiw-projector__current';
			currentLabel.textContent = __(
				'Current',
				'post-kinds-for-indieweb-in-block-themes'
			);

			button.append( image, ordinal, currentLabel );
			button.addEventListener( 'click', () => show( index ) );
			rail.appendChild( button );

			return button;
		} );

		projector.append( controls, rail );

		function show( index ) {
			if ( index < 0 || index >= mounts.length || index === current ) {
				update();
				return;
			}
			current = index;
			update();
		}

		function update() {
			mounts.forEach( ( mount, index ) => {
				if ( index === current ) {
					mount.removeAttribute( 'hidden' );
				} else {
					mount.setAttribute( 'hidden', '' );
				}
			} );

			previous.setAttribute(
				'aria-disabled',
				0 === current ? 'true' : 'false'
			);
			next.setAttribute(
				'aria-disabled',
				current === mounts.length - 1 ? 'true' : 'false'
			);
			counter.textContent = sprintf(
				/* translators: 1: current image number, 2: image count. */
				__( '%1$d of %2$d', 'post-kinds-for-indieweb-in-block-themes' ),
				current + 1,
				mounts.length
			);
			const figcaption = mounts[ current ].querySelector( 'figcaption' );
			caption.innerHTML = figcaption ? figcaption.innerHTML : '';

			thumbs.forEach( ( button, index ) => {
				const currentMarker = button.querySelector(
					'.pkiw-projector__current'
				);
				if ( index === current ) {
					button.setAttribute( 'aria-current', 'true' );
					currentMarker.removeAttribute( 'hidden' );
				} else {
					button.removeAttribute( 'aria-current' );
					currentMarker.setAttribute( 'hidden', '' );
				}
			} );
		}

		previous.addEventListener( 'click', () => {
			if ( 'true' === previous.getAttribute( 'aria-disabled' ) ) {
				return;
			}
			show( current - 1 );
		} );

		next.addEventListener( 'click', () => {
			if ( 'true' === next.getAttribute( 'aria-disabled' ) ) {
				return;
			}
			show( current + 1 );
		} );

		projector.addEventListener( 'keydown', ( event ) => {
			if (
				event.ctrlKey ||
				event.metaKey ||
				event.altKey ||
				event.shiftKey
			) {
				return;
			}
			if ( 'ArrowLeft' === event.key ) {
				event.preventDefault();
				if ( current > 0 ) {
					show( current - 1 );
				}
			}
			if ( 'ArrowRight' === event.key ) {
				event.preventDefault();
				if ( current < mounts.length - 1 ) {
					show( current + 1 );
				}
			}
		} );

		update();
	}

	function init() {
		document.querySelectorAll( '.pkiw-projector' ).forEach( enhance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
} )();
