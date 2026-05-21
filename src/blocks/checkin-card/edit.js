/**
 * Checkin Card Block - Edit Component
 *
 * Enhanced with location search, geolocation, and privacy controls.
 * Syncs with the post-kinds store for bidirectional data flow.
 *
 * @package
 */

/* global navigator */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	RichText,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	Button,
	DateTimePicker,
	Popover,
	ToggleControl,
	RadioControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { checkinIcon } from '../shared/icons';
import { BlockPlaceholder, LocationDisplay } from '../shared/components';
import { STORE_NAME } from '../../editor/stores/post-kinds';

/**
 * Debounce utility function
 *
 * @param {Function} func Function to debounce.
 * @param {number}   wait Milliseconds to wait.
 * @return {Function} Debounced function.
 */
function debounce( func, wait ) {
	let timeout;
	return function executedFunction( ...args ) {
		const later = () => {
			clearTimeout( timeout );
			func( ...args );
		};
		clearTimeout( timeout );
		timeout = setTimeout( later, wait );
	};
}

/**
 * Edit component for the Checkin Card block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		venueName,
		venueType,
		address,
		locality,
		region,
		country,
		postalCode,
		latitude,
		longitude,
		locationPrivacy,
		osmId,
		venueUrl,
		foursquareId,
		checkinAt,
		note,
		photo,
		photoAlt,
		showMap,
		layout,
	} = attributes;

	const [ showDatePicker, setShowDatePicker ] = useState( false );
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchResults, setSearchResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ isLocating, setIsLocating ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ showSearch, setShowSearch ] = useState( false );
	const [ userLocation, setUserLocation ] = useState( null );
	const [ nearbyVenues, setNearbyVenues ] = useState( [] );
	const [ isLoadingNearby, setIsLoadingNearby ] = useState( false );
	const [ sidebarSearchQuery, setSidebarSearchQuery ] = useState( '' );
	const [ sidebarSearchResults, setSidebarSearchResults ] = useState( [] );
	const [ isSidebarSearching, setIsSidebarSearching ] = useState( false );
	const [ hasInitialized, setHasInitialized ] = useState( false );

	const searchInputRef = useRef( null );

	const blockProps = useBlockProps( {
		className: `checkin-card layout-${ layout }`,
	} );

	// Get post meta from the post-kinds store
	const {
		metaVenueName,
		metaAddress,
		metaLocality,
		metaRegion,
		metaCountry,
		metaLatitude,
		metaLongitude,
		selectedKind,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			metaVenueName: getKindMeta( 'checkin_name' ),
			metaAddress: getKindMeta( 'checkin_address' ),
			metaLocality: getKindMeta( 'checkin_locality' ),
			metaRegion: getKindMeta( 'checkin_region' ),
			metaCountry: getKindMeta( 'checkin_country' ),
			metaLatitude: getKindMeta( 'geo_latitude' ),
			metaLongitude: getKindMeta( 'geo_longitude' ),
			selectedKind: select( STORE_NAME ).getSelectedKind(),
		};
	}, [] );

	const { updateKindMeta, updatePostKind } = useDispatch( STORE_NAME );

	// On mount, set the post kind to 'checkin' if not already set
	useEffect( () => {
		if ( ! hasInitialized ) {
			if ( ! selectedKind || selectedKind !== 'checkin' ) {
				updatePostKind( 'checkin' );
			}
			setHasInitialized( true );
		}
	}, [ hasInitialized, selectedKind, updatePostKind ] );

	// Sync block attributes TO post meta when block attributes change
	useEffect( () => {
		if ( ! hasInitialized ) {
			return;
		}

		// Only sync if block has values and they differ from meta
		if ( venueName && venueName !== metaVenueName ) {
			updateKindMeta( 'checkin_name', venueName );
		}
		if ( address && address !== metaAddress ) {
			updateKindMeta( 'checkin_address', address );
		}
		if ( locality && locality !== metaLocality ) {
			updateKindMeta( 'checkin_locality', locality );
		}
		if ( region && region !== metaRegion ) {
			updateKindMeta( 'checkin_region', region );
		}
		if ( country && country !== metaCountry ) {
			updateKindMeta( 'checkin_country', country );
		}
		if ( latitude && latitude !== metaLatitude ) {
			updateKindMeta( 'geo_latitude', latitude );
		}
		if ( longitude && longitude !== metaLongitude ) {
			updateKindMeta( 'geo_longitude', longitude );
		}
	}, [
		hasInitialized,
		venueName,
		address,
		locality,
		region,
		country,
		latitude,
		longitude,
	] );

	// Sync post meta TO block attributes when meta changes (from sidebar)
	useEffect( () => {
		if ( ! hasInitialized ) {
			return;
		}

		// If meta has values and block doesn't, or meta changed, sync to block
		const updates = {};

		if ( metaVenueName && metaVenueName !== venueName ) {
			updates.venueName = metaVenueName;
		}
		if ( metaAddress && metaAddress !== address ) {
			updates.address = metaAddress;
		}
		if ( metaLocality && metaLocality !== locality ) {
			updates.locality = metaLocality;
		}
		if ( metaRegion && metaRegion !== region ) {
			updates.region = metaRegion;
		}
		if ( metaCountry && metaCountry !== country ) {
			updates.country = metaCountry;
		}
		if ( metaLatitude && metaLatitude !== latitude ) {
			updates.latitude = metaLatitude;
		}
		if ( metaLongitude && metaLongitude !== longitude ) {
			updates.longitude = metaLongitude;
		}

		if ( Object.keys( updates ).length > 0 ) {
			setAttributes( updates );
		}
	}, [
		hasInitialized,
		metaVenueName,
		metaAddress,
		metaLocality,
		metaRegion,
		metaCountry,
		metaLatitude,
		metaLongitude,
	] );

	// Venue type options
	const venueTypes = [
		{ label: __( 'Place', 'post-kinds-for-indieweb' ), value: 'place' },
		{
			label: __( 'Restaurant', 'post-kinds-for-indieweb' ),
			value: 'restaurant',
		},
		{ label: __( 'Cafe', 'post-kinds-for-indieweb' ), value: 'cafe' },
		{ label: __( 'Bar', 'post-kinds-for-indieweb' ), value: 'bar' },
		{ label: __( 'Hotel', 'post-kinds-for-indieweb' ), value: 'hotel' },
		{ label: __( 'Airport', 'post-kinds-for-indieweb' ), value: 'airport' },
		{ label: __( 'Park', 'post-kinds-for-indieweb' ), value: 'park' },
		{ label: __( 'Museum', 'post-kinds-for-indieweb' ), value: 'museum' },
		{ label: __( 'Theater', 'post-kinds-for-indieweb' ), value: 'theater' },
		{ label: __( 'Store', 'post-kinds-for-indieweb' ), value: 'store' },
		{ label: __( 'Office', 'post-kinds-for-indieweb' ), value: 'office' },
		{ label: __( 'Home', 'post-kinds-for-indieweb' ), value: 'home' },
		{ label: __( 'Other', 'post-kinds-for-indieweb' ), value: 'other' },
	];

	// Privacy options
	const privacyOptions = [
		{
			label: __( 'Public (exact location)', 'post-kinds-for-indieweb' ),
			value: 'public',
		},
		{
			label: __( 'Approximate (city level)', 'post-kinds-for-indieweb' ),
			value: 'approximate',
		},
		{
			label: __( 'Private (hidden)', 'post-kinds-for-indieweb' ),
			value: 'private',
		},
	];

	/**
	 * Search for venues using Foursquare Places API with Nominatim fallback
	 *
	 * @param {string} query Search query.
	 */
	const doVenueSearch = async ( query ) => {
		if ( ! query || query.trim().length < 2 ) {
			setSearchResults( [] );
			setIsSearching( false );
			return;
		}

		setIsSearching( true );
		setError( null );

		try {
			// Build query params - include user location if available for better results
			let path = `/post-kinds-indieweb/v1/location/venues?query=${ encodeURIComponent(
				query
			) }`;

			if ( userLocation ) {
				path += `&lat=${ userLocation.lat }&lon=${ userLocation.lng }`;
			}

			const results = await apiFetch( { path } );
			setSearchResults( results || [] );
			setIsSearching( false );
		} catch ( err ) {
			// If Foursquare fails (not configured, rate limited, etc.), fall back to Nominatim
			// eslint-disable-next-line no-console
			console.log( 'Foursquare search failed, trying Nominatim:', err );
			try {
				const fallbackResults = await apiFetch( {
					path: `/post-kinds-indieweb/v1/location/search?query=${ encodeURIComponent(
						query
					) }`,
				} );
				setSearchResults( fallbackResults || [] );
				setIsSearching( false );
			} catch ( fallbackErr ) {
				// eslint-disable-next-line no-console
				console.error( 'Nominatim fallback also failed:', fallbackErr );
				setError(
					fallbackErr?.message ||
						__(
							'Search failed. Please try again.',
							'post-kinds-for-indieweb'
						)
				);
				setSearchResults( [] );
				setIsSearching( false );
			}
		}
	};

	/**
	 * Debounced venue search for typing in search box
	 */
	const searchVenues = useCallback(
		debounce( ( query ) => {
			doVenueSearch( query );
		}, 400 ),
		[ userLocation ]
	);

	/**
	 * Fetch nearby venues from Foursquare
	 *
	 * @param {number} lat Latitude.
	 * @param {number} lng Longitude.
	 */
	const fetchNearbyVenues = async ( lat, lng ) => {
		setIsLoadingNearby( true );
		setError( null );

		try {
			const results = await apiFetch( {
				path: `/post-kinds-indieweb/v1/location/venues?lat=${ lat }&lon=${ lng }`,
			} );

			setNearbyVenues( results || [] );
		} catch ( err ) {
			// Foursquare not configured - that's okay, just don't show nearby venues
			setNearbyVenues( [] );
		} finally {
			setIsLoadingNearby( false );
		}
	};

	/**
	 * Handle search input change
	 *
	 * @param {string} value Search input value.
	 */
	const handleSearchChange = ( value ) => {
		setSearchQuery( value );
		searchVenues( value );
	};

	/**
	 * Sidebar venue search - triggered by button click
	 */
	const doSidebarSearch = async () => {
		if ( ! sidebarSearchQuery || sidebarSearchQuery.trim().length < 2 ) {
			return;
		}

		setIsSidebarSearching( true );

		try {
			// Try Foursquare first
			const results = await apiFetch( {
				path: `/post-kinds-indieweb/v1/location/venues?query=${ encodeURIComponent(
					sidebarSearchQuery
				) }`,
			} );
			setSidebarSearchResults( results || [] );
		} catch ( err ) {
			// Fall back to Nominatim
			try {
				const fallbackResults = await apiFetch( {
					path: `/post-kinds-indieweb/v1/location/search?query=${ encodeURIComponent(
						sidebarSearchQuery
					) }`,
				} );
				setSidebarSearchResults( fallbackResults || [] );
			} catch ( fallbackErr ) {
				setSidebarSearchResults( [] );
			}
		} finally {
			setIsSidebarSearching( false );
		}
	};

	/**
	 * Handle sidebar venue selection
	 *
	 * @param {Object} result Selected venue.
	 */
	const selectSidebarVenue = ( result ) => {
		selectVenue( result );
		setSidebarSearchQuery( '' );
		setSidebarSearchResults( [] );
	};

	/**
	 * Use browser geolocation to get current location and show nearby venues
	 */
	const useCurrentLocation = async () => {
		if ( ! navigator.geolocation ) {
			setError(
				__(
					'Geolocation is not supported by your browser.',
					'post-kinds-for-indieweb'
				)
			);
			return;
		}

		setIsLocating( true );
		setError( null );

		navigator.geolocation.getCurrentPosition(
			async ( position ) => {
				const lat = position.coords.latitude;
				const lng = position.coords.longitude;

				// Store user location for search biasing
				setUserLocation( { lat, lng } );

				// Fetch nearby venues from Foursquare
				fetchNearbyVenues( lat, lng );

				// Show the search interface with nearby venues
				setShowSearch( true );
				setIsLocating( false );
			},
			( err ) => {
				setIsLocating( false );
				switch ( err.code ) {
					case err.PERMISSION_DENIED:
						setError(
							__(
								'Location access was denied.',
								'post-kinds-for-indieweb'
							)
						);
						break;
					case err.POSITION_UNAVAILABLE:
						setError(
							__(
								'Location information is unavailable.',
								'post-kinds-for-indieweb'
							)
						);
						break;
					case err.TIMEOUT:
						setError(
							__(
								'Location request timed out.',
								'post-kinds-for-indieweb'
							)
						);
						break;
					default:
						setError(
							__(
								'Could not detect your location.',
								'post-kinds-for-indieweb'
							)
						);
				}
			},
			{ enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
		);
	};

	/**
	 * Handle venue selection from search results
	 * Supports both Foursquare and Nominatim result formats
	 *
	 * @param {Object} result Venue search result object.
	 */
	const selectVenue = ( result ) => {
		// Check if this is a Foursquare result (has fsq_id) or Nominatim result
		const isFoursquare = !! result.fsq_id;

		if ( isFoursquare ) {
			// Foursquare result format
			setAttributes( {
				venueName: result.name || '',
				address: result.address || '',
				locality: result.locality || '',
				region: result.region || '',
				country: result.country || '',
				postalCode: result.postcode || '',
				latitude: result.latitude || null,
				longitude: result.longitude || null,
				foursquareId: result.fsq_id || '',
				venueUrl: result.website || '',
				venueType: mapFoursquareCategoryToType( result.category ),
			} );
		} else {
			// Nominatim result format
			const addr = result.address || {};

			setAttributes( {
				venueName:
					result.name || result.display_name?.split( ',' )[ 0 ] || '',
				address:
					[ addr.house_number, addr.road ]
						.filter( Boolean )
						.join( ' ' ) ||
					addr.road ||
					'',
				locality:
					addr.locality ||
					addr.city ||
					addr.town ||
					addr.village ||
					'',
				region: addr.region || addr.state || '',
				country: addr.country || '',
				postalCode: addr.postcode || '',
				latitude: result.latitude || parseFloat( result.lat ) || null,
				longitude: result.longitude || parseFloat( result.lon ) || null,
				osmId: result.osm_full_id || '',
				venueUrl: result.extra?.website || '',
			} );
		}

		// Clear search state
		setSearchQuery( '' );
		setSearchResults( [] );
		setNearbyVenues( [] );
		setShowSearch( false );
	};

	/**
	 * Map Foursquare category to our venue type
	 *
	 * @param {string} category Foursquare category name.
	 * @return {string} Our venue type.
	 */
	const mapFoursquareCategoryToType = ( category ) => {
		if ( ! category ) {
			return 'place';
		}
		const cat = category.toLowerCase();
		if ( cat.includes( 'restaurant' ) || cat.includes( 'food' ) ) {
			return 'restaurant';
		}
		if ( cat.includes( 'cafe' ) || cat.includes( 'coffee' ) ) {
			return 'cafe';
		}
		if (
			cat.includes( 'bar' ) ||
			cat.includes( 'pub' ) ||
			cat.includes( 'nightlife' )
		) {
			return 'bar';
		}
		if ( cat.includes( 'hotel' ) || cat.includes( 'lodging' ) ) {
			return 'hotel';
		}
		if ( cat.includes( 'airport' ) ) {
			return 'airport';
		}
		if ( cat.includes( 'park' ) || cat.includes( 'outdoor' ) ) {
			return 'park';
		}
		if ( cat.includes( 'museum' ) || cat.includes( 'gallery' ) ) {
			return 'museum';
		}
		if ( cat.includes( 'theater' ) || cat.includes( 'cinema' ) ) {
			return 'theater';
		}
		if (
			cat.includes( 'shop' ) ||
			cat.includes( 'store' ) ||
			cat.includes( 'retail' )
		) {
			return 'store';
		}
		if ( cat.includes( 'office' ) || cat.includes( 'business' ) ) {
			return 'office';
		}
		return 'place';
	};

	/**
	 * Handle privacy change with confirmation for public
	 *
	 * @param {string} newPrivacy New privacy setting value.
	 */
	const handlePrivacyChange = ( newPrivacy ) => {
		if ( newPrivacy === 'public' && locationPrivacy !== 'public' ) {
			// eslint-disable-next-line no-alert
			const confirmed = window.confirm(
				__(
					'You are about to make your precise location public. Are you sure?',
					'post-kinds-for-indieweb'
				)
			);
			if ( ! confirmed ) {
				return;
			}
		}
		setAttributes( { locationPrivacy: newPrivacy } );
	};

	/**
	 * Handle photo selection
	 *
	 * @param {Object} media Selected media object.
	 */
	const handlePhotoSelect = ( media ) => {
		setAttributes( {
			photo: media.url,
			photoAlt: media.alt || venueName,
		} );
	};

	/**
	 * Get venue type icon
	 */
	const getVenueIcon = () => {
		const icons = {
			place: '📍',
			restaurant: '🍽️',
			cafe: '☕',
			bar: '🍺',
			hotel: '🏨',
			airport: '✈️',
			park: '🌳',
			museum: '🏛️',
			theater: '🎭',
			store: '🛍️',
			office: '🏢',
			home: '🏠',
			other: '📌',
		};
		return icons[ venueType ] || icons.place;
	};

	/**
	 * Generate map URL for OpenStreetMap embed
	 */
	const getMapUrl = () => {
		if ( ! latitude || ! longitude ) {
			return null;
		}
		// Adjust bounding box based on privacy
		const bbox = locationPrivacy === 'public' ? 0.01 : 0.1;
		return `https://www.openstreetmap.org/export/embed.html?bbox=${
			longitude - bbox
		},${ latitude - bbox },${ longitude + bbox },${
			latitude + bbox
		}&layer=mapnik&marker=${ latitude },${ longitude }`;
	};

	/**
	 * Clear venue and start fresh
	 */
	const clearVenue = () => {
		setAttributes( {
			venueName: '',
			address: '',
			locality: '',
			region: '',
			country: '',
			postalCode: '',
			latitude: null,
			longitude: null,
			osmId: '',
		} );
		setShowSearch( true );
	};

	// Show placeholder if no venue info
	if ( ! venueName && ! locality && ! showSearch ) {
		return (
			<div { ...blockProps }>
				<BlockPlaceholder
					icon={ checkinIcon }
					label={ __( 'Checkin Card', 'post-kinds-for-indieweb' ) }
					instructions={ __(
						'Check in to a location. Use your current location or search for a venue.',
						'post-kinds-for-indieweb'
					) }
				>
					{ error && (
						<Notice
							status="error"
							isDismissible
							onDismiss={ () => setError( null ) }
						>
							{ error }
						</Notice>
					) }

					<div className="checkin-placeholder-actions">
						<Button
							variant="primary"
							onClick={ useCurrentLocation }
							disabled={ isLocating }
							icon={ isLocating ? null : 'location' }
						>
							{ isLocating ? (
								<>
									<Spinner />
									{ __(
										'Detecting…',
										'post-kinds-for-indieweb'
									) }
								</>
							) : (
								__(
									'Use Current Location',
									'post-kinds-for-indieweb'
								)
							) }
						</Button>

						<Button
							variant="secondary"
							onClick={ () => setShowSearch( true ) }
							icon="search"
						>
							{ __(
								'Search for Venue',
								'post-kinds-for-indieweb'
							) }
						</Button>

						<Button
							variant="tertiary"
							onClick={ () => setAttributes( { venueName: '' } ) }
						>
							{ __(
								'Enter Manually',
								'post-kinds-for-indieweb'
							) }
						</Button>
					</div>
				</BlockPlaceholder>
			</div>
		);
	}

	// Show search interface
	if ( showSearch && ! venueName ) {
		return (
			<div { ...blockProps }>
				<div className="checkin-search-state">
					<h3>
						{ userLocation
							? __(
									'Select a nearby venue or search',
									'post-kinds-for-indieweb'
							  )
							: __(
									'Search for a location',
									'post-kinds-for-indieweb'
							  ) }
					</h3>

					{ error && (
						<Notice
							status="error"
							isDismissible
							onDismiss={ () => setError( null ) }
						>
							{ error }
						</Notice>
					) }

					<div className="checkin-search-wrapper">
						<TextControl
							ref={ searchInputRef }
							value={ searchQuery }
							onChange={ handleSearchChange }
							placeholder={ __(
								'Search for a venue or address…',
								'post-kinds-for-indieweb'
							) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>

						{ isSearching && <Spinner /> }

						{ /* Show search results if searching */ }
						{ searchResults.length > 0 && (
							<ul
								className="checkin-search-results"
								role="listbox"
							>
								{ searchResults.map( ( result, index ) => (
									<li
										key={
											result.fsq_id ||
											result.place_id ||
											index
										}
									>
										<button
											type="button"
											className="checkin-result-item"
											onClick={ () =>
												selectVenue( result )
											}
										>
											<strong className="result-name">
												{ result.name ||
													result.display_name?.split(
														','
													)[ 0 ] }
											</strong>
											<span className="result-address">
												{ result.formatted_address ||
													result.display_name }
											</span>
											{ ( result.category ||
												result.type ) && (
												<span className="result-type">
													{ result.category ||
														result.type }
												</span>
											) }
											{ result.distance && (
												<span className="result-distance">
													{ result.distance < 1000
														? `${ result.distance }m`
														: `${ (
																result.distance /
																1000
														  ).toFixed( 1 ) }km` }
												</span>
											) }
										</button>
									</li>
								) ) }
							</ul>
						) }

						{ /* Show nearby venues when we have location but no search query */ }
						{ ! searchQuery &&
							nearbyVenues.length > 0 &&
							! isSearching && (
								<>
									<p className="nearby-venues-label">
										{ __(
											'Nearby venues:',
											'post-kinds-for-indieweb'
										) }
									</p>
									<ul
										className="checkin-search-results checkin-nearby-results"
										role="listbox"
									>
										{ nearbyVenues.map(
											( result, index ) => (
												<li
													key={
														result.fsq_id || index
													}
												>
													<button
														type="button"
														className="checkin-result-item"
														onClick={ () =>
															selectVenue(
																result
															)
														}
													>
														<strong className="result-name">
															{ result.name }
														</strong>
														<span className="result-address">
															{
																result.formatted_address
															}
														</span>
														{ result.category && (
															<span className="result-type">
																{
																	result.category
																}
															</span>
														) }
														{ result.distance && (
															<span className="result-distance">
																{ result.distance <
																1000
																	? `${ result.distance }m`
																	: `${ (
																			result.distance /
																			1000
																	  ).toFixed(
																			1
																	  ) }km` }
															</span>
														) }
													</button>
												</li>
											)
										) }
									</ul>
								</>
							) }

						{ isLoadingNearby && (
							<div className="loading-nearby">
								<Spinner />
								<span>
									{ __(
										'Finding nearby venues…',
										'post-kinds-for-indieweb'
									) }
								</span>
							</div>
						) }
					</div>

					<div className="checkin-search-actions">
						<Button
							variant="secondary"
							onClick={ useCurrentLocation }
							disabled={ isLocating }
						>
							{ isLocating
								? __( 'Detecting…', 'post-kinds-for-indieweb' )
								: __(
										'Use Current Location',
										'post-kinds-for-indieweb'
								  ) }
						</Button>

						<Button
							variant="tertiary"
							onClick={ () => {
								setShowSearch( false );
								setAttributes( { venueName: '' } );
							} }
						>
							{ __(
								'Enter Manually',
								'post-kinds-for-indieweb'
							) }
						</Button>

						<Button
							variant="link"
							onClick={ () => setShowSearch( false ) }
						>
							{ __( 'Cancel', 'post-kinds-for-indieweb' ) }
						</Button>
					</div>
				</div>
			</div>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Privacy Settings',
						'post-kinds-for-indieweb'
					) }
				>
					<RadioControl
						label={ __(
							'Location Privacy',
							'post-kinds-for-indieweb'
						) }
						help={ __(
							'Control how much location detail is shown publicly.',
							'post-kinds-for-indieweb'
						) }
						selected={ locationPrivacy || 'approximate' }
						options={ privacyOptions }
						onChange={ handlePrivacyChange }
					/>

					<div className="privacy-explanations">
						{ locationPrivacy === 'public' && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Your exact coordinates will be visible to everyone.',
									'post-kinds-for-indieweb'
								) }
							</Notice>
						) }
						{ locationPrivacy === 'approximate' && (
							<p className="description">
								{ __(
									'Only city/region will be shown. Coordinates are stored but not displayed.',
									'post-kinds-for-indieweb'
								) }
							</p>
						) }
						{ locationPrivacy === 'private' && (
							<p className="description">
								{ __(
									'Location is saved for your records but not shown publicly.',
									'post-kinds-for-indieweb'
								) }
							</p>
						) }
					</div>
				</PanelBody>

				<PanelBody
					title={ __( 'Venue Lookup', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<div className="venue-lookup-sidebar">
						<TextControl
							label={ __(
								'Search for venue',
								'post-kinds-for-indieweb'
							) }
							value={ sidebarSearchQuery }
							onChange={ setSidebarSearchQuery }
							placeholder={ __(
								'e.g. Denim Coffee, Chambersburg PA',
								'post-kinds-for-indieweb'
							) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' ) {
									e.preventDefault();
									doSidebarSearch();
								}
							} }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<Button
							variant="primary"
							onClick={ doSidebarSearch }
							disabled={ isSidebarSearching }
							style={ { marginBottom: '12px' } }
						>
							{ isSidebarSearching ? (
								<>
									<Spinner />
									{ __(
										'Searching…',
										'post-kinds-for-indieweb'
									) }
								</>
							) : (
								__( 'Look Up Venue', 'post-kinds-for-indieweb' )
							) }
						</Button>

						{ sidebarSearchResults.length > 0 && (
							<div className="sidebar-search-results">
								{ sidebarSearchResults
									.slice( 0, 5 )
									.map( ( result, index ) => (
										<Button
											key={
												result.fsq_id ||
												result.place_id ||
												index
											}
											variant="secondary"
											onClick={ () =>
												selectSidebarVenue( result )
											}
											className="sidebar-result-button"
											style={ {
												display: 'block',
												width: '100%',
												textAlign: 'left',
												marginBottom: '4px',
												whiteSpace: 'normal',
												height: 'auto',
												padding: '8px',
											} }
										>
											<strong>
												{ result.name ||
													result.display_name?.split(
														','
													)[ 0 ] }
											</strong>
											<br />
											<small style={ { opacity: 0.7 } }>
												{ result.formatted_address ||
													result.display_name }
											</small>
										</Button>
									) ) }
							</div>
						) }
					</div>
				</PanelBody>

				<PanelBody
					title={ __( 'Venue Details', 'post-kinds-for-indieweb' ) }
					initialOpen={ !! venueName }
				>
					<TextControl
						label={ __( 'Venue Name', 'post-kinds-for-indieweb' ) }
						value={ venueName || '' }
						onChange={ ( value ) =>
							setAttributes( { venueName: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Venue Type', 'post-kinds-for-indieweb' ) }
						value={ venueType }
						options={ venueTypes }
						onChange={ ( value ) =>
							setAttributes( { venueType: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __(
							'Street Address',
							'post-kinds-for-indieweb'
						) }
						value={ address || '' }
						onChange={ ( value ) =>
							setAttributes( { address: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __(
							'City/Locality',
							'post-kinds-for-indieweb'
						) }
						value={ locality || '' }
						onChange={ ( value ) =>
							setAttributes( { locality: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __(
							'State/Region',
							'post-kinds-for-indieweb'
						) }
						value={ region || '' }
						onChange={ ( value ) =>
							setAttributes( { region: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Country', 'post-kinds-for-indieweb' ) }
						value={ country || '' }
						onChange={ ( value ) =>
							setAttributes( { country: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Postal Code', 'post-kinds-for-indieweb' ) }
						value={ postalCode || '' }
						onChange={ ( value ) =>
							setAttributes( { postalCode: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>

					<Button
						variant="secondary"
						onClick={ () => setShowSearch( true ) }
						style={ { marginTop: '12px' } }
					>
						{ __(
							'Search Different Location',
							'post-kinds-for-indieweb'
						) }
					</Button>
				</PanelBody>

				<PanelBody
					title={ __( 'Coordinates', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Latitude', 'post-kinds-for-indieweb' ) }
						value={ latitude || '' }
						onChange={ ( value ) =>
							setAttributes( {
								latitude: parseFloat( value ) || null,
							} )
						}
						type="number"
						step="any"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Longitude', 'post-kinds-for-indieweb' ) }
						value={ longitude || '' }
						onChange={ ( value ) =>
							setAttributes( {
								longitude: parseFloat( value ) || null,
							} )
						}
						type="number"
						step="any"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<ToggleControl
						label={ __( 'Show Map', 'post-kinds-for-indieweb' ) }
						checked={ showMap }
						onChange={ ( value ) =>
							setAttributes( { showMap: value } )
						}
						help={
							locationPrivacy === 'private'
								? __(
										'Map is hidden when privacy is set to private.',
										'post-kinds-for-indieweb'
								  )
								: __(
										'Display an embedded OpenStreetMap.',
										'post-kinds-for-indieweb'
								  )
						}
						disabled={ locationPrivacy === 'private' }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Checkin Details', 'post-kinds-for-indieweb' ) }
				>
					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __( 'Checkin Time', 'post-kinds-for-indieweb' ) }
						</span>
						<Button
							variant="secondary"
							onClick={ () => setShowDatePicker( true ) }
							aria-label={ __(
								'Set checkin time',
								'post-kinds-for-indieweb'
							) }
						>
							{ checkinAt
								? new Date( checkinAt ).toLocaleString()
								: __( 'Set time', 'post-kinds-for-indieweb' ) }
						</Button>
						{ showDatePicker && (
							<Popover
								onClose={ () => setShowDatePicker( false ) }
							>
								<DateTimePicker
									currentDate={ checkinAt }
									onChange={ ( value ) => {
										setAttributes( { checkinAt: value } );
										setShowDatePicker( false );
									} }
								/>
							</Popover>
						) }
					</div>
				</PanelBody>

				<PanelBody title={ __( 'Layout', 'post-kinds-for-indieweb' ) }>
					<SelectControl
						label={ __(
							'Layout Style',
							'post-kinds-for-indieweb'
						) }
						value={ layout }
						options={ [
							{
								label: __(
									'Horizontal',
									'post-kinds-for-indieweb'
								),
								value: 'horizontal',
							},
							{
								label: __(
									'Vertical',
									'post-kinds-for-indieweb'
								),
								value: 'vertical',
							},
							{
								label: __(
									'Map Focus',
									'post-kinds-for-indieweb'
								),
								value: 'map',
							},
							{
								label: __(
									'Compact',
									'post-kinds-for-indieweb'
								),
								value: 'compact',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Links', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Venue URL', 'post-kinds-for-indieweb' ) }
						value={ venueUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { venueUrl: value } )
						}
						type="url"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __(
							'Foursquare ID',
							'post-kinds-for-indieweb'
						) }
						value={ foursquareId || '' }
						onChange={ ( value ) =>
							setAttributes( { foursquareId: value } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ osmId && (
						<p className="description">
							{ __( 'OSM ID:', 'post-kinds-for-indieweb' ) }{ ' ' }
							{ osmId }
						</p>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card h-entry">
					{ /* Photo */ }
					<div className="checkin-photo">
						<MediaUploadCheck>
							<MediaUpload
								onSelect={ handlePhotoSelect }
								allowedTypes={ [ 'image' ] }
								render={ ( { open } ) => (
									<div
										onClick={ open }
										onKeyDown={ ( e ) => {
											if (
												e.key === 'Enter' ||
												e.key === ' '
											) {
												e.preventDefault();
												open();
											}
										} }
										role="button"
										tabIndex={ 0 }
									>
										{ photo ? (
											<img
												src={ photo }
												alt={ photoAlt }
												className="u-photo"
											/>
										) : (
											<div className="photo-placeholder">
												<span className="venue-icon">
													{ getVenueIcon() }
												</span>
												<span>
													{ __(
														'Add photo',
														'post-kinds-for-indieweb'
													) }
												</span>
											</div>
										) }
									</div>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="checkin-info">
						<div className="checkin-header">
							<span className="venue-type-badge">
								<span className="venue-icon">
									{ getVenueIcon() }
								</span>
								{
									venueTypes.find(
										( t ) => t.value === venueType
									)?.label
								}
							</span>

							{ locationPrivacy === 'private' && (
								<span className="privacy-badge private">
									{ __(
										'Private',
										'post-kinds-for-indieweb'
									) }
								</span>
							) }
							{ locationPrivacy === 'approximate' && (
								<span className="privacy-badge approximate">
									{ __(
										'Approximate',
										'post-kinds-for-indieweb'
									) }
								</span>
							) }
						</div>

						<RichText
							tagName="h3"
							className="venue-name p-name"
							value={ venueName }
							onChange={ ( value ) =>
								setAttributes( { venueName: value } )
							}
							placeholder={ __(
								'Venue name',
								'post-kinds-for-indieweb'
							) }
						/>

						<div className="venue-location p-location h-card">
							<LocationDisplay
								address={
									locationPrivacy === 'public' ? address : ''
								}
								locality={ locality }
								region={ region }
								country={ country }
							/>
						</div>

						{ checkinAt && (
							<time
								className="checkin-time dt-published"
								dateTime={ new Date( checkinAt ).toISOString() }
							>
								{ new Date( checkinAt ).toLocaleString() }
							</time>
						) }

						<RichText
							tagName="p"
							className="checkin-note p-content"
							value={ note }
							onChange={ ( value ) =>
								setAttributes( { note: value } )
							}
							placeholder={ __(
								'Add a note about this checkin…',
								'post-kinds-for-indieweb'
							) }
						/>

						<Button
							variant="link"
							isDestructive
							onClick={ clearVenue }
							className="change-venue-button"
						>
							{ __( 'Change venue', 'post-kinds-for-indieweb' ) }
						</Button>
					</div>

					{ /* Map preview - hidden for private */ }
					{ showMap &&
						latitude &&
						longitude &&
						locationPrivacy !== 'private' && (
							<div className="checkin-map">
								<iframe
									title={ __(
										'Location map',
										'post-kinds-for-indieweb'
									) }
									width="100%"
									height="200"
									frameBorder="0"
									scrolling="no"
									marginHeight="0"
									marginWidth="0"
									src={ getMapUrl() }
								/>
								{ locationPrivacy === 'approximate' && (
									<p className="map-note">
										{ __(
											'Showing approximate area. Exact location hidden.',
											'post-kinds-for-indieweb'
										) }
									</p>
								) }
							</div>
						) }

					{ locationPrivacy === 'private' &&
						latitude &&
						longitude && (
							<div className="checkin-private-notice">
								<span className="dashicons dashicons-lock"></span>
								{ __(
									'Location saved privately',
									'post-kinds-for-indieweb'
								) }
							</div>
						) }
				</div>
			</div>
		</>
	);
}
