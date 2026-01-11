/**
 * Check-in Dashboard JavaScript
 *
 * @package Reactions_For_IndieWeb
 */

( function( $ ) {
	'use strict';

	const Dashboard = {
		map: null,
		markers: null,
		checkins: [],
		stats: {},
		currentView: 'grid',
		currentPage: 1,
		perPage: 50,
		filters: {
			year: '',
			venue_type: '',
			search: ''
		},

		/**
		 * Initialize the dashboard
		 */
		init: function() {
			this.bindEvents();
			this.loadData();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function() {
			// View toggles
			$( '.checkin-view-toggles .button' ).on( 'click', ( e ) => {
				e.preventDefault();
				const view = $( e.currentTarget ).data( 'view' );
				this.switchView( view );
			} );

			// Filters
			$( '#checkin-year-filter' ).on( 'change', ( e ) => {
				this.filters.year = $( e.target ).val();
				this.currentPage = 1;
				this.loadData();
			} );

			$( '#checkin-type-filter' ).on( 'change', ( e ) => {
				this.filters.venue_type = $( e.target ).val();
				this.currentPage = 1;
				this.loadData();
			} );

			let searchTimeout;
			$( '#checkin-search' ).on( 'input', ( e ) => {
				clearTimeout( searchTimeout );
				searchTimeout = setTimeout( () => {
					this.filters.search = $( e.target ).val();
					this.currentPage = 1;
					this.loadData();
				}, 300 );
			} );
		},

		/**
		 * Load check-ins and stats
		 */
		loadData: function() {
			this.showLoading();

			const params = new URLSearchParams( {
				page: this.currentPage,
				per_page: this.perPage
			} );

			if ( this.filters.year ) params.append( 'year', this.filters.year );
			if ( this.filters.venue_type ) params.append( 'venue_type', this.filters.venue_type );
			if ( this.filters.search ) params.append( 'search', this.filters.search );

			// Load both check-ins and stats
			Promise.all( [
				fetch( `${ reactionsCheckinDashboard.restUrl }checkins?${ params.toString() }`, {
					headers: { 'X-WP-Nonce': reactionsCheckinDashboard.nonce }
				} ).then( r => r.json() ),
				fetch( `${ reactionsCheckinDashboard.restUrl }checkins/stats${ this.filters.year ? '?year=' + this.filters.year : '' }`, {
					headers: { 'X-WP-Nonce': reactionsCheckinDashboard.nonce }
				} ).then( r => r.json() )
			] ).then( ( [ checkinsData, statsData ] ) => {
				this.checkins = checkinsData;
				this.stats = statsData;
				this.render();
			} ).catch( ( error ) => {
				console.error( 'Error loading data:', error );
				this.showError();
			} );
		},

		/**
		 * Show loading state
		 */
		showLoading: function() {
			$( '.checkin-grid-view, .checkin-map-view, .checkin-timeline-view' ).html(
				'<div class="checkin-loading"><span class="spinner is-active"></span> ' + reactionsCheckinDashboard.i18n.loading + '</div>'
			);
		},

		/**
		 * Show error state
		 */
		showError: function() {
			$( '.checkin-grid-view, .checkin-map-view, .checkin-timeline-view' ).html(
				'<div class="checkin-empty"><span class="dashicons dashicons-warning"></span><p>Error loading check-ins</p></div>'
			);
		},

		/**
		 * Render all views and stats
		 */
		render: function() {
			this.renderGridView();
			this.renderMapView();
			this.renderTimelineView();
			this.renderStats();
		},

		/**
		 * Switch between views
		 */
		switchView: function( view ) {
			this.currentView = view;

			// Update toggle buttons
			$( '.checkin-view-toggles .button' ).removeClass( 'active' );
			$( `.checkin-view-toggles .button[data-view="${ view }"]` ).addClass( 'active' );

			// Show/hide views
			$( '.checkin-grid-view, .checkin-map-view, .checkin-timeline-view' ).removeClass( 'active' );
			$( `.checkin-${ view }-view` ).addClass( 'active' );

			// Initialize map if switching to map view
			if ( view === 'map' && ! this.map ) {
				this.initMap();
			} else if ( view === 'map' && this.map ) {
				setTimeout( () => this.map.invalidateSize(), 100 );
			}
		},

		/**
		 * Render grid view
		 */
		renderGridView: function() {
			const $container = $( '.checkin-grid-view' );

			if ( ! this.checkins.length ) {
				$container.html(
					'<div class="checkin-empty">' +
					'<span class="dashicons dashicons-location"></span>' +
					'<p>' + reactionsCheckinDashboard.i18n.noCheckins + '</p>' +
					'</div>'
				);
				return;
			}

			let html = '<div class="checkin-grid">';

			this.checkins.forEach( ( checkin ) => {
				html += this.renderCheckinCard( checkin );
			} );

			html += '</div>';
			html += this.renderPagination();

			$container.html( html );

			// Bind pagination events
			$container.find( '.page-numbers' ).on( 'click', ( e ) => {
				e.preventDefault();
				const page = $( e.currentTarget ).data( 'page' );
				if ( page && page !== this.currentPage ) {
					this.currentPage = page;
					this.loadData();
				}
			} );
		},

		/**
		 * Render a single check-in card
		 */
		renderCheckinCard: function( checkin ) {
			const date = new Date( checkin.checkin_time );
			const formattedDate = date.toLocaleDateString( undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric'
			} );

			let photoHtml = '';
			if ( checkin.photo ) {
				photoHtml = `<img src="${ this.escapeHtml( checkin.photo ) }" alt="${ this.escapeHtml( checkin.venue_name ) }">`;
			} else {
				photoHtml = '<div class="no-photo"><span class="dashicons dashicons-location"></span></div>';
			}

			let noteHtml = '';
			if ( checkin.note ) {
				noteHtml = `<p class="checkin-card-note">"${ this.escapeHtml( checkin.note ) }"</p>`;
			}

			return `
				<div class="checkin-card">
					<div class="checkin-card-photo">${ photoHtml }</div>
					<div class="checkin-card-content">
						<h4 class="checkin-card-venue">${ this.escapeHtml( checkin.venue_name ) }</h4>
						<p class="checkin-card-address">${ this.escapeHtml( checkin.address || '' ) }</p>
						<div class="checkin-card-meta">
							<span class="checkin-card-type">${ this.escapeHtml( checkin.venue_type || 'venue' ) }</span>
							<span class="checkin-card-date">${ formattedDate }</span>
						</div>
						${ noteHtml }
					</div>
				</div>
			`;
		},

		/**
		 * Initialize Leaflet map
		 */
		initMap: function() {
			// Check if Leaflet is available
			if ( typeof L === 'undefined' ) {
				$( '.checkin-map-view' ).html(
					'<div class="checkin-empty"><span class="dashicons dashicons-warning"></span><p>Map library not loaded</p></div>'
				);
				return;
			}

			// Create map
			this.map = L.map( 'checkin-map' ).setView( [ 40, -95 ], 4 );

			// Add tile layer (OpenStreetMap)
			L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
			} ).addTo( this.map );

			// Create marker cluster group
			if ( typeof L.markerClusterGroup !== 'undefined' ) {
				this.markers = L.markerClusterGroup();
			} else {
				this.markers = L.layerGroup();
			}

			this.map.addLayer( this.markers );
			this.updateMapMarkers();
		},

		/**
		 * Update map markers
		 */
		updateMapMarkers: function() {
			if ( ! this.map || ! this.markers ) return;

			this.markers.clearLayers();

			const bounds = [];

			this.checkins.forEach( ( checkin ) => {
				if ( checkin.latitude && checkin.longitude ) {
					const marker = L.marker( [ checkin.latitude, checkin.longitude ] );

					const date = new Date( checkin.checkin_time );
					const formattedDate = date.toLocaleDateString( undefined, {
						year: 'numeric',
						month: 'short',
						day: 'numeric'
					} );

					marker.bindPopup( `
						<div class="checkin-popup">
							<div class="checkin-popup-venue">${ this.escapeHtml( checkin.venue_name ) }</div>
							<div class="checkin-popup-address">${ this.escapeHtml( checkin.address || '' ) }</div>
							<div class="checkin-popup-date">${ formattedDate }</div>
						</div>
					` );

					this.markers.addLayer( marker );
					bounds.push( [ checkin.latitude, checkin.longitude ] );
				}
			} );

			// Fit map to markers
			if ( bounds.length > 0 ) {
				this.map.fitBounds( bounds, { padding: [ 50, 50 ] } );
			}
		},

		/**
		 * Render map view
		 */
		renderMapView: function() {
			if ( this.map ) {
				this.updateMapMarkers();
			}
		},

		/**
		 * Render timeline view
		 */
		renderTimelineView: function() {
			const $container = $( '.checkin-timeline-view' );

			if ( ! this.checkins.length ) {
				$container.html(
					'<div class="checkin-empty">' +
					'<span class="dashicons dashicons-location"></span>' +
					'<p>' + reactionsCheckinDashboard.i18n.noCheckins + '</p>' +
					'</div>'
				);
				return;
			}

			// Group by month
			const grouped = {};
			this.checkins.forEach( ( checkin ) => {
				const date = new Date( checkin.checkin_time );
				const key = date.toLocaleDateString( undefined, { year: 'numeric', month: 'long' } );
				if ( ! grouped[ key ] ) {
					grouped[ key ] = [];
				}
				grouped[ key ].push( checkin );
			} );

			let html = '';

			Object.keys( grouped ).forEach( ( month ) => {
				html += `<div class="timeline-group">`;
				html += `<h3 class="timeline-group-header">${ month }</h3>`;
				html += '<div class="timeline-items">';

				grouped[ month ].forEach( ( checkin ) => {
					const date = new Date( checkin.checkin_time );
					const formattedDate = date.toLocaleDateString( undefined, {
						weekday: 'short',
						month: 'short',
						day: 'numeric'
					} );
					const formattedTime = date.toLocaleTimeString( undefined, {
						hour: 'numeric',
						minute: '2-digit'
					} );

					let noteHtml = '';
					if ( checkin.note ) {
						noteHtml = `<p class="timeline-item-note">"${ this.escapeHtml( checkin.note ) }"</p>`;
					}

					html += `
						<div class="timeline-item">
							<div class="timeline-item-header">
								<span class="timeline-item-venue">${ this.escapeHtml( checkin.venue_name ) }</span>
								<span class="timeline-item-date">${ formattedDate } at ${ formattedTime }</span>
							</div>
							<p class="timeline-item-address">${ this.escapeHtml( checkin.address || '' ) }</p>
							${ noteHtml }
						</div>
					`;
				} );

				html += '</div></div>';
			} );

			$container.html( html );
		},

		/**
		 * Render statistics sidebar
		 */
		renderStats: function() {
			const stats = this.stats;

			// Overview stats
			$( '#stat-total-checkins' ).text( stats.total || 0 );
			$( '#stat-unique-venues' ).text( stats.unique_venues || 0 );
			$( '#stat-countries' ).text( stats.countries?.length || 0 );
			$( '#stat-cities' ).text( stats.cities?.length || 0 );

			// Top venues
			const $venuesList = $( '#top-venues-list' );
			$venuesList.empty();

			if ( stats.most_visited?.length ) {
				stats.most_visited.forEach( ( venue ) => {
					$venuesList.append(
						`<li><span class="venue-name">${ this.escapeHtml( venue.name ) }</span><span class="venue-count">${ venue.count }</span></li>`
					);
				} );
			} else {
				$venuesList.append( '<li>No data</li>' );
			}

			// Countries
			const $countriesList = $( '#countries-list' );
			$countriesList.empty();

			if ( stats.countries?.length ) {
				stats.countries.slice( 0, 10 ).forEach( ( country ) => {
					$countriesList.append( `<span class="place-tag">${ this.escapeHtml( country ) }</span>` );
				} );
			} else {
				$countriesList.append( '<span class="place-tag">None yet</span>' );
			}

			// Cities
			const $citiesList = $( '#cities-list' );
			$citiesList.empty();

			if ( stats.cities?.length ) {
				stats.cities.slice( 0, 10 ).forEach( ( city ) => {
					$citiesList.append( `<span class="place-tag">${ this.escapeHtml( city ) }</span>` );
				} );
			} else {
				$citiesList.append( '<span class="place-tag">None yet</span>' );
			}
		},

		/**
		 * Render pagination controls
		 */
		renderPagination: function() {
			const totalPages = Math.ceil( this.stats.total / this.perPage );

			if ( totalPages <= 1 ) return '';

			let html = '<div class="checkin-pagination">';

			// Previous
			if ( this.currentPage > 1 ) {
				html += `<a href="#" class="page-numbers" data-page="${ this.currentPage - 1 }">&laquo; Prev</a>`;
			}

			// Page numbers
			for ( let i = 1; i <= totalPages; i++ ) {
				if ( i === this.currentPage ) {
					html += `<span class="page-numbers current">${ i }</span>`;
				} else if ( i === 1 || i === totalPages || Math.abs( i - this.currentPage ) <= 2 ) {
					html += `<a href="#" class="page-numbers" data-page="${ i }">${ i }</a>`;
				} else if ( Math.abs( i - this.currentPage ) === 3 ) {
					html += '<span class="page-numbers">...</span>';
				}
			}

			// Next
			if ( this.currentPage < totalPages ) {
				html += `<a href="#" class="page-numbers" data-page="${ this.currentPage + 1 }">Next &raquo;</a>`;
			}

			html += '</div>';

			return html;
		},

		/**
		 * Escape HTML entities
		 */
		escapeHtml: function( str ) {
			if ( ! str ) return '';
			const div = document.createElement( 'div' );
			div.textContent = str;
			return div.innerHTML;
		}
	};

	// Initialize on document ready
	$( document ).ready( function() {
		if ( $( '.checkin-dashboard-wrap' ).length ) {
			Dashboard.init();
		}
	} );

} )( jQuery );
