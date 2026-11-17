/**
 * Check-in Dashboard Block - Frontend Interactivity store.
 *
 * @package
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

const initializedMaps = new WeakSet();

function drawInto( mapElement ) {
	if ( initializedMaps.has( mapElement ) ) {
		return;
	}
	if ( typeof window.L === 'undefined' ) {
		return;
	}
	if ( ! mapElement ) {
		return;
	}

	const checkinsData = mapElement.dataset.checkins;
	if ( ! checkinsData ) {
		return;
	}

	let checkins;
	try {
		checkins = JSON.parse( checkinsData );
	} catch {
		return;
	}

	if ( ! checkins.length ) {
		return;
	}

	const { L } = window;
	const map = L.map( mapElement ).setView( [ 40, -95 ], 4 );

	L.tileLayer(
		mapElement.dataset.tileUrl ||
			'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
		{
			attribution:
				mapElement.dataset.attribution ||
				'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		}
	).addTo( map );

	const markers =
		typeof L.markerClusterGroup !== 'undefined'
			? L.markerClusterGroup()
			: L.layerGroup();
	const bounds = [];

	checkins.forEach( ( checkin ) => {
		if ( checkin.latitude && checkin.longitude ) {
			const marker = L.marker( [ checkin.latitude, checkin.longitude ], {
				alt: checkin.venue_name || '',
			} );
			const venueName = escapeHtml( checkin.venue_name );

			marker.bindPopup(
				'<div class="checkin-popup">' +
					'<strong>' +
					venueName +
					'</strong>' +
					( checkin.address
						? '<br><span>' +
							escapeHtml( checkin.address ) +
							'</span>'
						: '' ) +
					'<br><a href="' +
					escapeAttribute( checkin.permalink ) +
					'">' +
					escapeHtml( 'View post: ' + ( checkin.venue_name || '' ) ) +
					'</a>' +
					'</div>'
			);

			markers.addLayer( marker );
			bounds.push( [ checkin.latitude, checkin.longitude ] );
		}
	} );

	map.addLayer( markers );
	if ( bounds.length > 0 ) {
		map.fitBounds( bounds, { padding: [ 50, 50 ] } );
	}

	initializedMaps.add( mapElement );
}

function escapeHtml( string ) {
	if ( ! string ) {
		return '';
	}
	const element = document.createElement( 'div' );
	element.textContent = string;
	return element.innerHTML;
}

function escapeAttribute( string ) {
	return escapeHtml( string )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}

store( 'post-kinds-indieweb/checkin-dashboard', {
	state: {
		get isSelected() {
			const context = getContext();
			return context.view === context.name;
		},
	},
	actions: {
		selectView() {
			const context = getContext();
			const retryMap = context.name === 'map' && context.view === 'map';
			context.view = context.name;

			if ( retryMap ) {
				const dashboard = getElement().ref.closest(
					'.checkin-dashboard-frontend'
				);
				drawInto( dashboard?.querySelector( '.checkin-map' ) );
			}
		},
	},
	callbacks: {
		drawMap() {
			const context = getContext();
			if ( context.view !== 'map' ) {
				return;
			}
			drawInto( getElement().ref.querySelector( '.checkin-map' ) );
		},
	},
} );
