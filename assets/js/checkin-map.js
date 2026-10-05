/**
 * Check-in archive map.
 *
 * Draws the pins the server printed on `.pkiw-checkin-archive__map` and
 * links each pin to its list entry. The list works without this script.
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

			// Leaflet fires click for Enter on a focused marker.
			marker.on( 'click', () => {
				markEntries( pin.ids );
				markPin( marker );
				const first = entryEl( pin.ids[ 0 ] );
				const link = first && first.querySelector( 'a' );
				if ( link ) {
					link.focus();
				}
			} );

			pin.ids.forEach( ( id ) => {
				markerByEntry[ id ] = { marker, pin };
			} );

			group.addLayer( marker );
		} );

		map.addLayer( group );

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

	function init() {
		if ( typeof L === 'undefined' || ! L.markerClusterGroup ) {
			return;
		}
		document
			.querySelectorAll( '.pkiw-checkin-archive__map[data-pins]' )
			.forEach( initMap );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
