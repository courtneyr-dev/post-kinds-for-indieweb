/**
 * Reactions for IndieWeb - Kind Fields Component
 *
 * Displays kind-specific metadata fields based on the selected post kind.
 *
 * @package ReactionsForIndieWeb
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
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState, useCallback } from '@wordpress/element';
import { search as searchIcon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { STORE_NAME } from '../../stores/post-kinds';

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
		case 'reply':
		case 'like':
		case 'repost':
		case 'bookmark':
			return <CitationFields />;
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
	const { citeName, citeUrl, citeAuthor, citeSummary } = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			citeName: getKindMeta( 'cite_name' ),
			citeUrl: getKindMeta( 'cite_url' ),
			citeAuthor: getKindMeta( 'cite_author' ),
			citeSummary: getKindMeta( 'cite_summary' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<TextControl
				label={ __( 'URL', 'reactions-indieweb' ) }
				value={ citeUrl }
				onChange={ ( value ) => updateKindMeta( 'cite_url', value ) }
				type="url"
				placeholder="https://"
			/>
			<TextControl
				label={ __( 'Title', 'reactions-indieweb' ) }
				value={ citeName }
				onChange={ ( value ) => updateKindMeta( 'cite_name', value ) }
			/>
			<TextControl
				label={ __( 'Author', 'reactions-indieweb' ) }
				value={ citeAuthor }
				onChange={ ( value ) => updateKindMeta( 'cite_author', value ) }
			/>
			<TextareaControl
				label={ __( 'Summary', 'reactions-indieweb' ) }
				value={ citeSummary }
				onChange={ ( value ) => updateKindMeta( 'cite_summary', value ) }
				rows={ 3 }
			/>
		</VStack>
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
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<TextControl
				label={ __( 'Event URL', 'reactions-indieweb' ) }
				value={ citeUrl }
				onChange={ ( value ) => updateKindMeta( 'cite_url', value ) }
				type="url"
				placeholder="https://"
			/>
			<TextControl
				label={ __( 'Event Name', 'reactions-indieweb' ) }
				value={ citeName }
				onChange={ ( value ) => updateKindMeta( 'cite_name', value ) }
			/>
			<SelectControl
				label={ __( 'RSVP Status', 'reactions-indieweb' ) }
				value={ rsvpStatus }
				onChange={ ( value ) => updateKindMeta( 'rsvp_status', value ) }
				options={ [
					{ label: __( 'Select status…', 'reactions-indieweb' ), value: '' },
					{ label: __( '✅ Yes, attending', 'reactions-indieweb' ), value: 'yes' },
					{ label: __( '❌ No, can\'t make it', 'reactions-indieweb' ), value: 'no' },
					{ label: __( '🤔 Maybe', 'reactions-indieweb' ), value: 'maybe' },
					{ label: __( '👀 Interested', 'reactions-indieweb' ), value: 'interested' },
				] }
			/>
		</VStack>
	);
}

/**
 * Checkin Fields Component
 *
 * @return {JSX.Element} Checkin fields.
 */
function CheckinFields() {
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

	return (
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<TextControl
				label={ __( 'Venue Name', 'reactions-indieweb' ) }
				value={ checkinName }
				onChange={ ( value ) => updateKindMeta( 'checkin_name', value ) }
			/>
			<TextControl
				label={ __( 'Address', 'reactions-indieweb' ) }
				value={ checkinAddress }
				onChange={ ( value ) => updateKindMeta( 'checkin_address', value ) }
			/>
			<HStack>
				<TextControl
					label={ __( 'City', 'reactions-indieweb' ) }
					value={ checkinLocality }
					onChange={ ( value ) => updateKindMeta( 'checkin_locality', value ) }
				/>
				<TextControl
					label={ __( 'State/Region', 'reactions-indieweb' ) }
					value={ checkinRegion }
					onChange={ ( value ) => updateKindMeta( 'checkin_region', value ) }
				/>
			</HStack>
			<TextControl
				label={ __( 'Country', 'reactions-indieweb' ) }
				value={ checkinCountry }
				onChange={ ( value ) => updateKindMeta( 'checkin_country', value ) }
			/>
			<HStack>
				<TextControl
					label={ __( 'Latitude', 'reactions-indieweb' ) }
					value={ geoLatitude }
					onChange={ ( value ) => updateKindMeta( 'geo_latitude', parseFloat( value ) || 0 ) }
					type="number"
					step="0.0000001"
				/>
				<TextControl
					label={ __( 'Longitude', 'reactions-indieweb' ) }
					value={ geoLongitude }
					onChange={ ( value ) => updateKindMeta( 'geo_longitude', parseFloat( value ) || 0 ) }
					type="number"
					step="0.0000001"
				/>
			</HStack>
		</VStack>
	);
}

/**
 * Listen Fields Component
 *
 * @return {JSX.Element} Listen fields.
 */
function ListenFields() {
	const [ searchQuery, setSearchQuery ] = useState( '' );

	const {
		listenTrack,
		listenArtist,
		listenAlbum,
		listenCover,
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
			isLoading: store.isApiLoading(),
			apiResults: store.getApiLookupType() === 'music' ? store.getApiResults() : [],
		};
	}, [] );

	const { updateKindMeta, performApiLookup, clearApiResults } = useDispatch( STORE_NAME );

	const handleSearch = useCallback( () => {
		if ( searchQuery.trim() ) {
			performApiLookup( 'music', searchQuery );
		}
	}, [ searchQuery, performApiLookup ] );

	const handleSelectResult = useCallback( ( result ) => {
		updateKindMeta( 'listen_track', result.track );
		updateKindMeta( 'listen_artist', result.artist );
		updateKindMeta( 'listen_album', result.album );
		updateKindMeta( 'listen_cover', result.cover );
		updateKindMeta( 'listen_mbid', result.mbid );
		clearApiResults();
		setSearchQuery( '' );
	}, [ updateKindMeta, clearApiResults ] );

	return (
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<BaseControl label={ __( 'Search Music', 'reactions-indieweb' ) }>
				<HStack>
					<TextControl
						value={ searchQuery }
						onChange={ setSearchQuery }
						placeholder={ __( 'Track name or artist…', 'reactions-indieweb' ) }
						onKeyDown={ ( e ) => e.key === 'Enter' && handleSearch() }
					/>
					<Button
						icon={ searchIcon }
						onClick={ handleSearch }
						disabled={ isLoading }
						label={ __( 'Search', 'reactions-indieweb' ) }
					/>
				</HStack>
			</BaseControl>

			{ isLoading && <Spinner /> }

			{ apiResults.length > 0 && (
				<div className="reactions-indieweb-api-results">
					{ apiResults.slice( 0, 5 ).map( ( result, index ) => (
						<Button
							key={ index }
							className="reactions-indieweb-api-result"
							onClick={ () => handleSelectResult( result ) }
						>
							{ result.cover && (
								<img src={ result.cover } alt="" width="40" height="40" />
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
				label={ __( 'Track', 'reactions-indieweb' ) }
				value={ listenTrack }
				onChange={ ( value ) => updateKindMeta( 'listen_track', value ) }
			/>
			<TextControl
				label={ __( 'Artist', 'reactions-indieweb' ) }
				value={ listenArtist }
				onChange={ ( value ) => updateKindMeta( 'listen_artist', value ) }
			/>
			<TextControl
				label={ __( 'Album', 'reactions-indieweb' ) }
				value={ listenAlbum }
				onChange={ ( value ) => updateKindMeta( 'listen_album', value ) }
			/>
			<TextControl
				label={ __( 'Album Art URL', 'reactions-indieweb' ) }
				value={ listenCover }
				onChange={ ( value ) => updateKindMeta( 'listen_cover', value ) }
				type="url"
			/>
		</VStack>
	);
}

/**
 * Watch Fields Component
 *
 * @return {JSX.Element} Watch fields.
 */
function WatchFields() {
	const [ searchQuery, setSearchQuery ] = useState( '' );

	const {
		watchTitle,
		watchYear,
		watchPoster,
		watchStatus,
		watchSpoilers,
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
			isLoading: store.isApiLoading(),
			apiResults: store.getApiLookupType() === 'movie' ? store.getApiResults() : [],
		};
	}, [] );

	const { updateKindMeta, performApiLookup, clearApiResults } = useDispatch( STORE_NAME );

	const handleSearch = useCallback( () => {
		if ( searchQuery.trim() ) {
			performApiLookup( 'movie', searchQuery );
		}
	}, [ searchQuery, performApiLookup ] );

	const handleSelectResult = useCallback( ( result ) => {
		updateKindMeta( 'watch_title', result.title );
		updateKindMeta( 'watch_year', result.year );
		updateKindMeta( 'watch_poster', result.poster );
		updateKindMeta( 'watch_tmdb_id', result.tmdb_id );
		clearApiResults();
		setSearchQuery( '' );
	}, [ updateKindMeta, clearApiResults ] );

	return (
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<BaseControl label={ __( 'Search Movies/TV', 'reactions-indieweb' ) }>
				<HStack>
					<TextControl
						value={ searchQuery }
						onChange={ setSearchQuery }
						placeholder={ __( 'Title…', 'reactions-indieweb' ) }
						onKeyDown={ ( e ) => e.key === 'Enter' && handleSearch() }
					/>
					<Button
						icon={ searchIcon }
						onClick={ handleSearch }
						disabled={ isLoading }
						label={ __( 'Search', 'reactions-indieweb' ) }
					/>
				</HStack>
			</BaseControl>

			{ isLoading && <Spinner /> }

			{ apiResults.length > 0 && (
				<div className="reactions-indieweb-api-results">
					{ apiResults.slice( 0, 5 ).map( ( result, index ) => (
						<Button
							key={ index }
							className="reactions-indieweb-api-result"
							onClick={ () => handleSelectResult( result ) }
						>
							{ result.poster && (
								<img src={ result.poster } alt="" width="30" height="45" />
							) }
							<span>
								<strong>{ result.title }</strong> ({ result.year })
							</span>
						</Button>
					) ) }
				</div>
			) }

			<TextControl
				label={ __( 'Title', 'reactions-indieweb' ) }
				value={ watchTitle }
				onChange={ ( value ) => updateKindMeta( 'watch_title', value ) }
			/>
			<TextControl
				label={ __( 'Year', 'reactions-indieweb' ) }
				value={ watchYear }
				onChange={ ( value ) => updateKindMeta( 'watch_year', value ) }
			/>
			<SelectControl
				label={ __( 'Status', 'reactions-indieweb' ) }
				value={ watchStatus }
				onChange={ ( value ) => updateKindMeta( 'watch_status', value ) }
				options={ [
					{ label: __( 'Watched', 'reactions-indieweb' ), value: 'watched' },
					{ label: __( 'Currently Watching', 'reactions-indieweb' ), value: 'watching' },
					{ label: __( 'Abandoned', 'reactions-indieweb' ), value: 'abandoned' },
				] }
			/>
			<ToggleControl
				label={ __( 'Contains spoilers', 'reactions-indieweb' ) }
				checked={ watchSpoilers }
				onChange={ ( value ) => updateKindMeta( 'watch_spoilers', value ) }
			/>
			<TextControl
				label={ __( 'Poster URL', 'reactions-indieweb' ) }
				value={ watchPoster }
				onChange={ ( value ) => updateKindMeta( 'watch_poster', value ) }
				type="url"
			/>
		</VStack>
	);
}

/**
 * Read Fields Component
 *
 * @return {JSX.Element} Read fields.
 */
function ReadFields() {
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
			apiResults: store.getApiLookupType() === 'book' ? store.getApiResults() : [],
		};
	}, [] );

	const { updateKindMeta, performApiLookup, clearApiResults } = useDispatch( STORE_NAME );

	const handleSearch = useCallback( () => {
		if ( searchQuery.trim() ) {
			performApiLookup( 'book', searchQuery );
		}
	}, [ searchQuery, performApiLookup ] );

	const handleSelectResult = useCallback( ( result ) => {
		updateKindMeta( 'read_title', result.title );
		updateKindMeta( 'read_author', result.author );
		updateKindMeta( 'read_isbn', result.isbn );
		updateKindMeta( 'read_cover', result.cover );
		updateKindMeta( 'read_pages', result.pages || 0 );
		clearApiResults();
		setSearchQuery( '' );
	}, [ updateKindMeta, clearApiResults ] );

	return (
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<BaseControl label={ __( 'Search Books', 'reactions-indieweb' ) }>
				<HStack>
					<TextControl
						value={ searchQuery }
						onChange={ setSearchQuery }
						placeholder={ __( 'Title or ISBN…', 'reactions-indieweb' ) }
						onKeyDown={ ( e ) => e.key === 'Enter' && handleSearch() }
					/>
					<Button
						icon={ searchIcon }
						onClick={ handleSearch }
						disabled={ isLoading }
						label={ __( 'Search', 'reactions-indieweb' ) }
					/>
				</HStack>
			</BaseControl>

			{ isLoading && <Spinner /> }

			{ apiResults.length > 0 && (
				<div className="reactions-indieweb-api-results">
					{ apiResults.slice( 0, 5 ).map( ( result, index ) => (
						<Button
							key={ index }
							className="reactions-indieweb-api-result"
							onClick={ () => handleSelectResult( result ) }
						>
							{ result.cover && (
								<img src={ result.cover } alt="" width="30" height="45" />
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
				label={ __( 'Title', 'reactions-indieweb' ) }
				value={ readTitle }
				onChange={ ( value ) => updateKindMeta( 'read_title', value ) }
			/>
			<TextControl
				label={ __( 'Author', 'reactions-indieweb' ) }
				value={ readAuthor }
				onChange={ ( value ) => updateKindMeta( 'read_author', value ) }
			/>
			<TextControl
				label={ __( 'ISBN', 'reactions-indieweb' ) }
				value={ readIsbn }
				onChange={ ( value ) => updateKindMeta( 'read_isbn', value ) }
			/>
			<SelectControl
				label={ __( 'Status', 'reactions-indieweb' ) }
				value={ readStatus }
				onChange={ ( value ) => updateKindMeta( 'read_status', value ) }
				options={ [
					{ label: __( 'To Read', 'reactions-indieweb' ), value: 'to-read' },
					{ label: __( 'Currently Reading', 'reactions-indieweb' ), value: 'reading' },
					{ label: __( 'Finished', 'reactions-indieweb' ), value: 'finished' },
					{ label: __( 'Abandoned', 'reactions-indieweb' ), value: 'abandoned' },
				] }
			/>
			<HStack>
				<TextControl
					label={ __( 'Current Page', 'reactions-indieweb' ) }
					value={ readProgress }
					onChange={ ( value ) => updateKindMeta( 'read_progress', parseInt( value, 10 ) || 0 ) }
					type="number"
					min="0"
				/>
				<TextControl
					label={ __( 'Total Pages', 'reactions-indieweb' ) }
					value={ readPages }
					onChange={ ( value ) => updateKindMeta( 'read_pages', parseInt( value, 10 ) || 0 ) }
					type="number"
					min="0"
				/>
			</HStack>
			<TextControl
				label={ __( 'Cover URL', 'reactions-indieweb' ) }
				value={ readCover }
				onChange={ ( value ) => updateKindMeta( 'read_cover', value ) }
				type="url"
			/>
		</VStack>
	);
}

/**
 * Event Fields Component
 *
 * @return {JSX.Element} Event fields.
 */
function EventFields() {
	const { eventStart, eventEnd, eventLocation, eventUrl } = useSelect( ( select ) => {
		const getKindMeta = select( STORE_NAME ).getKindMeta;
		return {
			eventStart: getKindMeta( 'event_start' ),
			eventEnd: getKindMeta( 'event_end' ),
			eventLocation: getKindMeta( 'event_location' ),
			eventUrl: getKindMeta( 'event_url' ),
		};
	}, [] );

	const { updateKindMeta } = useDispatch( STORE_NAME );

	return (
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<TextControl
				label={ __( 'Start Date/Time', 'reactions-indieweb' ) }
				value={ eventStart }
				onChange={ ( value ) => updateKindMeta( 'event_start', value ) }
				type="datetime-local"
			/>
			<TextControl
				label={ __( 'End Date/Time', 'reactions-indieweb' ) }
				value={ eventEnd }
				onChange={ ( value ) => updateKindMeta( 'event_end', value ) }
				type="datetime-local"
			/>
			<TextControl
				label={ __( 'Location', 'reactions-indieweb' ) }
				value={ eventLocation }
				onChange={ ( value ) => updateKindMeta( 'event_location', value ) }
			/>
			<TextControl
				label={ __( 'Event URL', 'reactions-indieweb' ) }
				value={ eventUrl }
				onChange={ ( value ) => updateKindMeta( 'event_url', value ) }
				type="url"
				placeholder="https://"
			/>
		</VStack>
	);
}

/**
 * Review Fields Component
 *
 * @return {JSX.Element} Review fields.
 */
function ReviewFields() {
	const { reviewRating, reviewBest, reviewItemName, reviewItemUrl } = useSelect( ( select ) => {
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
		<VStack spacing={ 4 } className="reactions-indieweb-kind-fields">
			<TextControl
				label={ __( 'Item Name', 'reactions-indieweb' ) }
				value={ reviewItemName }
				onChange={ ( value ) => updateKindMeta( 'review_item_name', value ) }
			/>
			<TextControl
				label={ __( 'Item URL', 'reactions-indieweb' ) }
				value={ reviewItemUrl }
				onChange={ ( value ) => updateKindMeta( 'review_item_url', value ) }
				type="url"
				placeholder="https://"
			/>
			<BaseControl label={ __( 'Rating', 'reactions-indieweb' ) }>
				<div className="reactions-indieweb-star-rating">
					<span className="reactions-indieweb-stars" aria-hidden="true">
						{ stars.join( '' ) }
					</span>
					<RangeControl
						value={ rating }
						onChange={ ( value ) => updateKindMeta( 'review_rating', value ) }
						min={ 0 }
						max={ best }
						step={ 0.5 }
						withInputField={ true }
					/>
				</div>
			</BaseControl>
			<TextControl
				label={ __( 'Maximum Rating', 'reactions-indieweb' ) }
				value={ reviewBest }
				onChange={ ( value ) => updateKindMeta( 'review_best', parseInt( value, 10 ) || 5 ) }
				type="number"
				min="1"
				max="10"
			/>

			<style>{ `
				.reactions-indieweb-star-rating .reactions-indieweb-stars {
					font-size: 24px;
					color: #f5a623;
					margin-bottom: 8px;
					display: block;
				}

				.reactions-indieweb-api-results {
					display: flex;
					flex-direction: column;
					gap: 4px;
					max-height: 200px;
					overflow-y: auto;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 4px;
				}

				.reactions-indieweb-api-result {
					display: flex;
					align-items: center;
					gap: 8px;
					padding: 8px;
					text-align: left;
					width: 100%;
					justify-content: flex-start;
				}

				.reactions-indieweb-api-result img {
					flex-shrink: 0;
					object-fit: cover;
				}

				.reactions-indieweb-api-result span {
					overflow: hidden;
					text-overflow: ellipsis;
				}
			` }</style>
		</VStack>
	);
}
