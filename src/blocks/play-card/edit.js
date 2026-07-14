/**
 * Play Card Block - Edit Component
 *
 * Full inline editing with theme-aware styling and full sidebar controls.
 *
 * @package
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
	ExternalLink,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { StarRating, MediaSearch } from '../shared/components';

/**
 * Status options for games.
 */
const STATUS_OPTIONS = [
	{
		label: __( 'Playing', 'post-kinds-for-indieweb' ),
		value: 'playing',
		emoji: '🎮',
	},
	{
		label: __( 'Completed', 'post-kinds-for-indieweb' ),
		value: 'completed',
		emoji: '✅',
	},
	{
		label: __( 'Abandoned', 'post-kinds-for-indieweb' ),
		value: 'abandoned',
		emoji: '⏸️',
	},
	{
		label: __( 'Backlog', 'post-kinds-for-indieweb' ),
		value: 'backlog',
		emoji: '📋',
	},
	{
		label: __( 'Wishlist', 'post-kinds-for-indieweb' ),
		value: 'wishlist',
		emoji: '⭐',
	},
];

/**
 * Platform options for games.
 */
const PLATFORM_OPTIONS = [
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
		officialUrl,
		purchaseUrl,
	} = attributes;

	const [ isSearching, setIsSearching ] = useState( false );
	const [ showCustomPlatform, setShowCustomPlatform ] = useState( false );

	// Check if current platform is a predefined option
	const isPredefinedPlatform = PLATFORM_OPTIONS.some(
		( opt ) =>
			opt.value === platform && opt.value !== 'other' && opt.value !== ''
	);

	/**
	 * Get the platform select value based on current platform state.
	 *
	 * @return {string} The value for the platform SelectControl.
	 */
	const getPlatformSelectValue = () => {
		if ( isPredefinedPlatform ) {
			return platform;
		}
		return platform ? 'other' : '';
	};

	const blockProps = useBlockProps( {
		className: 'play-card-block pk-card k-play',
	} );

	const { editPost } = useDispatch( 'core/editor' );

	// Get post meta and kind - meta is the source of truth for sidebar sync
	const { currentKind, postMeta } = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		const meta =
			select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		return {
			currentKind: terms && terms.length > 0 ? terms[ 0 ] : null,
			postMeta: meta,
		};
	}, [] );

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

	// Sync FROM post meta TO block attributes when meta changes from sidebar.
	// This handles updates from KindFields.js.
	// _pkiw_play_* isn't a registered post meta key (no REST schema
	// entry, so WordPress core silently drops writes to it and the key is
	// never actually persisted server-side) — only ever apply a non-empty
	// meta value here. Without this guard, a fresh/just-inserted block's
	// attribute gets raced back to blank: the "sync attrs -> meta" effect
	// below writes meta asynchronously via editPost(), and depending on
	// render timing this effect can still observe the pre-write ''/undefined
	// meta value and stomp a real attribute with it.
	useEffect( () => {
		const updates = {};

		const metaTitle = postMeta._pkiw_play_title;
		if ( metaTitle && metaTitle !== ( title || '' ) ) {
			updates.title = metaTitle;
		}
		const metaPlatform = postMeta._pkiw_play_platform;
		if ( metaPlatform && metaPlatform !== ( platform || '' ) ) {
			updates.platform = metaPlatform;
		}
		const metaCover = postMeta._pkiw_play_cover;
		if ( metaCover && metaCover !== ( cover || '' ) ) {
			updates.cover = metaCover;
		}
		const metaStatus = postMeta._pkiw_play_status;
		if ( metaStatus && metaStatus !== ( status || '' ) ) {
			updates.status = metaStatus;
		}
		const metaHours = postMeta._pkiw_play_hours;
		if ( metaHours && metaHours !== ( hoursPlayed || 0 ) ) {
			updates.hoursPlayed = metaHours;
		}
		const metaRating = postMeta._pkiw_play_rating;
		if ( metaRating && metaRating !== ( rating || 0 ) ) {
			updates.rating = metaRating;
		}
		const metaBggId = postMeta._pkiw_play_bgg_id;
		if ( metaBggId && metaBggId !== ( bggId || '' ) ) {
			updates.bggId = metaBggId;
		}
		const metaRawgId = postMeta._pkiw_play_rawg_id;
		if ( metaRawgId && metaRawgId !== ( rawgId || '' ) ) {
			updates.rawgId = metaRawgId;
		}
		const metaSteamId = postMeta._pkiw_play_steam_id;
		if ( metaSteamId && metaSteamId !== ( steamId || '' ) ) {
			updates.steamId = metaSteamId;
		}
		const metaOfficialUrl = postMeta._pkiw_play_official_url;
		if ( metaOfficialUrl && metaOfficialUrl !== ( officialUrl || '' ) ) {
			updates.officialUrl = metaOfficialUrl;
		}
		const metaPurchaseUrl = postMeta._pkiw_play_purchase_url;
		if ( metaPurchaseUrl && metaPurchaseUrl !== ( purchaseUrl || '' ) ) {
			updates.purchaseUrl = metaPurchaseUrl;
		}

		if ( Object.keys( updates ).length > 0 ) {
			setAttributes( updates );
		}
	}, [
		postMeta._pkiw_play_title,
		postMeta._pkiw_play_platform,
		postMeta._pkiw_play_cover,
		postMeta._pkiw_play_status,
		postMeta._pkiw_play_hours,
		postMeta._pkiw_play_rating,
		postMeta._pkiw_play_bgg_id,
		postMeta._pkiw_play_rawg_id,
		postMeta._pkiw_play_steam_id,
		postMeta._pkiw_play_official_url,
		postMeta._pkiw_play_purchase_url,
	] );

	// Sync FROM block attributes TO post meta when attributes change
	// This handles updates from the block editor UI
	useEffect( () => {
		const metaUpdates = {};

		// Only update if attribute differs from current meta
		if ( ( title || '' ) !== ( postMeta._pkiw_play_title ?? '' ) ) {
			metaUpdates._pkiw_play_title = title || '';
		}
		if ( ( platform || '' ) !== ( postMeta._pkiw_play_platform ?? '' ) ) {
			metaUpdates._pkiw_play_platform = platform || '';
		}
		if ( ( cover || '' ) !== ( postMeta._pkiw_play_cover ?? '' ) ) {
			metaUpdates._pkiw_play_cover = cover || '';
		}
		if ( ( status || '' ) !== ( postMeta._pkiw_play_status ?? '' ) ) {
			metaUpdates._pkiw_play_status = status || '';
		}
		if ( ( hoursPlayed || 0 ) !== ( postMeta._pkiw_play_hours ?? 0 ) ) {
			metaUpdates._pkiw_play_hours = hoursPlayed || 0;
		}
		if ( ( rating || 0 ) !== ( postMeta._pkiw_play_rating ?? 0 ) ) {
			metaUpdates._pkiw_play_rating = rating || 0;
		}
		if ( ( bggId || '' ) !== ( postMeta._pkiw_play_bgg_id ?? '' ) ) {
			metaUpdates._pkiw_play_bgg_id = bggId || '';
		}
		if ( ( rawgId || '' ) !== ( postMeta._pkiw_play_rawg_id ?? '' ) ) {
			metaUpdates._pkiw_play_rawg_id = rawgId || '';
		}
		if ( ( steamId || '' ) !== ( postMeta._pkiw_play_steam_id ?? '' ) ) {
			metaUpdates._pkiw_play_steam_id = steamId || '';
		}
		if (
			( officialUrl || '' ) !== ( postMeta._pkiw_play_official_url ?? '' )
		) {
			metaUpdates._pkiw_play_official_url = officialUrl || '';
		}
		if (
			( purchaseUrl || '' ) !== ( postMeta._pkiw_play_purchase_url ?? '' )
		) {
			metaUpdates._pkiw_play_purchase_url = purchaseUrl || '';
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [
		title,
		platform,
		cover,
		status,
		hoursPlayed,
		rating,
		bggId,
		rawgId,
		steamId,
		officialUrl,
		purchaseUrl,
	] );

	const handleSearchSelect = ( item ) => {
		// Extract platform from item, handling array or string format.
		let selectedPlatform = '';
		if ( item.platforms ) {
			selectedPlatform = Array.isArray( item.platforms )
				? item.platforms[ 0 ]
				: item.platforms;
		}

		setAttributes( {
			title: item.title || item.name || '',
			cover:
				item.cover ||
				item.image ||
				item.thumbnail ||
				item.background_image ||
				'',
			coverAlt: item.title || item.name || '',
			platform: selectedPlatform,
			gameUrl: item.url || '',
			bggId: item.source === 'bgg' ? String( item.id ) : '',
			rawgId: item.source === 'rawg' ? String( item.id ) : '',
		} );
		setIsSearching( false );
	};

	const handleImageSelect = ( media ) => {
		setAttributes( {
			cover: media.url,
			coverAlt:
				media.alt ||
				title ||
				__( 'Game cover', 'post-kinds-for-indieweb' ),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { cover: '', coverAlt: '' } );
	};

	// Build select options for sidebar
	const statusOptions = STATUS_OPTIONS.map( ( s ) => ( {
		label: `${ s.emoji } ${ s.label }`,
		value: s.value,
	} ) );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Find Game', 'post-kinds-for-indieweb' ) }
					initialOpen={ ! title }
				>
					<p
						className="components-base-control__help"
						style={ { marginBottom: '12px' } }
					>
						{ __(
							'Search for your game on these sites, then paste the URL below:',
							'post-kinds-for-indieweb'
						) }
					</p>
					<div
						style={ {
							display: 'flex',
							gap: '8px',
							marginBottom: '12px',
						} }
					>
						<ExternalLink
							href={ `https://boardgamegeek.com/geeksearch.php?action=search&objecttype=boardgame&q=${ encodeURIComponent(
								title || ''
							) }` }
							style={ {
								display: 'inline-flex',
								alignItems: 'center',
								gap: '4px',
							} }
						>
							{ __( 'BoardGameGeek', 'post-kinds-for-indieweb' ) }
						</ExternalLink>
						<ExternalLink
							href={ `https://videogamegeek.com/geeksearch.php?action=search&objecttype=videogame&q=${ encodeURIComponent(
								title || ''
							) }` }
							style={ {
								display: 'inline-flex',
								alignItems: 'center',
								gap: '4px',
							} }
						>
							{ __( 'VideoGameGeek', 'post-kinds-for-indieweb' ) }
						</ExternalLink>
					</div>
					<TextControl
						label={ __(
							'Paste BGG/VGG URL',
							'post-kinds-for-indieweb'
						) }
						value={ gameUrl || '' }
						onChange={ ( value ) => {
							setAttributes( { gameUrl: value } );
							// Extract BGG ID and title from URL patterns like:
							// https://boardgamegeek.com/boardgame/13/catan
							// https://boardgamegeek.com/boardgameexpansion/461932/wingspan-americas-expansion
							// https://videogamegeek.com/videogame/12345/game-name
							const bggMatch = value.match(
								/(?:boardgamegeek|videogamegeek)\.com\/(?:boardgame|boardgameexpansion|videogame|videogameexpansion|rpgitem|thing)\/(\d+)(?:\/([^/?#]+))?/
							);
							if ( bggMatch ) {
								const updates = { bggId: bggMatch[ 1 ] };
								// Extract title from URL slug if present
								if ( bggMatch[ 2 ] ) {
									// Convert slug to title: "wingspan-americas-expansion" -> "Wingspan Americas Expansion"
									const titleFromSlug = bggMatch[ 2 ]
										.split( '-' )
										.map(
											( word ) =>
												word.charAt( 0 ).toUpperCase() +
												word.slice( 1 )
										)
										.join( ' ' );
									updates.title = titleFromSlug;
								}
								setAttributes( updates );
							}
						} }
						placeholder="https://boardgamegeek.com/boardgame/13/catan"
						help={ __(
							'The game ID will be extracted automatically.',
							'post-kinds-for-indieweb'
						) }
					/>
					{ bggId && (
						<p
							style={ {
								marginTop: '4px',
								color: 'var(--wp-components-color-accent, #007cba)',
							} }
						>
							{ __( 'BGG ID:', 'post-kinds-for-indieweb' ) }{ ' ' }
							{ bggId }
						</p>
					) }
					<hr style={ { margin: '16px 0' } } />
					<p
						className="components-base-control__help"
						style={ { marginBottom: '8px' } }
					>
						{ __(
							'Or search directly (requires API token):',
							'post-kinds-for-indieweb'
						) }
					</p>
					<MediaSearch
						type="game"
						placeholder={ __(
							'Search BoardGameGeek…',
							'post-kinds-for-indieweb'
						) }
						onSelect={ handleSearchSelect }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Game Details', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Title', 'post-kinds-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ __(
							'Game title',
							'post-kinds-for-indieweb'
						) }
					/>
					<SelectControl
						label={ __( 'Status', 'post-kinds-for-indieweb' ) }
						value={ status || 'playing' }
						options={ statusOptions }
						onChange={ ( value ) =>
							setAttributes( { status: value } )
						}
					/>
					<SelectControl
						label={ __( 'Platform', 'post-kinds-for-indieweb' ) }
						value={ getPlatformSelectValue() }
						options={ PLATFORM_OPTIONS }
						onChange={ ( value ) => {
							if ( value === 'other' ) {
								setShowCustomPlatform( true );
								setAttributes( { platform: '' } );
							} else {
								setShowCustomPlatform( false );
								setAttributes( { platform: value } );
							}
						} }
					/>
					{ ( showCustomPlatform ||
						( platform && ! isPredefinedPlatform ) ) && (
						<TextControl
							label={ __(
								'Custom Platform',
								'post-kinds-for-indieweb'
							) }
							value={ platform || '' }
							onChange={ ( value ) =>
								setAttributes( { platform: value } )
							}
							placeholder={ __(
								'Enter platform name…',
								'post-kinds-for-indieweb'
							) }
						/>
					) }
					<RangeControl
						label={ __(
							'Hours Played',
							'post-kinds-for-indieweb'
						) }
						value={ hoursPlayed || 0 }
						onChange={ ( value ) =>
							setAttributes( { hoursPlayed: value } )
						}
						min={ 0 }
						max={ 500 }
						step={ 0.5 }
					/>
					<RangeControl
						label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
						value={ rating || 0 }
						onChange={ ( value ) =>
							setAttributes( { rating: value } )
						}
						min={ 0 }
						max={ 5 }
						step={ 1 }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Links', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Official Website',
							'post-kinds-for-indieweb'
						) }
						value={ officialUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { officialUrl: value } )
						}
						type="url"
						placeholder="https://..."
						help={ __(
							'Link to the official game website.',
							'post-kinds-for-indieweb'
						) }
					/>
					<TextControl
						label={ __(
							'Purchase Link',
							'post-kinds-for-indieweb'
						) }
						value={ purchaseUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { purchaseUrl: value } )
						}
						type="url"
						placeholder="https://amazon.com/..."
						help={ __(
							'Link to buy the game (Amazon, Target, etc).',
							'post-kinds-for-indieweb'
						) }
					/>
					<hr style={ { margin: '16px 0' } } />
					<p
						className="components-base-control__help"
						style={ { marginBottom: '8px' } }
					>
						{ __(
							'Database IDs (auto-filled from BGG URL):',
							'post-kinds-for-indieweb'
						) }
					</p>
					<TextControl
						label={ __( 'BGG/VGG URL', 'post-kinds-for-indieweb' ) }
						value={ gameUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { gameUrl: value } )
						}
						type="url"
					/>
					<TextControl
						label={ __(
							'BoardGameGeek ID',
							'post-kinds-for-indieweb'
						) }
						value={ bggId || '' }
						onChange={ ( value ) =>
							setAttributes( { bggId: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Review', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Review', 'post-kinds-for-indieweb' ) }
						value={ review || '' }
						onChange={ ( value ) =>
							setAttributes( { review: value } )
						}
						placeholder={ __(
							'Your thoughts…',
							'post-kinds-for-indieweb'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card-wrapper">
					{ /* Search Bar */ }
					{ isSearching && (
						<div className="post-kinds-card__search-bar">
							<MediaSearch
								type="game"
								placeholder={ __(
									'Search for a game…',
									'post-kinds-for-indieweb'
								) }
								onSelect={ handleSearchSelect }
							/>
							<button
								type="button"
								className="post-kinds-card__search-close"
								onClick={ () => setIsSearching( false ) }
							>
								×
							</button>
						</div>
					) }

					<div className="post-kinds-card">
						<div
							className="post-kinds-card__media"
							style={ { width: '120px' } }
						>
							<MediaUploadCheck>
								<MediaUpload
									onSelect={ handleImageSelect }
									allowedTypes={ [ 'image' ] }
									render={ ( { open } ) => (
										<button
											type="button"
											className="post-kinds-card__media-button"
											onClick={ open }
											style={ { aspectRatio: '3/4' } }
										>
											{ cover ? (
												<>
													<img
														src={ cover }
														alt={
															coverAlt || title
														}
														className="post-kinds-card__image"
													/>
													<button
														type="button"
														className="post-kinds-card__media-remove"
														onClick={
															handleImageRemove
														}
														aria-label={ __(
															'Remove cover',
															'post-kinds-for-indieweb'
														) }
													>
														×
													</button>
												</>
											) : (
												<div className="post-kinds-card__media-placeholder">
													<span className="post-kinds-card__media-icon">
														🎮
													</span>
													<span className="post-kinds-card__media-text">
														{ __(
															'Add Cover',
															'post-kinds-for-indieweb'
														) }
													</span>
												</div>
											) }
										</button>
									) }
								/>
							</MediaUploadCheck>
						</div>

						<div className="post-kinds-card__content">
							<div className="post-kinds-card__header-row">
								<div className="post-kinds-card__badges-row">
									<select
										className="post-kinds-card__type-select"
										value={ status || 'playing' }
										onChange={ ( e ) =>
											setAttributes( {
												status: e.target.value,
											} )
										}
									>
										{ STATUS_OPTIONS.map( ( s ) => (
											<option
												key={ s.value }
												value={ s.value }
											>
												{ s.emoji } { s.label }
											</option>
										) ) }
									</select>
								</div>
								<button
									type="button"
									className="post-kinds-card__action-button"
									onClick={ () => setIsSearching( true ) }
									title={ __(
										'Search for game',
										'post-kinds-for-indieweb'
									) }
								>
									🔍
								</button>
							</div>

							<RichText
								tagName="h3"
								className="post-kinds-card__title"
								value={ title }
								onChange={ ( value ) =>
									setAttributes( { title: value } )
								}
								placeholder={ __(
									'Game title…',
									'post-kinds-for-indieweb'
								) }
							/>

							<div className="post-kinds-card__input-row">
								<span className="post-kinds-card__input-icon">
									🎮
								</span>
								<input
									type="text"
									className="post-kinds-card__input"
									value={ platform || '' }
									onChange={ ( e ) =>
										setAttributes( {
											platform: e.target.value,
										} )
									}
									placeholder={ __(
										'Platform (PC, Switch…)',
										'post-kinds-for-indieweb'
									) }
								/>
							</div>

							<div className="post-kinds-card__input-row">
								<span className="post-kinds-card__input-icon">
									⏱️
								</span>
								<input
									type="number"
									className="post-kinds-card__input"
									value={ hoursPlayed || '' }
									onChange={ ( e ) =>
										setAttributes( {
											hoursPlayed:
												parseFloat( e.target.value ) ||
												0,
										} )
									}
									placeholder="0"
									min="0"
									step="0.5"
									style={ { maxWidth: '80px' } }
								/>
								<span>
									{ __( 'hours', 'post-kinds-for-indieweb' ) }
								</span>
							</div>

							<div className="post-kinds-card__rating">
								<StarRating
									value={ rating }
									onChange={ ( value ) =>
										setAttributes( { rating: value } )
									}
									max={ 5 }
								/>
							</div>

							<RichText
								tagName="p"
								className="post-kinds-card__notes"
								value={ review }
								onChange={ ( value ) =>
									setAttributes( { review: value } )
								}
								placeholder={ __(
									'Your thoughts…',
									'post-kinds-for-indieweb'
								) }
							/>

							{ /* Links */ }
							<div className="post-kinds-card__links">
								{ gameUrl && (
									<a
										href={ gameUrl }
										className="post-kinds-card__link"
										target="_blank"
										rel="noopener noreferrer"
										onClick={ ( e ) => e.preventDefault() }
									>
										{ __(
											'View on BGG',
											'post-kinds-for-indieweb'
										) }
									</a>
								) }
								{ officialUrl && (
									<a
										href={ officialUrl }
										className="post-kinds-card__link"
										target="_blank"
										rel="noopener noreferrer"
										onClick={ ( e ) => e.preventDefault() }
									>
										{ __(
											'Official Site',
											'post-kinds-for-indieweb'
										) }
									</a>
								) }
								{ purchaseUrl && (
									<a
										href={ purchaseUrl }
										className="post-kinds-card__link post-kinds-card__link--buy"
										target="_blank"
										rel="noopener noreferrer"
										onClick={ ( e ) => e.preventDefault() }
									>
										{ __(
											'Buy',
											'post-kinds-for-indieweb'
										) }
									</a>
								) }
							</div>

							{ /* Input fields for links when none are set */ }
							{ ! gameUrl && ! officialUrl && ! purchaseUrl && (
								<div
									className="post-kinds-card__input-row"
									style={ { flexWrap: 'wrap', gap: '8px' } }
								>
									<input
										type="url"
										className="post-kinds-card__input post-kinds-card__input--url"
										value={ officialUrl || '' }
										onChange={ ( e ) =>
											setAttributes( {
												officialUrl: e.target.value,
											} )
										}
										placeholder={ __(
											'Official website URL…',
											'post-kinds-for-indieweb'
										) }
										style={ {
											flex: '1',
											minWidth: '150px',
										} }
									/>
									<input
										type="url"
										className="post-kinds-card__input post-kinds-card__input--url"
										value={ purchaseUrl || '' }
										onChange={ ( e ) =>
											setAttributes( {
												purchaseUrl: e.target.value,
											} )
										}
										placeholder={ __(
											'Purchase URL…',
											'post-kinds-for-indieweb'
										) }
										style={ {
											flex: '1',
											minWidth: '150px',
										} }
									/>
								</div>
							) }
						</div>
					</div>
				</div>
			</div>
		</>
	);
}
