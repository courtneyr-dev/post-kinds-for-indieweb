/**
 * Play Card Block - Edit Component
 *
 * Full inline editing with theme-aware styling and full sidebar controls.
 *
 * @package Reactions_For_IndieWeb
 */

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
	RangeControl,
	Button,
	ExternalLink,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { StarRating, MediaSearch } from '../shared/components';

/**
 * Status options for games.
 */
const STATUS_OPTIONS = [
	{ label: __( 'Playing', 'reactions-for-indieweb' ), value: 'playing', emoji: '🎮' },
	{ label: __( 'Completed', 'reactions-for-indieweb' ), value: 'completed', emoji: '✅' },
	{ label: __( 'Abandoned', 'reactions-for-indieweb' ), value: 'abandoned', emoji: '⏸️' },
	{ label: __( 'Backlog', 'reactions-for-indieweb' ), value: 'backlog', emoji: '📋' },
	{ label: __( 'Wishlist', 'reactions-for-indieweb' ), value: 'wishlist', emoji: '⭐' },
];

function getStatusInfo( status ) {
	return STATUS_OPTIONS.find( ( s ) => s.value === status ) || STATUS_OPTIONS[ 0 ];
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		platform,
		cover,
		coverAlt,
		status,
		hoursPlayed,
		rating,
		review,
		gameUrl,
		bggId,
		rawgId,
		steamId,
		developer,
		publisher,
		releaseYear,
	} = attributes;

	const [ isSearching, setIsSearching ] = useState( false );

	const blockProps = useBlockProps( {
		className: 'play-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );

	// Get post meta and kind - meta is the source of truth for sidebar sync
	const { currentKind, postMeta } = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
			return {
				currentKind: terms && terms.length > 0 ? terms[ 0 ] : null,
				postMeta: meta,
			};
		},
		[]
	);

	// Helper to update both block attributes AND post meta together
	const updateField = ( attrName, metaKey, value ) => {
		setAttributes( { [ attrName ]: value } );
		editPost( { meta: { [ metaKey ]: value } } );
	};

	// Set post kind to "play" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=play' } )
				.then( ( terms ) => {
					if ( terms && terms.length > 0 ) {
						editPost( { indieblocks_kind: [ terms[ 0 ].id ] } );
					}
				} )
				.catch( () => {} );
		}
	}, [] );

	// Sync FROM post meta TO block attributes when meta changes from sidebar
	// This handles updates from KindFields.js
	useEffect( () => {
		const updates = {};

		// Check each field - sync if meta differs from attribute
		const metaTitle = postMeta._reactions_play_title ?? '';
		const metaPlatform = postMeta._reactions_play_platform ?? '';
		const metaCover = postMeta._reactions_play_cover ?? '';
		const metaStatus = postMeta._reactions_play_status ?? '';
		const metaHours = postMeta._reactions_play_hours ?? 0;
		const metaRating = postMeta._reactions_play_rating ?? 0;
		const metaBggId = postMeta._reactions_play_bgg_id ?? '';
		const metaRawgId = postMeta._reactions_play_rawg_id ?? '';
		const metaSteamId = postMeta._reactions_play_steam_id ?? '';

		if ( metaTitle !== ( title || '' ) ) updates.title = metaTitle;
		if ( metaPlatform !== ( platform || '' ) ) updates.platform = metaPlatform;
		if ( metaCover !== ( cover || '' ) ) updates.cover = metaCover;
		if ( metaStatus !== ( status || '' ) ) updates.status = metaStatus;
		if ( metaHours !== ( hoursPlayed || 0 ) ) updates.hoursPlayed = metaHours;
		if ( metaRating !== ( rating || 0 ) ) updates.rating = metaRating;
		if ( metaBggId !== ( bggId || '' ) ) updates.bggId = metaBggId;
		if ( metaRawgId !== ( rawgId || '' ) ) updates.rawgId = metaRawgId;
		if ( metaSteamId !== ( steamId || '' ) ) updates.steamId = metaSteamId;

		if ( Object.keys( updates ).length > 0 ) {
			setAttributes( updates );
		}
	}, [
		postMeta._reactions_play_title,
		postMeta._reactions_play_platform,
		postMeta._reactions_play_cover,
		postMeta._reactions_play_status,
		postMeta._reactions_play_hours,
		postMeta._reactions_play_rating,
		postMeta._reactions_play_bgg_id,
		postMeta._reactions_play_rawg_id,
		postMeta._reactions_play_steam_id,
	] );

	// Sync FROM block attributes TO post meta when attributes change
	// This handles updates from the block editor UI
	useEffect( () => {
		const metaUpdates = {};

		// Only update if attribute differs from current meta
		if ( ( title || '' ) !== ( postMeta._reactions_play_title ?? '' ) ) {
			metaUpdates._reactions_play_title = title || '';
		}
		if ( ( platform || '' ) !== ( postMeta._reactions_play_platform ?? '' ) ) {
			metaUpdates._reactions_play_platform = platform || '';
		}
		if ( ( cover || '' ) !== ( postMeta._reactions_play_cover ?? '' ) ) {
			metaUpdates._reactions_play_cover = cover || '';
		}
		if ( ( status || '' ) !== ( postMeta._reactions_play_status ?? '' ) ) {
			metaUpdates._reactions_play_status = status || '';
		}
		if ( ( hoursPlayed || 0 ) !== ( postMeta._reactions_play_hours ?? 0 ) ) {
			metaUpdates._reactions_play_hours = hoursPlayed || 0;
		}
		if ( ( rating || 0 ) !== ( postMeta._reactions_play_rating ?? 0 ) ) {
			metaUpdates._reactions_play_rating = rating || 0;
		}
		if ( ( bggId || '' ) !== ( postMeta._reactions_play_bgg_id ?? '' ) ) {
			metaUpdates._reactions_play_bgg_id = bggId || '';
		}
		if ( ( rawgId || '' ) !== ( postMeta._reactions_play_rawg_id ?? '' ) ) {
			metaUpdates._reactions_play_rawg_id = rawgId || '';
		}
		if ( ( steamId || '' ) !== ( postMeta._reactions_play_steam_id ?? '' ) ) {
			metaUpdates._reactions_play_steam_id = steamId || '';
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ title, platform, cover, status, hoursPlayed, rating, bggId, rawgId, steamId ] );

	const handleSearchSelect = ( item ) => {
		// BGG uses 'designers', RAWG uses 'developers'
		const devName = item.designers
			? ( Array.isArray( item.designers ) ? item.designers.join( ', ' ) : item.designers )
			: ( item.developers
				? ( Array.isArray( item.developers ) ? item.developers.join( ', ' ) : item.developers )
				: '' );

		const pubName = item.publishers
			? ( Array.isArray( item.publishers ) ? item.publishers.join( ', ' ) : item.publishers )
			: '';

		setAttributes( {
			title: item.title || item.name || '',
			cover: item.cover || item.image || item.thumbnail || item.background_image || '',
			coverAlt: item.title || item.name || '',
			platform: item.platforms ? ( Array.isArray( item.platforms ) ? item.platforms[ 0 ] : item.platforms ) : '',
			developer: devName,
			publisher: pubName,
			releaseYear: item.year || item.released?.substring( 0, 4 ) || '',
			gameUrl: item.url || '',
			bggId: item.source === 'bgg' ? String( item.id ) : '',
			rawgId: item.source === 'rawg' ? String( item.id ) : '',
		} );
		setIsSearching( false );
	};

	const handleImageSelect = ( media ) => {
		setAttributes( {
			cover: media.url,
			coverAlt: media.alt || title || __( 'Game cover', 'reactions-for-indieweb' ),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { cover: '', coverAlt: '' } );
	};

	const statusInfo = getStatusInfo( status );

	// Build select options for sidebar
	const statusOptions = STATUS_OPTIONS.map( ( s ) => ( {
		label: `${ s.emoji } ${ s.label }`,
		value: s.value,
	} ) );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Find Game', 'reactions-for-indieweb' ) } initialOpen={ ! title }>
					<p className="components-base-control__help" style={ { marginBottom: '12px' } }>
						{ __( 'Search for your game on these sites, then paste the URL below:', 'reactions-for-indieweb' ) }
					</p>
					<div style={ { display: 'flex', gap: '8px', marginBottom: '12px' } }>
						<ExternalLink
							href={ `https://boardgamegeek.com/geeksearch.php?action=search&objecttype=boardgame&q=${ encodeURIComponent( title || '' ) }` }
							style={ { display: 'inline-flex', alignItems: 'center', gap: '4px' } }
						>
							{ __( 'BoardGameGeek', 'reactions-for-indieweb' ) }
						</ExternalLink>
						<ExternalLink
							href={ `https://videogamegeek.com/geeksearch.php?action=search&objecttype=videogame&q=${ encodeURIComponent( title || '' ) }` }
							style={ { display: 'inline-flex', alignItems: 'center', gap: '4px' } }
						>
							{ __( 'VideoGameGeek', 'reactions-for-indieweb' ) }
						</ExternalLink>
					</div>
					<TextControl
						label={ __( 'Paste BGG/VGG URL', 'reactions-for-indieweb' ) }
						value={ gameUrl || '' }
						onChange={ ( value ) => {
							setAttributes( { gameUrl: value } );
							// Extract BGG ID from URL patterns like:
							// https://boardgamegeek.com/boardgame/13/catan
							// https://videogamegeek.com/videogame/12345/game-name
							const bggMatch = value.match( /(?:boardgamegeek|videogamegeek)\.com\/(?:boardgame|videogame|thing)\/(\d+)/ );
							if ( bggMatch ) {
								setAttributes( { bggId: bggMatch[ 1 ] } );
							}
						} }
						placeholder="https://boardgamegeek.com/boardgame/13/catan"
						help={ __( 'The game ID will be extracted automatically.', 'reactions-for-indieweb' ) }
					/>
					{ bggId && (
						<p style={ { marginTop: '4px', color: 'var(--wp-components-color-accent, #007cba)' } }>
							{ __( 'BGG ID:', 'reactions-for-indieweb' ) } { bggId }
						</p>
					) }
					<hr style={ { margin: '16px 0' } } />
					<p className="components-base-control__help" style={ { marginBottom: '8px' } }>
						{ __( 'Or search directly (requires API token):', 'reactions-for-indieweb' ) }
					</p>
					<MediaSearch
						type="game"
						placeholder={ __( 'Search BoardGameGeek...', 'reactions-for-indieweb' ) }
						onSelect={ handleSearchSelect }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Game Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Title', 'reactions-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) => setAttributes( { title: value } ) }
						placeholder={ __( 'Game title', 'reactions-for-indieweb' ) }
					/>
					<SelectControl
						label={ __( 'Status', 'reactions-for-indieweb' ) }
						value={ status || 'playing' }
						options={ statusOptions }
						onChange={ ( value ) => setAttributes( { status: value } ) }
					/>
					<TextControl
						label={ __( 'Platform', 'reactions-for-indieweb' ) }
						value={ platform || '' }
						onChange={ ( value ) => setAttributes( { platform: value } ) }
						placeholder={ __( 'PC, Switch, PS5...', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Developer', 'reactions-for-indieweb' ) }
						value={ developer || '' }
						onChange={ ( value ) => setAttributes( { developer: value } ) }
					/>
					<TextControl
						label={ __( 'Release Year', 'reactions-for-indieweb' ) }
						value={ releaseYear || '' }
						onChange={ ( value ) => setAttributes( { releaseYear: value } ) }
					/>
					<RangeControl
						label={ __( 'Hours Played', 'reactions-for-indieweb' ) }
						value={ hoursPlayed || 0 }
						onChange={ ( value ) => setAttributes( { hoursPlayed: value } ) }
						min={ 0 }
						max={ 500 }
						step={ 0.5 }
					/>
					<RangeControl
						label={ __( 'Rating', 'reactions-for-indieweb' ) }
						value={ rating || 0 }
						onChange={ ( value ) => setAttributes( { rating: value } ) }
						min={ 0 }
						max={ 5 }
						step={ 1 }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Links & IDs', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Game URL', 'reactions-for-indieweb' ) }
						value={ gameUrl || '' }
						onChange={ ( value ) => setAttributes( { gameUrl: value } ) }
						type="url"
					/>
					<TextControl
						label={ __( 'BoardGameGeek ID', 'reactions-for-indieweb' ) }
						value={ bggId || '' }
						onChange={ ( value ) => setAttributes( { bggId: value } ) }
					/>
					<TextControl
						label={ __( 'RAWG ID', 'reactions-for-indieweb' ) }
						value={ rawgId || '' }
						onChange={ ( value ) => setAttributes( { rawgId: value } ) }
					/>
					<TextControl
						label={ __( 'Steam App ID', 'reactions-for-indieweb' ) }
						value={ steamId || '' }
						onChange={ ( value ) => setAttributes( { steamId: value } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Review', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Review', 'reactions-for-indieweb' ) }
						value={ review || '' }
						onChange={ ( value ) => setAttributes( { review: value } ) }
						placeholder={ __( 'Your thoughts...', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="reactions-card-wrapper">
					{ /* Search Bar */ }
					{ isSearching && (
						<div className="reactions-card__search-bar">
							<MediaSearch
								type="game"
								placeholder={ __( 'Search for a game...', 'reactions-for-indieweb' ) }
								onSelect={ handleSearchSelect }
							/>
							<button
								type="button"
								className="reactions-card__search-close"
								onClick={ () => setIsSearching( false ) }
							>
								×
							</button>
						</div>
					) }

					<div className="reactions-card">
						<div className="reactions-card__media" style={ { width: '120px' } }>
							<MediaUploadCheck>
								<MediaUpload
									onSelect={ handleImageSelect }
									allowedTypes={ [ 'image' ] }
									render={ ( { open } ) => (
										<button
											type="button"
											className="reactions-card__media-button"
											onClick={ open }
											style={ { aspectRatio: '3/4' } }
										>
											{ cover ? (
												<>
													<img src={ cover } alt={ coverAlt || title } className="reactions-card__image" />
													<button
														type="button"
														className="reactions-card__media-remove"
														onClick={ handleImageRemove }
														aria-label={ __( 'Remove cover', 'reactions-for-indieweb' ) }
													>
														×
													</button>
												</>
											) : (
												<div className="reactions-card__media-placeholder">
													<span className="reactions-card__media-icon">🎮</span>
													<span className="reactions-card__media-text">{ __( 'Add Cover', 'reactions-for-indieweb' ) }</span>
												</div>
											) }
										</button>
									) }
								/>
							</MediaUploadCheck>
						</div>

						<div className="reactions-card__content">
							<div className="reactions-card__header-row">
								<div className="reactions-card__badges-row">
									<select
										className="reactions-card__type-select"
										value={ status || 'playing' }
										onChange={ ( e ) => setAttributes( { status: e.target.value } ) }
									>
										{ STATUS_OPTIONS.map( ( s ) => (
											<option key={ s.value } value={ s.value }>
												{ s.emoji } { s.label }
											</option>
										) ) }
									</select>
								</div>
								<button
									type="button"
									className="reactions-card__action-button"
									onClick={ () => setIsSearching( true ) }
									title={ __( 'Search for game', 'reactions-for-indieweb' ) }
								>
									🔍
								</button>
							</div>

							<RichText
								tagName="h3"
								className="reactions-card__title"
								value={ title }
								onChange={ ( value ) => setAttributes( { title: value } ) }
								placeholder={ __( 'Game title...', 'reactions-for-indieweb' ) }
							/>

							<RichText
								tagName="p"
								className="reactions-card__subtitle"
								value={ developer }
								onChange={ ( value ) => setAttributes( { developer: value } ) }
								placeholder={ __( 'Developer', 'reactions-for-indieweb' ) }
							/>

							<div className="reactions-card__input-row">
								<span className="reactions-card__input-icon">🎮</span>
								<input
									type="text"
									className="reactions-card__input"
									value={ platform || '' }
									onChange={ ( e ) => setAttributes( { platform: e.target.value } ) }
									placeholder={ __( 'Platform (PC, Switch...)', 'reactions-for-indieweb' ) }
								/>
							</div>

							<div className="reactions-card__input-row">
								<span className="reactions-card__input-icon">⏱️</span>
								<input
									type="number"
									className="reactions-card__input"
									value={ hoursPlayed || '' }
									onChange={ ( e ) => setAttributes( { hoursPlayed: parseFloat( e.target.value ) || 0 } ) }
									placeholder="0"
									min="0"
									step="0.5"
									style={ { maxWidth: '80px' } }
								/>
								<span>{ __( 'hours', 'reactions-for-indieweb' ) }</span>
							</div>

							<div className="reactions-card__rating">
								<StarRating
									value={ rating }
									onChange={ ( value ) => setAttributes( { rating: value } ) }
									max={ 5 }
								/>
							</div>

							<RichText
								tagName="p"
								className="reactions-card__notes"
								value={ review }
								onChange={ ( value ) => setAttributes( { review: value } ) }
								placeholder={ __( 'Your thoughts...', 'reactions-for-indieweb' ) }
							/>
						</div>
					</div>
				</div>
			</div>
		</>
	);
}
