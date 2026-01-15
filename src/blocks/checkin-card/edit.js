/**
 * Checkin Card Block - Edit Component
 *
 * Enhanced with location search, geolocation, and privacy controls.
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
import { useState, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { checkinIcon } from '../shared/icons';
import { BlockPlaceholder, LocationDisplay } from '../shared/components';

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

	const searchInputRef = useRef( null );

	const blockProps = useBlockProps( {
		className: `checkin-card layout-${ layout }`,
	} );

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
	 * Search for venues using the REST API proxy
	 */
	const searchVenues = useCallback(
		debounce( async ( query ) => {
			if ( ! query || query.trim().length < 3 ) {
				setSearchResults( [] );
				return;
			}

			setIsSearching( true );
			setError( null );

			try {
				const results = await apiFetch( {
					path: `/post-kinds-indieweb/v1/location/search?query=${ encodeURIComponent(
						query
					) }`,
				} );

				setSearchResults( results || [] );
			} catch ( err ) {
				setError(
					err.message ||
						__(
							'Search failed. Please try again.',
							'post-kinds-for-indieweb'
						)
				);
				setSearchResults( [] );
			} finally {
				setIsSearching( false );
			}
		}, 500 ),
		[]
	);

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
	 * Use browser geolocation to get current location
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

				try {
					// Reverse geocode via REST API proxy
					const result = await apiFetch( {
						path: `/post-kinds-indieweb/v1/location/reverse?lat=${ lat }&lon=${ lng }`,
					} );

					if ( result ) {
						selectVenue( result );
					} else {
						// Just set coords if reverse geocode failed
						setAttributes( {
							latitude: lat,
							longitude: lng,
							venueName: __(
								'Current Location',
								'post-kinds-for-indieweb'
							),
						} );
					}
				} catch ( err ) {
					// Still set coordinates even if reverse geocode fails
					setAttributes( {
						latitude: lat,
						longitude: lng,
						venueName: __(
							'Current Location',
							'post-kinds-for-indieweb'
						),
					} );
				} finally {
					setIsLocating( false );
					setShowSearch( false );
				}
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
	 *
	 * @param {Object} result Venue search result object.
	 */
	const selectVenue = ( result ) => {
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
				addr.locality || addr.city || addr.town || addr.village || '',
			region: addr.region || addr.state || '',
			country: addr.country || '',
			postalCode: addr.postcode || '',
			latitude: result.latitude || parseFloat( result.lat ) || null,
			longitude: result.longitude || parseFloat( result.lon ) || null,
			osmId: result.osm_full_id || '',
			venueUrl: result.extra?.website || '',
		} );

		// Clear search state
		setSearchQuery( '' );
		setSearchResults( [] );
		setShowSearch( false );
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
						{ __(
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
						/>

						{ isSearching && <Spinner /> }

						{ searchResults.length > 0 && (
							<ul
								className="checkin-search-results"
								role="listbox"
							>
								{ searchResults.map( ( result, index ) => (
									<li key={ result.place_id || index }>
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
											{ result.type && (
												<span className="result-type">
													{ result.type }
												</span>
											) }
										</button>
									</li>
								) ) }
							</ul>
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
					title={ __( 'Venue Details', 'post-kinds-for-indieweb' ) }
				>
					<TextControl
						label={ __( 'Venue Name', 'post-kinds-for-indieweb' ) }
						value={ venueName || '' }
						onChange={ ( value ) =>
							setAttributes( { venueName: value } )
						}
					/>
					<SelectControl
						label={ __( 'Venue Type', 'post-kinds-for-indieweb' ) }
						value={ venueType }
						options={ venueTypes }
						onChange={ ( value ) =>
							setAttributes( { venueType: value } )
						}
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
					/>
					<TextControl
						label={ __( 'Country', 'post-kinds-for-indieweb' ) }
						value={ country || '' }
						onChange={ ( value ) =>
							setAttributes( { country: value } )
						}
					/>
					<TextControl
						label={ __( 'Postal Code', 'post-kinds-for-indieweb' ) }
						value={ postalCode || '' }
						onChange={ ( value ) =>
							setAttributes( { postalCode: value } )
						}
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
									🔒{ ' ' }
									{ __(
										'Private',
										'post-kinds-for-indieweb'
									) }
								</span>
							) }
							{ locationPrivacy === 'approximate' && (
								<span className="privacy-badge approximate">
									📍{ ' ' }
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
