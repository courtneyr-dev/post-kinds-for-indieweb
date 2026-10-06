/**
 * Check-in archive map.
 *
 * Draws the pins the server printed on `.pkiw-checkin-archive__map` and
 * links each pin to its list entry. The list works without this script.
 *
 * A map marked data-pkiw-consent="required" (the
 * pkiw_checkin_map_requires_consent filter) loads no tiles until a
 * `pkiw:map-consent` event on document, at any time after this script
 * loads, or window.pkiwMapConsent === true.
 *
 * Keyboard: zoom buttons come before the pins in tab order. Enter on a pin
 * moves focus to its list entry. Each list number becomes a button that
 * centers the map on its pin, so nothing needs a drag. Nearby pins merge
 * into a cluster button that zooms in on Enter.
 *
 * @package
 */

( function () {
	'use strict';

	const reducedMotion =
		window.matchMedia &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

	function pinIcon( pin, extraClass ) {
		const more =
			pin.numbers.length > 1
				? '<span class="pkiw-pin__more" aria-hidden="true">+' +
					( pin.numbers.length - 1 ) +
					'</span>'
				: '';

		return L.divIcon( {
			className: 'pkiw-pin' + ( extraClass ? ' ' + extraClass : '' ),
			html:
				'<span class="pkiw-pin__num" aria-hidden="true">' +
				pin.numbers[ 0 ] +
				'</span>' +
				more +
				'<span class="pkiw-pin__label">' +
				escapeHtml( pin.label ) +
				'</span>',
			iconSize: [ 44, 44 ],
			iconAnchor: [ 22, 22 ],
		} );
	}

	function clusterIcon( count, label ) {
		return L.divIcon( {
			className: 'pkiw-pin pkiw-pin--cluster',
			html:
				'<span class="pkiw-pin__num" aria-hidden="true">' +
				count +
				'</span><span class="pkiw-pin__label">' +
				escapeHtml( label.replace( '%d', count ) ) +
				'</span>',
			iconSize: [ 44, 44 ],
			iconAnchor: [ 22, 22 ],
		} );
	}

	function initMap( el ) {
		let pins;
		try {
			pins = JSON.parse( el.dataset.pins || '[]' );
		} catch {
			return;
		}
		if ( ! pins.length ) {
			return;
		}

		const root = el.closest( '.pkiw-checkin-archive' ) || document;
		const maxZoom = parseInt( el.dataset.maxZoom, 10 ) || 19;

		el.hidden = false;

		const map = L.map( el, {
			zoomAnimation: ! reducedMotion,
			fadeAnimation: ! reducedMotion,
			markerZoomAnimation: ! reducedMotion,
			maxZoom,
		} );

		map.attributionControl.setPrefix( false );

		L.tileLayer( el.dataset.tileUrl, {
			attribution: el.dataset.attribution,
			maxZoom,
		} ).addTo( map );

		// Zoom buttons sit at the top left, so they come first in tab order.
		const controls = el.querySelector( '.leaflet-control-container' );
		if ( controls ) {
			el.insertBefore( controls, el.firstChild );
		}

		// The view comes first: the cluster layer reads the map's zoom when it
		// is added.
		if ( 1 === pins.length ) {
			map.setView( [ pins[ 0 ].lat, pins[ 0 ].lng ], 15, {
				animate: false,
			} );
		} else {
			map.fitBounds(
				L.latLngBounds( pins.map( ( p ) => [ p.lat, p.lng ] ) ),
				{ padding: [ 48, 48 ], animate: false, maxZoom: 16 }
			);
		}

		const group = L.markerClusterGroup( {
			showCoverageOnHover: false,
			spiderfyOnMaxZoom: false,
			maxClusterRadius: 44,
			animate: ! reducedMotion,
			iconCreateFunction( cluster ) {
				return clusterIcon(
					cluster
						.getAllChildMarkers()
						.reduce( ( sum, m ) => sum + m.pkiwCount, 0 ),
					el.dataset.clusterLabel || '%d'
				);
			},
		} );

		const markerByEntry = {};
		const markers = [];

		function entryEl( id ) {
			return document.getElementById( 'pkiw-checkin-entry-' + id );
		}

		function markEntries( ids ) {
			root.querySelectorAll(
				'.pkiw-checkin-archive__entry.is-active'
			).forEach( ( li ) => li.classList.remove( 'is-active' ) );
			ids.forEach( ( id ) => {
				const li = entryEl( id );
				if ( li ) {
					li.classList.add( 'is-active' );
				}
			} );
		}

		function markPin( marker ) {
			el.querySelectorAll( '.pkiw-pin.is-active' ).forEach( ( pin ) =>
				pin.classList.remove( 'is-active' )
			);
			const icon = marker.getElement();
			if ( icon ) {
				icon.classList.add( 'is-active' );
			}
		}

		pins.forEach( ( pin ) => {
			const marker = L.marker( [ pin.lat, pin.lng ], {
				icon: pinIcon( pin ),
				keyboard: true,
				riseOnHover: true,
			} );
			marker.pkiwCount = pin.ids.length;
			marker.pkiwNumber = pin.numbers[ 0 ];
			markers.push( marker );

			const goToEntry = () => {
				markEntries( pin.ids );
				markPin( marker );
				const first = entryEl( pin.ids[ 0 ] );
				const link = first && first.querySelector( 'a' );
				if ( link ) {
					link.focus();
				}
			};

			// A pin is a button: a click, Enter or Space activates it.
			marker.on( 'click', goToEntry );
			marker.on( 'keypress', ( event ) => {
				const key = event.originalEvent && event.originalEvent.key;
				if ( 'Enter' === key || ' ' === key ) {
					event.originalEvent.preventDefault();
					goToEntry();
				}
			} );

			pin.ids.forEach( ( id ) => {
				markerByEntry[ id ] = { marker, pin };
			} );

			group.addLayer( marker );
		} );

		map.addLayer( group );

		markers.sort( ( a, b ) => a.pkiwNumber - b.pkiwNumber );

		// Pins take focus in list order. markercluster adds their elements
		// in its own order, so they go back in order after every redraw. A
		// cluster sits where its first check-in in the list would.
		function orderPins() {
			const pane = map.getPane( 'markerPane' );
			const icons = [];
			markers.forEach( ( marker ) => {
				const visible = group.getVisibleParent( marker );
				const icon = visible && visible.getElement();
				if (
					icon &&
					icon.parentNode === pane &&
					! icons.includes( icon )
				) {
					icons.push( icon );
				}
			} );

			const current = Array.prototype.filter.call(
				pane.children,
				( child ) => icons.includes( child )
			);
			if ( current.every( ( icon, i ) => icon === icons[ i ] ) ) {
				return;
			}

			// Moving the focused element drops its focus; give it back.
			const doc = el.ownerDocument;
			const focused = doc.activeElement;
			icons.forEach( ( icon ) => pane.appendChild( icon ) );
			if ( focused !== doc.activeElement && icons.includes( focused ) ) {
				focused.focus( { preventScroll: true } );
			}
		}

		map.on( 'moveend', orderPins );
		group.on( 'animationend', orderPins );
		orderPins();

		// The pin takes focus, or the cluster now holding it, or the map
		// region when neither is drawn.
		function focusPin( marker ) {
			const visible = group.getVisibleParent( marker );
			const icon = visible && visible.getElement();
			( icon && icon.isConnected ? icon : el ).focus();
		}

		// leaflet.markercluster 1.4.1 zooms a cluster on click only. Enter
		// and Space zoom in too, or spread the cluster at the maximum zoom.
		// The cluster's element goes away, so focus moves to its first
		// check-in in the list.
		group.on( 'clusterkeypress', ( event ) => {
			const key = event.originalEvent && event.originalEvent.key;
			if ( 'Enter' !== key && ' ' !== key ) {
				return;
			}
			event.originalEvent.preventDefault();

			const cluster = event.layer;
			const first = cluster
				.getAllChildMarkers()
				.reduce( ( a, b ) => ( b.pkiwNumber < a.pkiwNumber ? b : a ) );

			if ( map.getZoom() >= map.getMaxZoom() ) {
				const icon = first.getElement();
				if ( icon && icon.isConnected ) {
					icon.focus();
					return;
				}
				group.once( 'spiderfied', () => focusPin( first ) );
				cluster.spiderfy();
				return;
			}

			map.once( 'moveend', () => focusPin( first ) );
			cluster.zoomToBounds( { padding: [ 48, 48 ] } );
		} );

		// Each list number becomes the control that moves the map to its pin.
		root.querySelectorAll(
			'.pkiw-checkin-archive__num[data-map="' + el.id + '"]'
		).forEach( ( span ) => {
			const target = markerByEntry[ span.dataset.entry ];
			if ( ! target ) {
				return;
			}

			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = span.className;
			button.textContent = span.textContent;
			button.setAttribute( 'aria-label', span.dataset.label );
			button.setAttribute( 'aria-controls', el.id );

			button.addEventListener( 'click', () => {
				markEntries( target.pin.ids );
				group.zoomToShowLayer( target.marker, () => {
					map.setView(
						target.marker.getLatLng(),
						Math.max( map.getZoom(), 15 ),
						{ animate: ! reducedMotion }
					);
					markPin( target.marker );
				} );
			} );

			span.replaceWith( button );
		} );
	}

	// A consent tool can answer before the page finishes loading, so the
	// listener goes on now. Maps found waiting draw when it fires.
	let consented = false;
	const waiting = [];
	document.addEventListener(
		'pkiw:map-consent',
		() => {
			consented = true;
			waiting.splice( 0 ).forEach( ( el ) => initMap( el ) );
		},
		{ once: true }
	);

	function init() {
		if ( typeof L === 'undefined' || ! L.markerClusterGroup ) {
			return;
		}
		document
			.querySelectorAll( '.pkiw-checkin-archive__map[data-pins]' )
			.forEach( ( el ) => {
				if (
					'required' === el.dataset.pkiwConsent &&
					true !== window.pkiwMapConsent &&
					! consented
				) {
					waiting.push( el );
					return;
				}
				initMap( el );
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
