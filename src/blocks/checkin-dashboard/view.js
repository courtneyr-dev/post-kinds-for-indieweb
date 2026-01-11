/**
 * Check-in Dashboard Block - Frontend View Script
 *
 * @package Reactions_For_IndieWeb
 */

( function() {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function() {
		const dashboards = document.querySelectorAll( '.checkin-dashboard-frontend' );

		dashboards.forEach( function( dashboard ) {
			initDashboard( dashboard );
		} );
	} );

	function initDashboard( dashboard ) {
		const showMap = dashboard.dataset.showMap === 'true';

		// View switching
		const viewBtns = dashboard.querySelectorAll( '.view-btn' );
		const views = dashboard.querySelectorAll( '[class*="checkin-view-"]' );

		viewBtns.forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				const view = this.dataset.view;

				// Update buttons
				viewBtns.forEach( ( b ) => b.classList.remove( 'active' ) );
				this.classList.add( 'active' );

				// Update views
				views.forEach( function( v ) {
					v.classList.remove( 'active' );
					if ( v.classList.contains( 'checkin-view-' + view ) ) {
						v.classList.add( 'active' );
					}
				} );

				// Initialize map on first view
				if ( view === 'map' && showMap ) {
					initMap( dashboard );
				}
			} );
		} );

		// Initialize map if it's the default view
		if ( dashboard.dataset.layout === 'map' && showMap ) {
			initMap( dashboard );
		}
	}

	let mapInitialized = false;

	function initMap( dashboard ) {
		if ( mapInitialized ) return;
		if ( typeof L === 'undefined' ) return;

		const mapContainer = dashboard.querySelector( '#checkin-frontend-map' );
		if ( ! mapContainer ) return;

		const checkinsData = mapContainer.dataset.checkins;
		if ( ! checkinsData ) return;

		let checkins;
		try {
			checkins = JSON.parse( checkinsData );
		} catch ( e ) {
			return;
		}

		if ( ! checkins.length ) return;

		// Create map
		const map = L.map( mapContainer ).setView( [ 40, -95 ], 4 );

		// Add tile layer
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		} ).addTo( map );

		// Create markers
		const markers = typeof L.markerClusterGroup !== 'undefined'
			? L.markerClusterGroup()
			: L.layerGroup();

		const bounds = [];

		checkins.forEach( function( checkin ) {
			if ( checkin.latitude && checkin.longitude ) {
				const marker = L.marker( [ checkin.latitude, checkin.longitude ] );

				marker.bindPopup(
					'<div class="checkin-popup">' +
					'<strong>' + escapeHtml( checkin.venue_name ) + '</strong>' +
					( checkin.address ? '<br><span>' + escapeHtml( checkin.address ) + '</span>' : '' ) +
					'<br><a href="' + checkin.permalink + '">View post</a>' +
					'</div>'
				);

				markers.addLayer( marker );
				bounds.push( [ checkin.latitude, checkin.longitude ] );
			}
		} );

		map.addLayer( markers );

		// Fit to bounds
		if ( bounds.length > 0 ) {
			map.fitBounds( bounds, { padding: [ 50, 50 ] } );
		}

		mapInitialized = true;
	}

	function escapeHtml( str ) {
		if ( ! str ) return '';
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}
} )();
