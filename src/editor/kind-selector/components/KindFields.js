/**
 * Post Kinds for IndieWeb - Kind Fields Component
 *
 * Displays kind-specific metadata fields based on the selected post kind.
 *
 * @package
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	RangeControl,
	Button,
	Spinner,
	BaseControl,
	ExternalLink,
	Flex,
} from '@wordpress/components';
import { useState, useCallback, useId } from '@wordpress/element';
import { search as searchIcon, link as linkIcon } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { STORE_NAME } from '../../stores/post-kinds';
import SyndicationControls from './SyndicationControls';

/**
 * Kind Fields Component
 *
 * Renders appropriate metadata fields based on the selected kind.
 *
 * @param {Object} props      Component props.
 * @param {string} props.kind Current kind slug.
 * @return {JSX.Element|null} The fields component or null.
 */
export default function KindFields( { kind } ) {
	switch ( kind ) {
		case 'rsvp':
			return <RSVPFields />;
		case 'checkin':
			return <CheckinFields />;
		case 'listen':
			return <ListenFields />;
		case 'watch':
			return <WatchFields />;
		case 'read':
			return <ReadFields />;
		case 'event':
			return <EventFields />;
		case 'review':
			return <ReviewFields />;
		case 'play':
			return <PlayFields />;
		case 'eat':
			return <EatFields />;
		case 'drink':
			return <DrinkFields />;
		case 'favorite':
			return <FavoriteFields />;
		case 'jam':
			return <JamFields />;
		case 'wish':
			return <WishFields />;
		case 'mood':
			return <MoodFields />;
		case 'acquisition':
			return <AcquisitionFields />;
		case 'recipe':
			return <RecipeFields />;
		case 'reply':
		case 'like':
		case 'repost':
			return <CitationFields />;
		case 'bookmark':
			return <BookmarkFields />;
		default:
			return null;
	}
}

/**
 * Citation Fields Component
 *
 * Fields for reply, like, repost, and bookmark kinds.
 *
 * @return {JSX.Element} Citation fields.
 */
function CitationFields() {
	const { citeName, citeUrl, citeAuthor, citeSummary } = useSelect(
		( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				citeName: getKindMeta( 'cite_name' ),
				citeUrl: getKindMeta( 'cite_url' ),
				citeAuthor: getKindMeta( 'cite_author' ),
				citeSummary: getKindMeta( 'cite_summary' ),
			};
		},
		[]
	);

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'URL', 'post-kinds-for-indieweb' ) }
				value={ citeUrl }
				onChange={ ( value ) => updateKindMeta( 'cite_url', value ) }
				type="url"
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Title', 'post-kinds-for-indieweb' ) }
				value={ citeName }
				onChange={ ( value ) => updateKindMeta( 'cite_name', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Author', 'post-kinds-for-indieweb' ) }
				value={ citeAuthor }
				onChange={ ( value ) => updateKindMeta( 'cite_author', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextareaControl
				label={ __( 'Summary', 'post-kinds-for-indieweb' ) }
				value={ citeSummary }
				onChange={ ( value ) =>
					updateKindMeta( 'cite_summary', value )
				}
				rows={ 3 }
				__nextHasNoMarginBottom
			/>
		</Flex>
	);
}

/**
 * Bookmark Fields Component
 *
 * Extended citation fields for bookmarks with embed type selection.
 * Supports oEmbed (core/embed) or Bookmark Card plugin.
 *
 * @return {JSX.Element} Bookmark fields.
 */
function BookmarkFields() {
	const [ isCheckingEmbed, setIsCheckingEmbed ] = useState( false );
	const [ embedSupport, setEmbedSupport ] = useState( null );

	const { citeName, citeUrl, citeAuthor, citeSummary, bookmarkEmbedType } =
		useSelect( ( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				citeName: getKindMeta( 'cite_name' ),
				citeUrl: getKindMeta( 'cite_url' ),
				citeAuthor: getKindMeta( 'cite_author' ),
				citeSummary: getKindMeta( 'cite_summary' ),
				bookmarkEmbedType:
					getKindMeta( 'bookmark_embed_type' ) || 'auto',
			};
		}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	const bookmarkCardActive =
		window.postKindsIndieWebEditor?.bookmarkCardActive;

	// Check if URL has oEmbed support.
	const checkEmbedSupport = useCallback( async ( url ) => {
		if ( ! url || ! url.startsWith( 'http' ) ) {
			setEmbedSupport( null );
			return;
		}

		setIsCheckingEmbed( true );
		try {
			const response = await apiFetch( {
				path: `/post-kinds-indieweb/v1/check-oembed?url=${ encodeURIComponent(
					url
				) }`,
			} );
			setEmbedSupport( response );
		} catch {
			setEmbedSupport( { supported: false } );
		}
		setIsCheckingEmbed( false );
	}, [] );

	// Build embed type options based on what's available.
	const embedTypeOptions = [
		{
			label: __(
				'Auto (oEmbed first, then fallback)',
				'post-kinds-for-indieweb'
			),
			value: 'auto',
		},
		{
			label: __(
				'oEmbed (YouTube, Twitter, etc.)',
				'post-kinds-for-indieweb'
			),
			value: 'oembed',
		},
	];

	if ( bookmarkCardActive ) {
		embedTypeOptions.push( {
			label: __(
				'Bookmark Card (link preview)',
				'post-kinds-for-indieweb'
			),
			value: 'bookmark-card',
		} );
	}

	embedTypeOptions.push( {
		label: __( 'None (metadata only)', 'post-kinds-for-indieweb' ),
		value: 'none',
	} );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'URL', 'post-kinds-for-indieweb' ) }
				value={ citeUrl }
				onChange={ ( value ) => {
					updateKindMeta( 'cite_url', value );
					checkEmbedSupport( value );
				} }
				type="url"
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ isCheckingEmbed && (
				<Flex>
					<Spinner />
					<span>
						{ __(
							'Checking embed support…',
							'post-kinds-for-indieweb'
						) }
					</span>
				</Flex>
			) }

			{ embedSupport && ! isCheckingEmbed && (
				<div className="post-kinds-indieweb-embed-status">
					{ embedSupport.supported ? (
						<span style={ { color: 'green' } }>
							{ __(
								'oEmbed supported',
								'post-kinds-for-indieweb'
							) }
							{ embedSupport.provider &&
								` (${ embedSupport.provider })` }
						</span>
					) : (
						<span style={ { color: '#666' } }>
							{ __(
								'No oEmbed support',
								'post-kinds-for-indieweb'
							) }
							{ bookmarkCardActive &&
								' - ' +
									__(
										'will use Bookmark Card',
										'post-kinds-for-indieweb'
									) }
						</span>
					) }
				</div>
			) }

			<SelectControl
				label={ __( 'Embed Type', 'post-kinds-for-indieweb' ) }
				value={ bookmarkEmbedType }
				options={ embedTypeOptions }
				onChange={ ( value ) =>
					updateKindMeta( 'bookmark_embed_type', value )
				}
				help={ __(
					'Choose how the bookmark URL should be displayed in the post.',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ __( 'Title', 'post-kinds-for-indieweb' ) }
				value={ citeName }
				onChange={ ( value ) => updateKindMeta( 'cite_name', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Author', 'post-kinds-for-indieweb' ) }
				value={ citeAuthor }
				onChange={ ( value ) => updateKindMeta( 'cite_author', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextareaControl
				label={ __( 'Summary', 'post-kinds-for-indieweb' ) }
				value={ citeSummary }
				onChange={ ( value ) =>
					updateKindMeta( 'cite_summary', value )
				}
				rows={ 3 }
				__nextHasNoMarginBottom
			/>
		</Flex>
	);
}

/**
 * RSVP Fields Component
 *
 * @return {JSX.Element} RSVP fields.
 */
function RSVPFields() {
	const { rsvpStatus, citeUrl, citeName } = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			rsvpStatus: getKindMeta( 'rsvp_status' ),
			citeUrl: getKindMeta( 'cite_url' ),
			citeName: getKindMeta( 'cite_name' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'Event URL', 'post-kinds-for-indieweb' ) }
				value={ citeUrl }
				onChange={ ( value ) => updateKindMeta( 'cite_url', value ) }
				type="url"
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Event Name', 'post-kinds-for-indieweb' ) }
				value={ citeName }
				onChange={ ( value ) => updateKindMeta( 'cite_name', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'RSVP Status', 'post-kinds-for-indieweb' ) }
				value={ rsvpStatus }
				onChange={ ( value ) => updateKindMeta( 'rsvp_status', value ) }
				options={ [
					{
						label: __(
							'Select status…',
							'post-kinds-for-indieweb'
						),
						value: '',
					},
					{
						label: __(
							'✅ Yes, attending',
							'post-kinds-for-indieweb'
						),
						value: 'yes',
					},
					{
						label: __(
							"❌ No, can't make it",
							'post-kinds-for-indieweb'
						),
						value: 'no',
					},
					{
						label: __( '🤔 Maybe', 'post-kinds-for-indieweb' ),
						value: 'maybe',
					},
					{
						label: __( '👀 Interested', 'post-kinds-for-indieweb' ),
						value: 'interested',
					},
				] }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Checkin Fields Component
 *
 * @return {JSX.Element} Checkin fields.
 */
function CheckinFields() {
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchResults, setSearchResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );

	const {
		checkinName,
		checkinAddress,
		checkinLocality,
		checkinRegion,
		checkinCountry,
		geoLatitude,
		geoLongitude,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			checkinName: getKindMeta( 'checkin_name' ),
			checkinAddress: getKindMeta( 'checkin_address' ),
			checkinLocality: getKindMeta( 'checkin_locality' ),
			checkinRegion: getKindMeta( 'checkin_region' ),
			checkinCountry: getKindMeta( 'checkin_country' ),
			geoLatitude: getKindMeta( 'geo_latitude' ),
			geoLongitude: getKindMeta( 'geo_longitude' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	/**
	 * Search for venues using Foursquare/Nominatim
	 */
	const doVenueSearch = async () => {
		if ( ! searchQuery || searchQuery.trim().length < 2 ) {
			return;
		}

		setIsSearching( true );

		// Set a timeout to stop searching after 15 seconds
		const timeoutId = setTimeout( () => {
			// eslint-disable-next-line no-console
			console.error( 'Venue search timed out after 15 seconds' );
			setIsSearching( false );
			setSearchResults( [] );
		}, 15000 );

		try {
			// Try Foursquare first
			const results = await apiFetch( {
				path: `/post-kinds-indieweb/v1/location/venues?query=${ encodeURIComponent(
					searchQuery
				) }`,
			} );
			clearTimeout( timeoutId );
			// eslint-disable-next-line no-console
			console.log( 'Foursquare results:', results );
			setSearchResults( results || [] );
			setIsSearching( false );
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.log( 'Foursquare failed, trying Nominatim:', err );
			// Fall back to Nominatim
			try {
				const fallbackResults = await apiFetch( {
					path: `/post-kinds-indieweb/v1/location/search?query=${ encodeURIComponent(
						searchQuery
					) }`,
				} );
				clearTimeout( timeoutId );
				// eslint-disable-next-line no-console
				console.log( 'Nominatim results:', fallbackResults );
				setSearchResults( fallbackResults || [] );
				setIsSearching( false );
			} catch ( fallbackErr ) {
				clearTimeout( timeoutId );
				// eslint-disable-next-line no-console
				console.error(
					'Both Foursquare and Nominatim failed:',
					fallbackErr
				);
				setSearchResults( [] );
				setIsSearching( false );
			}
		}
	};

	/**
	 * Select a venue from search results
	 *
	 * @param {Object} result Selected venue.
	 */
	const selectVenue = ( result ) => {
		// Auto-fill all venue fields from Foursquare or Nominatim result
		updateKindMeta(
			'checkin_name',
			result.name || result.display_name?.split( ',' )[ 0 ] || ''
		);
		updateKindMeta(
			'checkin_address',
			result.address || result.street || ''
		);
		updateKindMeta(
			'checkin_locality',
			result.locality || result.city || ''
		);
		updateKindMeta( 'checkin_region', result.region || result.state || '' );
		updateKindMeta( 'checkin_country', result.country || '' );

		if ( result.latitude || result.lat ) {
			updateKindMeta(
				'geo_latitude',
				parseFloat( result.latitude || result.lat ) || 0
			);
		}
		if ( result.longitude || result.lon ) {
			updateKindMeta(
				'geo_longitude',
				parseFloat( result.longitude || result.lon ) || 0
			);
		}

		// Clear search
		setSearchQuery( '' );
		setSearchResults( [] );
	};

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<BaseControl
				id="checkin-venue-search"
				label={ __( 'Look Up Venue', 'post-kinds-for-indieweb' ) }
				__nextHasNoMarginBottom
			>
				<Flex>
					<TextControl
						value={ searchQuery }
						onChange={ setSearchQuery }
						placeholder={ __(
							'Search venues…',
							'post-kinds-for-indieweb'
						) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								doVenueSearch();
							}
						} }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						variant="secondary"
						onClick={ doVenueSearch }
						disabled={ isSearching }
						icon={ searchIcon }
					>
						{ isSearching ? (
							<Spinner />
						) : (
							__( 'Search', 'post-kinds-for-indieweb' )
						) }
					</Button>
				</Flex>
			</BaseControl>

			{ searchResults.length > 0 && (
				<div
					className="venue-search-results"
					style={ {
						maxHeight: '200px',
						overflowY: 'auto',
						border: '1px solid #ddd',
						borderRadius: '4px',
					} }
				>
					{ searchResults.slice( 0, 5 ).map( ( result, index ) => (
						<Button
							key={ result.fsq_id || result.place_id || index }
							variant="tertiary"
							onClick={ () => selectVenue( result ) }
							style={ {
								display: 'block',
								width: '100%',
								textAlign: 'left',
								padding: '8px',
							} }
						>
							<strong>
								{ result.name ||
									result.display_name?.split( ',' )[ 0 ] }
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

			<TextControl
				label={ __( 'Venue Name', 'post-kinds-for-indieweb' ) }
				value={ checkinName }
				onChange={ ( value ) =>
					updateKindMeta( 'checkin_name', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Address', 'post-kinds-for-indieweb' ) }
				value={ checkinAddress }
				onChange={ ( value ) =>
					updateKindMeta( 'checkin_address', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<Flex>
				<TextControl
					label={ __( 'City', 'post-kinds-for-indieweb' ) }
					value={ checkinLocality }
					onChange={ ( value ) =>
						updateKindMeta( 'checkin_locality', value )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					label={ __( 'State/Region', 'post-kinds-for-indieweb' ) }
					value={ checkinRegion }
					onChange={ ( value ) =>
						updateKindMeta( 'checkin_region', value )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</Flex>
			<TextControl
				label={ __( 'Country', 'post-kinds-for-indieweb' ) }
				value={ checkinCountry }
				onChange={ ( value ) =>
					updateKindMeta( 'checkin_country', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<Flex>
				<TextControl
					label={ __( 'Latitude', 'post-kinds-for-indieweb' ) }
					value={ geoLatitude }
					onChange={ ( value ) =>
						updateKindMeta(
							'geo_latitude',
							parseFloat( value ) || 0
						)
					}
					type="number"
					step="0.0000001"
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					label={ __( 'Longitude', 'post-kinds-for-indieweb' ) }
					value={ geoLongitude }
					onChange={ ( value ) =>
						updateKindMeta(
							'geo_longitude',
							parseFloat( value ) || 0
						)
					}
					type="number"
					step="0.0000001"
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</Flex>
			<SyndicationControls kind="checkin" />
		</Flex>
	);
}

/**
 * Listen Fields Component
 *
 * @return {JSX.Element} Listen fields.
 */
function ListenFields() {
	const musicUrlId = useId();
	const musicSearchId = useId();
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ urlInput, setUrlInput ] = useState( '' );
	const [ isUrlLoading, setIsUrlLoading ] = useState( false );
	const [ urlError, setUrlError ] = useState( '' );

	const {
		listenTrack,
		listenArtist,
		listenAlbum,
		listenCover,
		listenUrl,
		isLoading,
		apiResults,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		const store = select( STORE_NAME );
		return {
			listenTrack: getKindMeta( 'listen_track' ),
			listenArtist: getKindMeta( 'listen_artist' ),
			listenAlbum: getKindMeta( 'listen_album' ),
			listenCover: getKindMeta( 'listen_cover' ),
			listenUrl: getKindMeta( 'listen_url' ),
			isLoading: store.isApiLoading(),
			apiResults:
				store.getApiLookupType() === 'music'
					? store.getApiResults()
					: [],
		};
	}, [] );

	const { updateKindMeta, performApiLookup, clearApiResults } =
		useDispatch( STORE_NAME );

	// Check if input looks like a URL.
	const isUrl = useCallback( ( input ) => {
		return /^https?:\/\//i.test( input.trim() );
	}, [] );

	// Handle URL lookup.
	const handleUrlLookup = useCallback( async () => {
		const url = urlInput.trim();
		if ( ! url ) {
			return;
		}

		setIsUrlLoading( true );
		setUrlError( '' );

		try {
			const result = await apiFetch( {
				path: `/post-kinds-indieweb/v1/lookup/music-url?url=${ encodeURIComponent(
					url
				) }`,
			} );

			// Update metadata from result.
			if ( result.track ) {
				updateKindMeta( 'listen_track', result.track );
			}
			if ( result.artist ) {
				updateKindMeta( 'listen_artist', result.artist );
			}
			if ( result.album ) {
				updateKindMeta( 'listen_album', result.album );
			}
			if ( result.cover ) {
				updateKindMeta( 'listen_cover', result.cover );
			}
			// Store the URL for embedding.
			updateKindMeta( 'listen_url', result.url || url );

			setUrlInput( '' );
		} catch ( error ) {
			setUrlError(
				error.message ||
					__(
						'Could not fetch track info from URL.',
						'post-kinds-for-indieweb'
					)
			);
		} finally {
			setIsUrlLoading( false );
		}
	}, [ urlInput, updateKindMeta ] );

	const handleSearch = useCallback( () => {
		const query = searchQuery.trim();
		if ( ! query ) {
			return;
		}

		// If it looks like a URL, do URL lookup instead.
		if ( isUrl( query ) ) {
			setUrlInput( query );
			setSearchQuery( '' );
			handleUrlLookup();
			return;
		}

		performApiLookup( 'music', query );
	}, [ searchQuery, isUrl, performApiLookup, handleUrlLookup ] );

	const handleSelectResult = useCallback(
		( result ) => {
			updateKindMeta( 'listen_track', result.track );
			updateKindMeta( 'listen_artist', result.artist );
			updateKindMeta( 'listen_album', result.album );
			updateKindMeta( 'listen_cover', result.cover );
			updateKindMeta( 'listen_mbid', result.mbid );
			clearApiResults();
			setSearchQuery( '' );
		},
		[ updateKindMeta, clearApiResults ]
	);

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			{ /* URL Input for Spotify, Apple Music, etc. */ }
			<BaseControl
				id={ musicUrlId }
				label={ __( 'Paste Music URL', 'post-kinds-for-indieweb' ) }
				help={ __(
					'Spotify, Apple Music, YouTube, SoundCloud',
					'post-kinds-for-indieweb'
				) }
			>
				<Flex>
					<TextControl
						value={ urlInput }
						onChange={ setUrlInput }
						placeholder="https://open.spotify.com/track/..."
						onKeyDown={ ( e ) =>
							e.key === 'Enter' && handleUrlLookup()
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						icon={ linkIcon }
						onClick={ handleUrlLookup }
						disabled={ isUrlLoading || ! urlInput.trim() }
						label={ __( 'Fetch', 'post-kinds-for-indieweb' ) }
					/>
				</Flex>
				{ urlError && (
					<p
						style={ {
							color: '#d63638',
							fontSize: '12px',
							marginTop: '4px',
						} }
					>
						{ urlError }
					</p>
				) }
			</BaseControl>

			{ isUrlLoading && <Spinner /> }

			{ /* Saved URL display */ }
			{ listenUrl && (
				<div
					style={ {
						padding: '8px 12px',
						backgroundColor: '#f0f6fc',
						border: '1px solid #c3c4c7',
						borderRadius: '2px',
						fontSize: '12px',
						wordBreak: 'break-all',
					} }
				>
					<strong>
						{ __( 'Linked:', 'post-kinds-for-indieweb' ) }
					</strong>{ ' ' }
					<a
						href={ listenUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ listenUrl }
					</a>
				</div>
			) }

			{ /* Or search by name */ }
			<BaseControl
				id={ musicSearchId }
				label={ __( 'Or Search by Name', 'post-kinds-for-indieweb' ) }
			>
				<Flex>
					<TextControl
						value={ searchQuery }
						onChange={ setSearchQuery }
						placeholder={ __(
							'Track name or artist…',
							'post-kinds-for-indieweb'
						) }
						onKeyDown={ ( e ) =>
							e.key === 'Enter' && handleSearch()
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						icon={ searchIcon }
						onClick={ handleSearch }
						disabled={ isLoading }
						label={ __( 'Search', 'post-kinds-for-indieweb' ) }
					/>
				</Flex>
			</BaseControl>

			{ isLoading && <Spinner /> }

			{ apiResults.length > 0 && (
				<div className="post-kinds-indieweb-api-results">
					{ apiResults.slice( 0, 5 ).map( ( result, index ) => (
						<Button
							key={ index }
							className="post-kinds-indieweb-api-result"
							onClick={ () => handleSelectResult( result ) }
						>
							{ result.cover && (
								<img
									src={ result.cover }
									alt=""
									width="40"
									height="40"
								/>
							) }
							<span>
								<strong>{ result.track }</strong>
								<br />
								{ result.artist } — { result.album }
							</span>
						</Button>
					) ) }
				</div>
			) }

			<TextControl
				label={ __( 'Track', 'post-kinds-for-indieweb' ) }
				value={ listenTrack }
				onChange={ ( value ) =>
					updateKindMeta( 'listen_track', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Artist', 'post-kinds-for-indieweb' ) }
				value={ listenArtist }
				onChange={ ( value ) =>
					updateKindMeta( 'listen_artist', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Album', 'post-kinds-for-indieweb' ) }
				value={ listenAlbum }
				onChange={ ( value ) =>
					updateKindMeta( 'listen_album', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Album Art URL', 'post-kinds-for-indieweb' ) }
				value={ listenCover }
				onChange={ ( value ) =>
					updateKindMeta( 'listen_cover', value )
				}
				type="url"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SyndicationControls kind="listen" />
		</Flex>
	);
}

/**
 * Watch Fields Component
 *
 * @return {JSX.Element} Watch fields.
 */
function WatchFields() {
	const videoUrlId = useId();
	const videoSearchId = useId();
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ urlInput, setUrlInput ] = useState( '' );
	const [ isUrlLoading, setIsUrlLoading ] = useState( false );
	const [ urlError, setUrlError ] = useState( '' );

	const {
		watchTitle,
		watchYear,
		watchPoster,
		watchStatus,
		watchSpoilers,
		watchUrl,
		isLoading,
		apiResults,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		const store = select( STORE_NAME );
		return {
			watchTitle: getKindMeta( 'watch_title' ),
			watchYear: getKindMeta( 'watch_year' ),
			watchPoster: getKindMeta( 'watch_poster' ),
			watchStatus: getKindMeta( 'watch_status' ),
			watchSpoilers: getKindMeta( 'watch_spoilers' ),
			watchUrl: getKindMeta( 'watch_url' ),
			isLoading: store.isApiLoading(),
			apiResults:
				store.getApiLookupType() === 'movie'
					? store.getApiResults()
					: [],
		};
	}, [] );

	const { updateKindMeta, performApiLookup, clearApiResults } =
		useDispatch( STORE_NAME );

	// Check if input looks like a URL.
	const isUrl = useCallback( ( input ) => {
		return /^https?:\/\//i.test( input.trim() );
	}, [] );

	// Handle URL lookup for IMDB, TMDB, Trakt, Letterboxd.
	const handleUrlLookup = useCallback( async () => {
		const url = urlInput.trim();
		if ( ! url ) {
			return;
		}

		setIsUrlLoading( true );
		setUrlError( '' );

		try {
			const result = await apiFetch( {
				path: `/post-kinds-indieweb/v1/lookup/watch-url?url=${ encodeURIComponent(
					url
				) }`,
			} );

			// Update metadata from result.
			if ( result.title ) {
				updateKindMeta( 'watch_title', result.title );
			}
			if ( result.year ) {
				updateKindMeta( 'watch_year', result.year );
			}
			if ( result.poster ) {
				updateKindMeta( 'watch_poster', result.poster );
			}
			if ( result.tmdb_id ) {
				updateKindMeta( 'watch_tmdb_id', result.tmdb_id );
			}
			if ( result.imdb_id ) {
				updateKindMeta( 'watch_imdb_id', result.imdb_id );
			}
			if ( result.trakt_id ) {
				updateKindMeta( 'watch_trakt_id', result.trakt_id );
			}
			// Store the URL for reference.
			updateKindMeta( 'watch_url', url );

			setUrlInput( '' );
		} catch ( error ) {
			setUrlError(
				error.message ||
					__(
						'Could not fetch movie/TV info from URL.',
						'post-kinds-for-indieweb'
					)
			);
		} finally {
			setIsUrlLoading( false );
		}
	}, [ urlInput, updateKindMeta ] );

	const handleSearch = useCallback( () => {
		const query = searchQuery.trim();
		if ( ! query ) {
			return;
		}

		// If it looks like a URL, do URL lookup instead.
		if ( isUrl( query ) ) {
			setUrlInput( query );
			setSearchQuery( '' );
			handleUrlLookup();
			return;
		}

		performApiLookup( 'movie', query );
	}, [ searchQuery, isUrl, performApiLookup, handleUrlLookup ] );

	const handleSelectResult = useCallback(
		( result ) => {
			updateKindMeta( 'watch_title', result.title );
			updateKindMeta( 'watch_year', result.year );
			updateKindMeta( 'watch_poster', result.poster );
			updateKindMeta( 'watch_tmdb_id', result.tmdb_id );
			clearApiResults();
			setSearchQuery( '' );
		},
		[ updateKindMeta, clearApiResults ]
	);

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			{ /* URL Input for IMDB, TMDB, Trakt, Letterboxd */ }
			<BaseControl
				id={ videoUrlId }
				label={ __( 'Paste Movie/TV URL', 'post-kinds-for-indieweb' ) }
				help={ __(
					'IMDB, TMDB, Trakt, or Letterboxd',
					'post-kinds-for-indieweb'
				) }
			>
				<Flex>
					<TextControl
						value={ urlInput }
						onChange={ setUrlInput }
						placeholder="https://www.imdb.com/title/..."
						onKeyDown={ ( e ) =>
							e.key === 'Enter' && handleUrlLookup()
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						icon={ linkIcon }
						onClick={ handleUrlLookup }
						disabled={ isUrlLoading || ! urlInput.trim() }
						label={ __( 'Fetch', 'post-kinds-for-indieweb' ) }
					/>
				</Flex>
				{ urlError && (
					<p
						style={ {
							color: '#d63638',
							fontSize: '12px',
							marginTop: '4px',
						} }
					>
						{ urlError }
					</p>
				) }
			</BaseControl>

			{ isUrlLoading && <Spinner /> }

			{ /* Saved URL display */ }
			{ watchUrl && (
				<div
					style={ {
						padding: '8px 12px',
						backgroundColor: '#f0f6fc',
						border: '1px solid #c3c4c7',
						borderRadius: '2px',
						fontSize: '12px',
						wordBreak: 'break-all',
					} }
				>
					<strong>
						{ __( 'Linked:', 'post-kinds-for-indieweb' ) }
					</strong>{ ' ' }
					<a
						href={ watchUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ watchUrl }
					</a>
				</div>
			) }

			{ /* Or search by title */ }
			<BaseControl
				id={ videoSearchId }
				label={ __( 'Or Search by Title', 'post-kinds-for-indieweb' ) }
			>
				<Flex>
					<TextControl
						value={ searchQuery }
						onChange={ setSearchQuery }
						placeholder={ __(
							'Movie or TV show title…',
							'post-kinds-for-indieweb'
						) }
						onKeyDown={ ( e ) =>
							e.key === 'Enter' && handleSearch()
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						icon={ searchIcon }
						onClick={ handleSearch }
						disabled={ isLoading }
						label={ __( 'Search', 'post-kinds-for-indieweb' ) }
					/>
				</Flex>
			</BaseControl>

			{ isLoading && <Spinner /> }

			{ apiResults.length > 0 && (
				<div className="post-kinds-indieweb-api-results">
					{ apiResults.slice( 0, 5 ).map( ( result, index ) => (
						<Button
							key={ index }
							className="post-kinds-indieweb-api-result"
							onClick={ () => handleSelectResult( result ) }
						>
							{ result.poster && (
								<img
									src={ result.poster }
									alt=""
									width="30"
									height="45"
								/>
							) }
							<span>
								<strong>{ result.title }</strong> (
								{ result.year })
							</span>
						</Button>
					) ) }
				</div>
			) }

			<TextControl
				label={ __( 'Title', 'post-kinds-for-indieweb' ) }
				value={ watchTitle }
				onChange={ ( value ) => updateKindMeta( 'watch_title', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Year', 'post-kinds-for-indieweb' ) }
				value={ watchYear }
				onChange={ ( value ) => updateKindMeta( 'watch_year', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Status', 'post-kinds-for-indieweb' ) }
				value={ watchStatus }
				onChange={ ( value ) =>
					updateKindMeta( 'watch_status', value )
				}
				options={ [
					{
						label: __( 'Watched', 'post-kinds-for-indieweb' ),
						value: 'watched',
					},
					{
						label: __(
							'Currently Watching',
							'post-kinds-for-indieweb'
						),
						value: 'watching',
					},
					{
						label: __( 'Abandoned', 'post-kinds-for-indieweb' ),
						value: 'abandoned',
					},
				] }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<ToggleControl
				label={ __( 'Contains spoilers', 'post-kinds-for-indieweb' ) }
				checked={ watchSpoilers }
				onChange={ ( value ) =>
					updateKindMeta( 'watch_spoilers', value )
				}
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Poster URL', 'post-kinds-for-indieweb' ) }
				value={ watchPoster }
				onChange={ ( value ) =>
					updateKindMeta( 'watch_poster', value )
				}
				type="url"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SyndicationControls kind="watch" />
		</Flex>
	);
}

/**
 * Read Fields Component
 *
 * @return {JSX.Element} Read fields.
 */
function ReadFields() {
	const bookSearchId = useId();
	const [ searchQuery, setSearchQuery ] = useState( '' );

	const {
		readTitle,
		readAuthor,
		readIsbn,
		readCover,
		readStatus,
		readProgress,
		readPages,
		isLoading,
		apiResults,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		const store = select( STORE_NAME );
		return {
			readTitle: getKindMeta( 'read_title' ),
			readAuthor: getKindMeta( 'read_author' ),
			readIsbn: getKindMeta( 'read_isbn' ),
			readCover: getKindMeta( 'read_cover' ),
			readStatus: getKindMeta( 'read_status' ),
			readProgress: getKindMeta( 'read_progress' ),
			readPages: getKindMeta( 'read_pages' ),
			isLoading: store.isApiLoading(),
			apiResults:
				store.getApiLookupType() === 'book'
					? store.getApiResults()
					: [],
		};
	}, [] );

	const { updateKindMeta, performApiLookup, clearApiResults } =
		useDispatch( STORE_NAME );

	const handleSearch = useCallback( () => {
		if ( searchQuery.trim() ) {
			performApiLookup( 'book', searchQuery );
		}
	}, [ searchQuery, performApiLookup ] );

	const handleSelectResult = useCallback(
		( result ) => {
			updateKindMeta( 'read_title', result.title );
			updateKindMeta( 'read_author', result.author );
			updateKindMeta( 'read_isbn', result.isbn );
			updateKindMeta( 'read_cover', result.cover );
			updateKindMeta( 'read_pages', result.pages || 0 );
			clearApiResults();
			setSearchQuery( '' );
		},
		[ updateKindMeta, clearApiResults ]
	);

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<BaseControl
				id={ bookSearchId }
				label={ __( 'Search Books', 'post-kinds-for-indieweb' ) }
			>
				<Flex>
					<TextControl
						value={ searchQuery }
						onChange={ setSearchQuery }
						placeholder={ __(
							'Title or ISBN…',
							'post-kinds-for-indieweb'
						) }
						onKeyDown={ ( e ) =>
							e.key === 'Enter' && handleSearch()
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						icon={ searchIcon }
						onClick={ handleSearch }
						disabled={ isLoading }
						label={ __( 'Search', 'post-kinds-for-indieweb' ) }
					/>
				</Flex>
			</BaseControl>

			{ isLoading && <Spinner /> }

			{ apiResults.length > 0 && (
				<div className="post-kinds-indieweb-api-results">
					{ apiResults.slice( 0, 5 ).map( ( result, index ) => (
						<Button
							key={ index }
							className="post-kinds-indieweb-api-result"
							onClick={ () => handleSelectResult( result ) }
						>
							{ result.cover && (
								<img
									src={ result.cover }
									alt=""
									width="30"
									height="45"
								/>
							) }
							<span>
								<strong>{ result.title }</strong>
								<br />
								{ result.author }
							</span>
						</Button>
					) ) }
				</div>
			) }

			<TextControl
				label={ __( 'Title', 'post-kinds-for-indieweb' ) }
				value={ readTitle }
				onChange={ ( value ) => updateKindMeta( 'read_title', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Author', 'post-kinds-for-indieweb' ) }
				value={ readAuthor }
				onChange={ ( value ) => updateKindMeta( 'read_author', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'ISBN', 'post-kinds-for-indieweb' ) }
				value={ readIsbn }
				onChange={ ( value ) => updateKindMeta( 'read_isbn', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Status', 'post-kinds-for-indieweb' ) }
				value={ readStatus }
				onChange={ ( value ) => updateKindMeta( 'read_status', value ) }
				options={ [
					{
						label: __( 'To Read', 'post-kinds-for-indieweb' ),
						value: 'to-read',
					},
					{
						label: __(
							'Currently Reading',
							'post-kinds-for-indieweb'
						),
						value: 'reading',
					},
					{
						label: __( 'Finished', 'post-kinds-for-indieweb' ),
						value: 'finished',
					},
					{
						label: __( 'Abandoned', 'post-kinds-for-indieweb' ),
						value: 'abandoned',
					},
				] }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<Flex>
				<TextControl
					label={ __( 'Current Page', 'post-kinds-for-indieweb' ) }
					value={ readProgress }
					onChange={ ( value ) =>
						updateKindMeta(
							'read_progress',
							parseInt( value, 10 ) || 0
						)
					}
					type="number"
					min="0"
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					label={ __( 'Total Pages', 'post-kinds-for-indieweb' ) }
					value={ readPages }
					onChange={ ( value ) =>
						updateKindMeta(
							'read_pages',
							parseInt( value, 10 ) || 0
						)
					}
					type="number"
					min="0"
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</Flex>
			<TextControl
				label={ __( 'Cover URL', 'post-kinds-for-indieweb' ) }
				value={ readCover }
				onChange={ ( value ) => updateKindMeta( 'read_cover', value ) }
				type="url"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Event Fields Component
 *
 * @return {JSX.Element} Event fields.
 */
function EventFields() {
	const { eventStart, eventEnd, eventLocation, eventUrl } = useSelect(
		( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				eventStart: getKindMeta( 'event_start' ),
				eventEnd: getKindMeta( 'event_end' ),
				eventLocation: getKindMeta( 'event_location' ),
				eventUrl: getKindMeta( 'event_url' ),
			};
		},
		[]
	);

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'Start Date/Time', 'post-kinds-for-indieweb' ) }
				value={ eventStart }
				onChange={ ( value ) => updateKindMeta( 'event_start', value ) }
				type="datetime-local"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'End Date/Time', 'post-kinds-for-indieweb' ) }
				value={ eventEnd }
				onChange={ ( value ) => updateKindMeta( 'event_end', value ) }
				type="datetime-local"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Location', 'post-kinds-for-indieweb' ) }
				value={ eventLocation }
				onChange={ ( value ) =>
					updateKindMeta( 'event_location', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Event URL', 'post-kinds-for-indieweb' ) }
				value={ eventUrl }
				onChange={ ( value ) => updateKindMeta( 'event_url', value ) }
				type="url"
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Review Fields Component
 *
 * @return {JSX.Element} Review fields.
 */
function ReviewFields() {
	const ratingId = useId();
	const { reviewRating, reviewBest, reviewItemName, reviewItemUrl } =
		useSelect( ( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				reviewRating: getKindMeta( 'review_rating' ),
				reviewBest: getKindMeta( 'review_best' ) || 5,
				reviewItemName: getKindMeta( 'review_item_name' ),
				reviewItemUrl: getKindMeta( 'review_item_url' ),
			};
		}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	// Generate star display.
	const rating = parseFloat( reviewRating ) || 0;
	const best = parseInt( reviewBest, 10 ) || 5;
	const stars = [];
	for ( let i = 1; i <= best; i++ ) {
		if ( i <= rating ) {
			stars.push( '★' );
		} else if ( i - 0.5 === rating ) {
			stars.push( '½' );
		} else {
			stars.push( '☆' );
		}
	}

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'Item Name', 'post-kinds-for-indieweb' ) }
				value={ reviewItemName }
				onChange={ ( value ) =>
					updateKindMeta( 'review_item_name', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Item URL', 'post-kinds-for-indieweb' ) }
				value={ reviewItemUrl }
				onChange={ ( value ) =>
					updateKindMeta( 'review_item_url', value )
				}
				type="url"
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<BaseControl
				id={ ratingId }
				label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
			>
				<div className="post-kinds-indieweb-star-rating">
					<span
						className="post-kinds-indieweb-stars"
						aria-hidden="true"
					>
						{ stars.join( '' ) }
					</span>
					<RangeControl
						value={ rating }
						onChange={ ( value ) =>
							updateKindMeta( 'review_rating', value )
						}
						min={ 0 }
						max={ best }
						step={ 0.5 }
						withInputField={ true }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
			</BaseControl>
			<TextControl
				label={ __( 'Maximum Rating', 'post-kinds-for-indieweb' ) }
				value={ reviewBest }
				onChange={ ( value ) =>
					updateKindMeta( 'review_best', parseInt( value, 10 ) || 5 )
				}
				type="number"
				min="1"
				max="10"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<style>{ `
				.post-kinds-indieweb-star-rating .post-kinds-indieweb-stars {
					font-size: 24px;
					color: #f5a623;
					margin-bottom: 8px;
					display: block;
				}

				.post-kinds-indieweb-api-results {
					display: flex;
					flex-direction: column;
					gap: 4px;
					max-height: 200px;
					overflow-y: auto;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 4px;
				}

				.post-kinds-indieweb-api-result {
					display: flex;
					align-items: center;
					gap: 8px;
					padding: 8px;
					text-align: left;
					width: 100%;
					justify-content: flex-start;
				}

				.post-kinds-indieweb-api-result img {
					flex-shrink: 0;
					object-fit: cover;
				}

				.post-kinds-indieweb-api-result span {
					overflow: hidden;
					text-overflow: ellipsis;
				}
			` }</style>
		</Flex>
	);
}

/**
 * Play Fields Component
 *
 * Fields for game logging with BGG/RAWG lookup.
 *
 * @return {JSX.Element} Play fields.
 */
function PlayFields() {
	const gameSearchId = useId();
	const gameUrlId = useId();
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchSource, setSearchSource ] = useState( 'bgg' );
	const [ gameType, setGameType ] = useState( 'boardgame' );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ searchResults, setSearchResults ] = useState( [] );
	const [ searchError, setSearchError ] = useState( '' );
	const [ gameUrl, setGameUrl ] = useState( '' );
	const [ showCustomPlatform, setShowCustomPlatform ] = useState( false );

	const {
		playTitle,
		playPlatform,
		playStatus,
		playHours,
		playCover,
		playRating,
		playBggId,
		playRawgId,
		playSteamId,
		playOfficialUrl,
		playPurchaseUrl,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			playTitle: getKindMeta( 'play_title' ),
			playPlatform: getKindMeta( 'play_platform' ),
			playStatus: getKindMeta( 'play_status' ),
			playHours: getKindMeta( 'play_hours' ),
			playCover: getKindMeta( 'play_cover' ),
			playRating: getKindMeta( 'play_rating' ),
			playBggId: getKindMeta( 'play_bgg_id' ),
			playRawgId: getKindMeta( 'play_rawg_id' ),
			playSteamId: getKindMeta( 'play_steam_id' ),
			playOfficialUrl: getKindMeta( 'play_official_url' ),
			playPurchaseUrl: getKindMeta( 'play_purchase_url' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	// Check if platform is a predefined option
	const isPredefinedPlatform = platformOptions.some(
		( opt ) =>
			opt.value === playPlatform &&
			opt.value !== 'other' &&
			opt.value !== ''
	);

	/**
	 * Get the platform select value based on current state.
	 *
	 * @return {string} The value for the platform SelectControl.
	 */
	const getPlatformSelectValue = () => {
		if ( isPredefinedPlatform ) {
			return playPlatform;
		}
		return playPlatform ? 'other' : '';
	};

	// Handle URL paste to extract BGG ID and title
	const handleUrlPaste = ( value ) => {
		setGameUrl( value );
		// Extract BGG ID and title from URL patterns
		const bggMatch = value.match(
			/(?:boardgamegeek|videogamegeek)\.com\/(?:boardgame|boardgameexpansion|videogame|videogameexpansion|rpgitem|thing)\/(\d+)(?:\/([^/?#]+))?/
		);
		if ( bggMatch ) {
			updateKindMeta( 'play_bgg_id', bggMatch[ 1 ] );
			// Extract title from URL slug if present
			if ( bggMatch[ 2 ] ) {
				// Convert slug to title: "wingspan-americas-expansion" -> "Wingspan Americas Expansion"
				const titleFromSlug = bggMatch[ 2 ]
					.split( '-' )
					.map(
						( word ) =>
							word.charAt( 0 ).toUpperCase() + word.slice( 1 )
					)
					.join( ' ' );
				updateKindMeta( 'play_title', titleFromSlug );
			}
		}
	};

	const handleSearch = useCallback( async () => {
		const query = searchQuery.trim();
		if ( ! query ) {
			return;
		}

		setIsSearching( true );
		setSearchError( '' );
		setSearchResults( [] );

		try {
			const params = new URLSearchParams( {
				q: query,
				source: searchSource,
			} );

			if ( searchSource === 'bgg' ) {
				params.set( 'type', gameType );
			}

			const results = await apiFetch( {
				path: `/post-kinds-indieweb/v1/lookup/game?${ params.toString() }`,
			} );

			setSearchResults( results || [] );
		} catch ( error ) {
			// Handle error - don't show raw HTML
			let errorMsg = __( 'Search failed.', 'post-kinds-for-indieweb' );
			if ( error.message ) {
				// Check if error contains HTML (from PHP fatal error)
				if (
					error.message.includes( '<' ) ||
					error.message.includes( 'critical error' )
				) {
					errorMsg = __(
						'API not configured. Use manual URL paste below, or configure BGG token in Settings.',
						'post-kinds-for-indieweb'
					);
				} else {
					errorMsg = error.message;
				}
			}
			setSearchError( errorMsg );
		} finally {
			setIsSearching( false );
		}
	}, [ searchQuery, searchSource, gameType ] );

	const handleSelectResult = useCallback(
		async ( result ) => {
			// For BGG, fetch full details.
			if ( result.source === 'bgg' && result.id ) {
				setIsSearching( true );
				try {
					const details = await apiFetch( {
						path: `/post-kinds-indieweb/v1/lookup/game?source=bgg&id=${ result.id }`,
					} );

					updateKindMeta(
						'play_title',
						details.title || result.title
					);
					updateKindMeta( 'play_cover', details.cover || '' );
					updateKindMeta(
						'play_bgg_id',
						String( details.id || result.id )
					);
					updateKindMeta( 'play_rawg_id', '' );
				} catch {
					// Fallback to search result data.
					updateKindMeta( 'play_title', result.title );
					updateKindMeta( 'play_bgg_id', String( result.id ) );
					updateKindMeta( 'play_rawg_id', '' );
				} finally {
					setIsSearching( false );
				}
			} else {
				// RAWG result.
				updateKindMeta( 'play_title', result.name || result.title );
				updateKindMeta( 'play_cover', result.cover || '' );
				updateKindMeta( 'play_rawg_id', String( result.id ) );
				updateKindMeta( 'play_bgg_id', '' );

				// Set platform from first platform if available.
				if ( result.platforms && result.platforms.length > 0 ) {
					updateKindMeta( 'play_platform', result.platforms[ 0 ] );
				}
			}

			setSearchResults( [] );
			setSearchQuery( '' );
		},
		[ updateKindMeta ]
	);

	const platformOptions = [
		{
			label: __( 'Select Platform', 'post-kinds-for-indieweb' ),
			value: '',
		},
		// Video Game Consoles
		{ label: '— Video Games —', value: '', disabled: true },
		{ label: 'PlayStation 5', value: 'PlayStation 5' },
		{ label: 'PlayStation 4', value: 'PlayStation 4' },
		{ label: 'Xbox Series X/S', value: 'Xbox Series X/S' },
		{ label: 'Xbox One', value: 'Xbox One' },
		{ label: 'Nintendo Switch', value: 'Nintendo Switch' },
		{ label: 'Nintendo 3DS', value: 'Nintendo 3DS' },
		{ label: 'Steam Deck', value: 'Steam Deck' },
		// PC/Mobile
		{ label: '— Computer/Mobile —', value: '', disabled: true },
		{ label: 'Windows', value: 'Windows' },
		{ label: 'Mac', value: 'Mac' },
		{ label: 'Linux', value: 'Linux' },
		{ label: 'iOS', value: 'iOS' },
		{ label: 'Android', value: 'Android' },
		// Board/Tabletop
		{ label: '— Tabletop —', value: '', disabled: true },
		{ label: 'Board Game', value: 'Board Game' },
		{ label: 'Card Game', value: 'Card Game' },
		{ label: 'Tabletop RPG', value: 'Tabletop RPG' },
		{ label: 'Miniatures', value: 'Miniatures' },
		{ label: 'Dice Game', value: 'Dice Game' },
		// Other
		{ label: '— Other —', value: '', disabled: true },
		{ label: 'Other (type below)', value: 'other' },
	];

	const statusOptions = [
		{ label: __( 'Playing', 'post-kinds-for-indieweb' ), value: 'playing' },
		{
			label: __( 'Completed', 'post-kinds-for-indieweb' ),
			value: 'completed',
		},
		{
			label: __( 'Abandoned', 'post-kinds-for-indieweb' ),
			value: 'abandoned',
		},
		{ label: __( 'Backlog', 'post-kinds-for-indieweb' ), value: 'backlog' },
	];

	const sourceOptions = [
		{
			label: __( 'BGG: Board Games', 'post-kinds-for-indieweb' ),
			value: 'bgg-board',
		},
		{
			label: __( 'BGG: Video Games', 'post-kinds-for-indieweb' ),
			value: 'bgg-video',
		},
		{
			label: __( 'RAWG: Video Games', 'post-kinds-for-indieweb' ),
			value: 'rawg',
		},
	];

	const handleSourceChange = ( value ) => {
		if ( value === 'bgg-board' ) {
			setSearchSource( 'bgg' );
			setGameType( 'boardgame' );
		} else if ( value === 'bgg-video' ) {
			setSearchSource( 'bgg' );
			setGameType( 'videogame' );
		} else {
			setSearchSource( 'rawg' );
			setGameType( 'videogame' );
		}
	};

	const getCurrentSourceValue = () => {
		if ( searchSource === 'rawg' ) {
			return 'rawg';
		}
		return gameType === 'videogame' ? 'bgg-video' : 'bgg-board';
	};

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			{ /* Manual URL Section */ }
			<BaseControl
				id={ gameSearchId }
				label={ __( 'Find Game', 'post-kinds-for-indieweb' ) }
			>
				<Flex direction="column" gap={ 2 }>
					<p
						style={ {
							fontSize: '12px',
							color: '#757575',
							margin: '0 0 8px',
						} }
					>
						{ __(
							'Search on these sites, then paste the URL:',
							'post-kinds-for-indieweb'
						) }
					</p>
					<Flex style={ { marginBottom: '8px' } }>
						<ExternalLink
							href={ `https://boardgamegeek.com/geeksearch.php?action=search&objecttype=boardgame&q=${ encodeURIComponent(
								playTitle || searchQuery || ''
							) }` }
						>
							{ __( 'BoardGameGeek', 'post-kinds-for-indieweb' ) }
						</ExternalLink>
						<ExternalLink
							href={ `https://videogamegeek.com/geeksearch.php?action=search&objecttype=videogame&q=${ encodeURIComponent(
								playTitle || searchQuery || ''
							) }` }
						>
							{ __( 'VideoGameGeek', 'post-kinds-for-indieweb' ) }
						</ExternalLink>
					</Flex>
					<TextControl
						value={ gameUrl }
						onChange={ handleUrlPaste }
						placeholder="https://boardgamegeek.com/boardgame/13/catan"
						__nextHasNoMarginBottom
					/>
					{ playBggId && (
						<p
							style={ {
								fontSize: '12px',
								color: '#007cba',
								margin: '4px 0 0',
							} }
						>
							{ __( 'BGG ID:', 'post-kinds-for-indieweb' ) }{ ' ' }
							{ playBggId }
						</p>
					) }
				</Flex>
			</BaseControl>

			{ /* API Search Section */ }
			<BaseControl
				id={ gameUrlId }
				label={ __(
					'Or Search API (requires token)',
					'post-kinds-for-indieweb'
				) }
			>
				<Flex direction="column" gap={ 2 }>
					<SelectControl
						value={ getCurrentSourceValue() }
						options={ sourceOptions }
						onChange={ handleSourceChange }
						__nextHasNoMarginBottom
					/>
					<Flex>
						<TextControl
							value={ searchQuery }
							onChange={ setSearchQuery }
							placeholder={ __(
								'Game title…',
								'post-kinds-for-indieweb'
							) }
							onKeyDown={ ( e ) =>
								e.key === 'Enter' && handleSearch()
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<Button
							icon={ searchIcon }
							onClick={ handleSearch }
							disabled={ isSearching }
							label={ __( 'Search', 'post-kinds-for-indieweb' ) }
						/>
					</Flex>
				</Flex>
			</BaseControl>

			{ isSearching && <Spinner /> }

			{ searchError && (
				<p style={ { color: '#d63638', fontSize: '12px' } }>
					{ searchError }
				</p>
			) }

			{ searchResults.length > 0 && (
				<div className="post-kinds-indieweb-api-results">
					{ searchResults.slice( 0, 8 ).map( ( result, index ) => (
						<Button
							key={ index }
							className="post-kinds-indieweb-api-result"
							onClick={ () => handleSelectResult( result ) }
						>
							{ result.cover && (
								<img
									src={ result.cover }
									alt=""
									width="40"
									height="40"
								/>
							) }
							<span>
								<strong>{ result.name || result.title }</strong>
								{ result.year && <> ({ result.year })</> }
								{ result.platforms &&
									result.platforms.length > 0 && (
										<>
											<br />
											<small>
												{ result.platforms
													.slice( 0, 3 )
													.join( ', ' ) }
											</small>
										</>
									) }
							</span>
						</Button>
					) ) }
				</div>
			) }

			{ /* Cover Preview */ }
			{ playCover && (
				<div style={ { textAlign: 'center' } }>
					<img
						src={ playCover }
						alt={ playTitle }
						style={ {
							maxWidth: '150px',
							maxHeight: '200px',
							borderRadius: '4px',
						} }
					/>
				</div>
			) }

			{ /* Game Details */ }
			<TextControl
				label={ __( 'Game Title', 'post-kinds-for-indieweb' ) }
				value={ playTitle }
				onChange={ ( value ) => updateKindMeta( 'play_title', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<SelectControl
				label={ __( 'Platform', 'post-kinds-for-indieweb' ) }
				value={ getPlatformSelectValue() }
				options={ platformOptions }
				onChange={ ( value ) => {
					if ( value === 'other' ) {
						setShowCustomPlatform( true );
						updateKindMeta( 'play_platform', '' );
					} else {
						setShowCustomPlatform( false );
						updateKindMeta( 'play_platform', value );
					}
				} }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ ( showCustomPlatform ||
				( playPlatform && ! isPredefinedPlatform ) ) && (
				<TextControl
					label={ __( 'Custom Platform', 'post-kinds-for-indieweb' ) }
					value={ playPlatform || '' }
					onChange={ ( value ) =>
						updateKindMeta( 'play_platform', value )
					}
					placeholder={ __(
						'Enter platform name…',
						'post-kinds-for-indieweb'
					) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) }

			<SelectControl
				label={ __( 'Status', 'post-kinds-for-indieweb' ) }
				value={ playStatus || 'playing' }
				options={ statusOptions }
				onChange={ ( value ) => updateKindMeta( 'play_status', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ __( 'Hours Played', 'post-kinds-for-indieweb' ) }
				type="number"
				min="0"
				step="0.5"
				value={ playHours || '' }
				onChange={ ( value ) =>
					updateKindMeta( 'play_hours', parseFloat( value ) || 0 )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<RangeControl
				label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
				value={ playRating || 0 }
				onChange={ ( value ) => updateKindMeta( 'play_rating', value ) }
				min={ 0 }
				max={ 5 }
				step={ 0.5 }
				withInputField
				renderTooltipContent={ ( value ) => `${ value } / 5` }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ __( 'Cover Image URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ playCover }
				onChange={ ( value ) => updateKindMeta( 'play_cover', value ) }
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ __( 'Official Website', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ playOfficialUrl }
				onChange={ ( value ) =>
					updateKindMeta( 'play_official_url', value )
				}
				placeholder="https://..."
				help={ __(
					'Link to the official game website.',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ __( 'Purchase Link', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ playPurchaseUrl }
				onChange={ ( value ) =>
					updateKindMeta( 'play_purchase_url', value )
				}
				placeholder="https://amazon.com/..."
				help={ __(
					'Link to buy the game.',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ /* ID Fields (collapsible/advanced) */ }
			<details style={ { fontSize: '12px' } }>
				<summary style={ { cursor: 'pointer', marginBottom: '8px' } }>
					{ __( 'Advanced: Game IDs', 'post-kinds-for-indieweb' ) }
				</summary>
				<Flex direction="column" gap={ 2 }>
					<TextControl
						label={ __(
							'BoardGameGeek ID',
							'post-kinds-for-indieweb'
						) }
						value={ playBggId }
						onChange={ ( value ) =>
							updateKindMeta( 'play_bgg_id', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'RAWG ID', 'post-kinds-for-indieweb' ) }
						value={ playRawgId }
						onChange={ ( value ) =>
							updateKindMeta( 'play_rawg_id', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __(
							'Steam App ID',
							'post-kinds-for-indieweb'
						) }
						value={ playSteamId }
						onChange={ ( value ) =>
							updateKindMeta( 'play_steam_id', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</Flex>
			</details>
		</Flex>
	);
}

/**
 * Eat Fields Component
 *
 * Fields for food/meal logging.
 *
 * @return {JSX.Element} Eat fields.
 */
const EAT_CUISINE_TYPES = [
	{
		label: __( 'Select cuisine…', 'post-kinds-for-indieweb' ),
		value: '',
		emoji: '🍽️',
	},
	{
		label: __( 'American', 'post-kinds-for-indieweb' ),
		value: 'american',
		emoji: '🍔',
	},
	{
		label: __( 'Chinese', 'post-kinds-for-indieweb' ),
		value: 'chinese',
		emoji: '🥡',
	},
	{
		label: __( 'French', 'post-kinds-for-indieweb' ),
		value: 'french',
		emoji: '🥐',
	},
	{
		label: __( 'Indian', 'post-kinds-for-indieweb' ),
		value: 'indian',
		emoji: '🍛',
	},
	{
		label: __( 'Italian', 'post-kinds-for-indieweb' ),
		value: 'italian',
		emoji: '🍝',
	},
	{
		label: __( 'Japanese', 'post-kinds-for-indieweb' ),
		value: 'japanese',
		emoji: '🍱',
	},
	{
		label: __( 'Korean', 'post-kinds-for-indieweb' ),
		value: 'korean',
		emoji: '🍜',
	},
	{
		label: __( 'Mexican', 'post-kinds-for-indieweb' ),
		value: 'mexican',
		emoji: '🌮',
	},
	{
		label: __( 'Thai', 'post-kinds-for-indieweb' ),
		value: 'thai',
		emoji: '🍲',
	},
	{
		label: __( 'Vietnamese', 'post-kinds-for-indieweb' ),
		value: 'vietnamese',
		emoji: '🍜',
	},
	{
		label: __( 'Mediterranean', 'post-kinds-for-indieweb' ),
		value: 'mediterranean',
		emoji: '🥙',
	},
	{
		label: __( 'Seafood', 'post-kinds-for-indieweb' ),
		value: 'seafood',
		emoji: '🦐',
	},
	{
		label: __( 'Breakfast', 'post-kinds-for-indieweb' ),
		value: 'breakfast',
		emoji: '🥞',
	},
	{
		label: __( 'Dessert', 'post-kinds-for-indieweb' ),
		value: 'dessert',
		emoji: '🍰',
	},
	{
		label: __( 'Other', 'post-kinds-for-indieweb' ),
		value: 'other',
		emoji: '🍽️',
	},
];

function EatFields() {
	const eatLocationId = useId();
	const {
		eatName,
		eatCuisine,
		eatType,
		eatPhoto,
		eatRating,
		eatNotes,
		eatRestaurantUrl,
		eatLocationName,
		eatLocationAddress,
		eatLocationLocality,
		eatLocationRegion,
		eatLocationCountry,
		eatGeoLatitude,
		eatGeoLongitude,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			eatName: getKindMeta( 'eat_name' ),
			eatCuisine: getKindMeta( 'eat_cuisine' ),
			eatType: getKindMeta( 'eat_type' ),
			eatPhoto: getKindMeta( 'eat_photo' ),
			eatRating: getKindMeta( 'eat_rating' ),
			eatNotes: getKindMeta( 'eat_notes' ),
			eatRestaurantUrl: getKindMeta( 'eat_restaurant_url' ),
			eatLocationName: getKindMeta( 'eat_location_name' ),
			eatLocationAddress: getKindMeta( 'eat_location_address' ),
			eatLocationLocality: getKindMeta( 'eat_location_locality' ),
			eatLocationRegion: getKindMeta( 'eat_location_region' ),
			eatLocationCountry: getKindMeta( 'eat_location_country' ),
			eatGeoLatitude: getKindMeta( 'eat_geo_latitude' ),
			eatGeoLongitude: getKindMeta( 'eat_geo_longitude' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	const cuisineOptions = EAT_CUISINE_TYPES.map( ( type ) => ( {
		label: `${ type.emoji } ${ type.label }`,
		value: type.value,
	} ) );

	const typeOptions = [
		{ label: __( 'Select type…', 'post-kinds-for-indieweb' ), value: '' },
		{
			label: __( 'Breakfast', 'post-kinds-for-indieweb' ),
			value: 'breakfast',
		},
		{ label: __( 'Lunch', 'post-kinds-for-indieweb' ), value: 'lunch' },
		{ label: __( 'Dinner', 'post-kinds-for-indieweb' ), value: 'dinner' },
		{ label: __( 'Snack', 'post-kinds-for-indieweb' ), value: 'snack' },
		{ label: __( 'Dessert', 'post-kinds-for-indieweb' ), value: 'dessert' },
	];

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'Food/Meal', 'post-kinds-for-indieweb' ) }
				value={ eatName }
				onChange={ ( value ) => updateKindMeta( 'eat_name', value ) }
				placeholder={ __(
					'What did you eat?',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Cuisine', 'post-kinds-for-indieweb' ) }
				value={ eatCuisine }
				options={ cuisineOptions }
				onChange={ ( value ) => updateKindMeta( 'eat_cuisine', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Meal Type', 'post-kinds-for-indieweb' ) }
				value={ eatType }
				options={ typeOptions }
				onChange={ ( value ) => updateKindMeta( 'eat_type', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<RangeControl
				label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
				value={ eatRating || 0 }
				onChange={ ( value ) => updateKindMeta( 'eat_rating', value ) }
				min={ 0 }
				max={ 5 }
				step={ 0.5 }
				withInputField
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Photo URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ eatPhoto }
				onChange={ ( value ) => updateKindMeta( 'eat_photo', value ) }
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Notes', 'post-kinds-for-indieweb' ) }
				value={ eatNotes }
				onChange={ ( value ) => updateKindMeta( 'eat_notes', value ) }
				placeholder={ __( 'How was it?', 'post-kinds-for-indieweb' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ /* Location Section */ }
			<BaseControl
				id={ eatLocationId }
				label={ __( 'Location', 'post-kinds-for-indieweb' ) }
			>
				<Flex direction="column" gap={ 3 }>
					<TextControl
						label={ __(
							'Restaurant/Venue Name',
							'post-kinds-for-indieweb'
						) }
						value={ eatLocationName }
						onChange={ ( value ) =>
							updateKindMeta( 'eat_location_name', value )
						}
						placeholder={ __(
							'Where did you eat?',
							'post-kinds-for-indieweb'
						) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Address', 'post-kinds-for-indieweb' ) }
						value={ eatLocationAddress }
						onChange={ ( value ) =>
							updateKindMeta( 'eat_location_address', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Flex>
						<TextControl
							label={ __( 'City', 'post-kinds-for-indieweb' ) }
							value={ eatLocationLocality }
							onChange={ ( value ) =>
								updateKindMeta( 'eat_location_locality', value )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __(
								'State/Region',
								'post-kinds-for-indieweb'
							) }
							value={ eatLocationRegion }
							onChange={ ( value ) =>
								updateKindMeta( 'eat_location_region', value )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</Flex>
					<TextControl
						label={ __( 'Country', 'post-kinds-for-indieweb' ) }
						value={ eatLocationCountry }
						onChange={ ( value ) =>
							updateKindMeta( 'eat_location_country', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Website URL', 'post-kinds-for-indieweb' ) }
						type="url"
						value={ eatRestaurantUrl }
						onChange={ ( value ) =>
							updateKindMeta( 'eat_restaurant_url', value )
						}
						placeholder="https://"
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Flex>
						<TextControl
							label={ __(
								'Latitude',
								'post-kinds-for-indieweb'
							) }
							value={ eatGeoLatitude }
							onChange={ ( value ) =>
								updateKindMeta(
									'eat_geo_latitude',
									parseFloat( value ) || 0
								)
							}
							type="number"
							step="0.0000001"
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __(
								'Longitude',
								'post-kinds-for-indieweb'
							) }
							value={ eatGeoLongitude }
							onChange={ ( value ) =>
								updateKindMeta(
									'eat_geo_longitude',
									parseFloat( value ) || 0
								)
							}
							type="number"
							step="0.0000001"
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</Flex>
				</Flex>
			</BaseControl>
		</Flex>
	);
}

/**
 * Drink Fields Component
 *
 * Fields for beverage logging.
 *
 * @return {JSX.Element} Drink fields.
 */
function DrinkFields() {
	const drinkLocationId = useId();
	const {
		drinkName,
		drinkType,
		drinkBrewery,
		drinkPhoto,
		drinkRating,
		drinkLocationName,
		drinkLocationAddress,
		drinkLocationLocality,
		drinkLocationRegion,
		drinkLocationCountry,
		drinkGeoLatitude,
		drinkGeoLongitude,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			drinkName: getKindMeta( 'drink_name' ),
			drinkType: getKindMeta( 'drink_type' ),
			drinkBrewery: getKindMeta( 'drink_brewery' ),
			drinkPhoto: getKindMeta( 'drink_photo' ),
			drinkRating: getKindMeta( 'drink_rating' ),
			drinkLocationName: getKindMeta( 'drink_location_name' ),
			drinkLocationAddress: getKindMeta( 'drink_location_address' ),
			drinkLocationLocality: getKindMeta( 'drink_location_locality' ),
			drinkLocationRegion: getKindMeta( 'drink_location_region' ),
			drinkLocationCountry: getKindMeta( 'drink_location_country' ),
			drinkGeoLatitude: getKindMeta( 'drink_geo_latitude' ),
			drinkGeoLongitude: getKindMeta( 'drink_geo_longitude' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	const typeOptions = [
		{ label: __( 'Select type…', 'post-kinds-for-indieweb' ), value: '' },
		{ label: __( 'Coffee', 'post-kinds-for-indieweb' ), value: 'coffee' },
		{ label: __( 'Tea', 'post-kinds-for-indieweb' ), value: 'tea' },
		{ label: __( 'Beer', 'post-kinds-for-indieweb' ), value: 'beer' },
		{ label: __( 'Wine', 'post-kinds-for-indieweb' ), value: 'wine' },
		{
			label: __( 'Cocktail', 'post-kinds-for-indieweb' ),
			value: 'cocktail',
		},
		{ label: __( 'Spirit', 'post-kinds-for-indieweb' ), value: 'spirit' },
		{ label: __( 'Soda', 'post-kinds-for-indieweb' ), value: 'soda' },
		{ label: __( 'Juice', 'post-kinds-for-indieweb' ), value: 'juice' },
		{ label: __( 'Water', 'post-kinds-for-indieweb' ), value: 'water' },
		{ label: __( 'Other', 'post-kinds-for-indieweb' ), value: 'other' },
	];

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'Drink Name', 'post-kinds-for-indieweb' ) }
				value={ drinkName }
				onChange={ ( value ) => updateKindMeta( 'drink_name', value ) }
				placeholder={ __(
					'What are you drinking?',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Type', 'post-kinds-for-indieweb' ) }
				value={ drinkType }
				options={ typeOptions }
				onChange={ ( value ) => updateKindMeta( 'drink_type', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Brewery/Brand', 'post-kinds-for-indieweb' ) }
				value={ drinkBrewery }
				onChange={ ( value ) =>
					updateKindMeta( 'drink_brewery', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<RangeControl
				label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
				value={ drinkRating || 0 }
				onChange={ ( value ) =>
					updateKindMeta( 'drink_rating', value )
				}
				min={ 0 }
				max={ 5 }
				step={ 0.5 }
				withInputField
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Photo URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ drinkPhoto }
				onChange={ ( value ) => updateKindMeta( 'drink_photo', value ) }
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ /* Location Section */ }
			<BaseControl
				id={ drinkLocationId }
				label={ __( 'Location', 'post-kinds-for-indieweb' ) }
			>
				<Flex direction="column" gap={ 3 }>
					<TextControl
						label={ __(
							'Bar/Cafe/Venue Name',
							'post-kinds-for-indieweb'
						) }
						value={ drinkLocationName }
						onChange={ ( value ) =>
							updateKindMeta( 'drink_location_name', value )
						}
						placeholder={ __(
							'Where are you drinking?',
							'post-kinds-for-indieweb'
						) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Address', 'post-kinds-for-indieweb' ) }
						value={ drinkLocationAddress }
						onChange={ ( value ) =>
							updateKindMeta( 'drink_location_address', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Flex>
						<TextControl
							label={ __( 'City', 'post-kinds-for-indieweb' ) }
							value={ drinkLocationLocality }
							onChange={ ( value ) =>
								updateKindMeta(
									'drink_location_locality',
									value
								)
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __(
								'State/Region',
								'post-kinds-for-indieweb'
							) }
							value={ drinkLocationRegion }
							onChange={ ( value ) =>
								updateKindMeta( 'drink_location_region', value )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</Flex>
					<TextControl
						label={ __( 'Country', 'post-kinds-for-indieweb' ) }
						value={ drinkLocationCountry }
						onChange={ ( value ) =>
							updateKindMeta( 'drink_location_country', value )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Flex>
						<TextControl
							label={ __(
								'Latitude',
								'post-kinds-for-indieweb'
							) }
							value={ drinkGeoLatitude }
							onChange={ ( value ) =>
								updateKindMeta(
									'drink_geo_latitude',
									parseFloat( value ) || 0
								)
							}
							type="number"
							step="0.0000001"
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __(
								'Longitude',
								'post-kinds-for-indieweb'
							) }
							value={ drinkGeoLongitude }
							onChange={ ( value ) =>
								updateKindMeta(
									'drink_geo_longitude',
									parseFloat( value ) || 0
								)
							}
							type="number"
							step="0.0000001"
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</Flex>
				</Flex>
			</BaseControl>
		</Flex>
	);
}

/**
 * Favorite Fields Component
 *
 * Fields for starring/saving items.
 *
 * @return {JSX.Element} Favorite fields.
 */
function FavoriteFields() {
	const { favoriteName, favoriteUrl, favoriteRating } = useSelect(
		( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				favoriteName: getKindMeta( 'favorite_name' ),
				favoriteUrl: getKindMeta( 'favorite_url' ),
				favoriteRating: getKindMeta( 'favorite_rating' ),
			};
		},
		[]
	);

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'Name/Title', 'post-kinds-for-indieweb' ) }
				value={ favoriteName }
				onChange={ ( value ) =>
					updateKindMeta( 'favorite_name', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ favoriteUrl }
				onChange={ ( value ) =>
					updateKindMeta( 'favorite_url', value )
				}
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<RangeControl
				label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
				value={ favoriteRating || 0 }
				onChange={ ( value ) =>
					updateKindMeta( 'favorite_rating', value )
				}
				min={ 0 }
				max={ 5 }
				step={ 0.5 }
				withInputField
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Jam Fields Component
 *
 * Fields for "this is my jam" music highlights.
 *
 * @return {JSX.Element} Jam fields.
 */
function JamFields() {
	const { jamTrack, jamArtist, jamAlbum, jamUrl, jamCover } = useSelect(
		( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				jamTrack: getKindMeta( 'jam_track' ),
				jamArtist: getKindMeta( 'jam_artist' ),
				jamAlbum: getKindMeta( 'jam_album' ),
				jamUrl: getKindMeta( 'jam_url' ),
				jamCover: getKindMeta( 'jam_cover' ),
			};
		},
		[]
	);

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			{ jamCover && (
				<div style={ { textAlign: 'center' } }>
					<img
						src={ jamCover }
						alt={ jamTrack }
						style={ { maxWidth: '120px', borderRadius: '4px' } }
					/>
				</div>
			) }
			<TextControl
				label={ __( 'Track', 'post-kinds-for-indieweb' ) }
				value={ jamTrack }
				onChange={ ( value ) => updateKindMeta( 'jam_track', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Artist', 'post-kinds-for-indieweb' ) }
				value={ jamArtist }
				onChange={ ( value ) => updateKindMeta( 'jam_artist', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Album', 'post-kinds-for-indieweb' ) }
				value={ jamAlbum }
				onChange={ ( value ) => updateKindMeta( 'jam_album', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Link', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ jamUrl }
				onChange={ ( value ) => updateKindMeta( 'jam_url', value ) }
				placeholder="https://open.spotify.com/..."
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Cover Image URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ jamCover }
				onChange={ ( value ) => updateKindMeta( 'jam_cover', value ) }
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Wish Fields Component
 *
 * Fields for wishlist items.
 *
 * @return {JSX.Element} Wish fields.
 */
function WishFields() {
	const { wishName, wishUrl, wishType, wishPriority, wishPhoto } = useSelect(
		( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				wishName: getKindMeta( 'wish_name' ),
				wishUrl: getKindMeta( 'wish_url' ),
				wishType: getKindMeta( 'wish_type' ),
				wishPriority: getKindMeta( 'wish_priority' ),
				wishPhoto: getKindMeta( 'wish_photo' ),
			};
		},
		[]
	);

	const { updateKindMeta } = useDispatch( STORE_NAME );

	const typeOptions = [
		{ label: __( 'Select type…', 'post-kinds-for-indieweb' ), value: '' },
		{ label: __( 'Book', 'post-kinds-for-indieweb' ), value: 'book' },
		{
			label: __( 'Movie/Show', 'post-kinds-for-indieweb' ),
			value: 'movie',
		},
		{ label: __( 'Game', 'post-kinds-for-indieweb' ), value: 'game' },
		{ label: __( 'Music', 'post-kinds-for-indieweb' ), value: 'music' },
		{ label: __( 'Product', 'post-kinds-for-indieweb' ), value: 'product' },
		{
			label: __( 'Experience', 'post-kinds-for-indieweb' ),
			value: 'experience',
		},
		{ label: __( 'Other', 'post-kinds-for-indieweb' ), value: 'other' },
	];

	const priorityOptions = [
		{ label: __( 'Low', 'post-kinds-for-indieweb' ), value: 'low' },
		{ label: __( 'Medium', 'post-kinds-for-indieweb' ), value: 'medium' },
		{ label: __( 'High', 'post-kinds-for-indieweb' ), value: 'high' },
	];

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<TextControl
				label={ __( 'Item Name', 'post-kinds-for-indieweb' ) }
				value={ wishName }
				onChange={ ( value ) => updateKindMeta( 'wish_name', value ) }
				placeholder={ __(
					'What do you wish for?',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ wishUrl }
				onChange={ ( value ) => updateKindMeta( 'wish_url', value ) }
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Type', 'post-kinds-for-indieweb' ) }
				value={ wishType }
				options={ typeOptions }
				onChange={ ( value ) => updateKindMeta( 'wish_type', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Priority', 'post-kinds-for-indieweb' ) }
				value={ wishPriority || 'medium' }
				options={ priorityOptions }
				onChange={ ( value ) =>
					updateKindMeta( 'wish_priority', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Photo URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ wishPhoto }
				onChange={ ( value ) => updateKindMeta( 'wish_photo', value ) }
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Mood Fields Component
 *
 * Fields for emotional state logging.
 *
 * @return {JSX.Element} Mood fields.
 */
function MoodFields() {
	const { moodEmoji, moodLabel, moodRating } = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			moodEmoji: getKindMeta( 'mood_emoji' ),
			moodLabel: getKindMeta( 'mood_label' ),
			moodRating: getKindMeta( 'mood_rating' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	const ratingOptions = [
		{
			label: __( 'Select level…', 'post-kinds-for-indieweb' ),
			value: '',
		},
		{ label: '1 - ' + __( 'Low', 'post-kinds-for-indieweb' ), value: '1' },
		{ label: '2', value: '2' },
		{
			label: '3 - ' + __( 'Neutral', 'post-kinds-for-indieweb' ),
			value: '3',
		},
		{ label: '4', value: '4' },
		{ label: '5 - ' + __( 'High', 'post-kinds-for-indieweb' ), value: '5' },
	];

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			{ moodEmoji && (
				<div style={ { fontSize: '48px', textAlign: 'center' } }>
					{ moodEmoji }
				</div>
			) }
			<TextControl
				label={ __( 'Mood Emoji', 'post-kinds-for-indieweb' ) }
				value={ moodEmoji }
				onChange={ ( value ) => updateKindMeta( 'mood_emoji', value ) }
				placeholder="😊"
				maxLength={ 10 }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Mood Label', 'post-kinds-for-indieweb' ) }
				value={ moodLabel }
				onChange={ ( value ) => updateKindMeta( 'mood_label', value ) }
				placeholder={ __(
					'How are you feeling?',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Level (1–5)', 'post-kinds-for-indieweb' ) }
				value={ moodRating ? String( moodRating ) : '' }
				options={ ratingOptions }
				onChange={ ( value ) =>
					updateKindMeta( 'mood_rating', parseInt( value ) || 0 )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Acquisition Fields Component
 *
 * Fields for items acquired/purchased.
 *
 * @return {JSX.Element} Acquisition fields.
 */
function AcquisitionFields() {
	const {
		acquisitionName,
		acquisitionUrl,
		acquisitionPrice,
		acquisitionPhoto,
		acquisitionRating,
	} = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			acquisitionName: getKindMeta( 'acquisition_name' ),
			acquisitionUrl: getKindMeta( 'acquisition_url' ),
			acquisitionPrice: getKindMeta( 'acquisition_price' ),
			acquisitionPhoto: getKindMeta( 'acquisition_photo' ),
			acquisitionRating: getKindMeta( 'acquisition_rating' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			{ acquisitionPhoto && (
				<div style={ { textAlign: 'center' } }>
					<img
						src={ acquisitionPhoto }
						alt={ acquisitionName }
						style={ { maxWidth: '150px', borderRadius: '4px' } }
					/>
				</div>
			) }
			<TextControl
				label={ __( 'Item Name', 'post-kinds-for-indieweb' ) }
				value={ acquisitionName }
				onChange={ ( value ) =>
					updateKindMeta( 'acquisition_name', value )
				}
				placeholder={ __(
					'What did you get?',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ acquisitionUrl }
				onChange={ ( value ) =>
					updateKindMeta( 'acquisition_url', value )
				}
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Price', 'post-kinds-for-indieweb' ) }
				value={ acquisitionPrice }
				onChange={ ( value ) =>
					updateKindMeta( 'acquisition_price', value )
				}
				placeholder="$0.00"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<RangeControl
				label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
				value={ acquisitionRating || 0 }
				onChange={ ( value ) =>
					updateKindMeta( 'acquisition_rating', value )
				}
				min={ 0 }
				max={ 5 }
				step={ 0.5 }
				withInputField
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Photo URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ acquisitionPhoto }
				onChange={ ( value ) =>
					updateKindMeta( 'acquisition_photo', value )
				}
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}

/**
 * Recipe Fields Component
 *
 * Fields for recipe posts (integrates with WP Recipe Maker).
 *
 * @return {JSX.Element} Recipe fields.
 */
function RecipeFields() {
	const { recipeName, recipeYield, recipeDuration, recipeUrl } = useSelect(
		( select ) => {
			const getKindMeta = select( STORE_NAME ).getKindMeta;
			return {
				recipeName: getKindMeta( 'recipe_name' ),
				recipeYield: getKindMeta( 'recipe_yield' ),
				recipeDuration: getKindMeta( 'recipe_duration' ),
				recipeUrl: getKindMeta( 'recipe_url' ),
			};
		},
		[]
	);

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<Flex
			direction="column"
			gap={ 4 }
			className="post-kinds-indieweb-kind-fields"
		>
			<p
				className="description"
				style={ { margin: 0, fontSize: '12px', color: '#757575' } }
			>
				{ __(
					'For full recipe features, use WP Recipe Maker blocks.',
					'post-kinds-for-indieweb'
				) }
			</p>
			<TextControl
				label={ __( 'Recipe Name', 'post-kinds-for-indieweb' ) }
				value={ recipeName }
				onChange={ ( value ) => updateKindMeta( 'recipe_name', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Yield/Servings', 'post-kinds-for-indieweb' ) }
				value={ recipeYield }
				onChange={ ( value ) =>
					updateKindMeta( 'recipe_yield', value )
				}
				placeholder={ __( '4 servings', 'post-kinds-for-indieweb' ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Total Time', 'post-kinds-for-indieweb' ) }
				value={ recipeDuration }
				onChange={ ( value ) =>
					updateKindMeta( 'recipe_duration', value )
				}
				placeholder={ __(
					'PT1H30M (ISO 8601)',
					'post-kinds-for-indieweb'
				) }
				help={ __(
					'Format: PT1H30M = 1 hour 30 minutes',
					'post-kinds-for-indieweb'
				) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ __( 'Recipe Source URL', 'post-kinds-for-indieweb' ) }
				type="url"
				value={ recipeUrl }
				onChange={ ( value ) => updateKindMeta( 'recipe_url', value ) }
				placeholder="https://"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</Flex>
	);
}
