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
	Disabled,
} from '@wordpress/components';
import { useState, useEffect, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';
import { StarRating, MediaSearch } from '../shared/components';
import { suggestPlayStyle, applySuggestedStyle } from '../shared/play-style';

/**
 * Status options for games.
 */
const STATUS_OPTIONS = [
	{
		label: __( 'Playing', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'playing',
		emoji: '🎮',
	},
	{
		label: __( 'Completed', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'completed',
		emoji: '✅',
	},
	{
		label: __( 'Abandoned', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'abandoned',
		emoji: '⏸️',
	},
	{
		label: __( 'Backlog', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'backlog',
		emoji: '📋',
	},
	{
		label: __( 'Wishlist', 'post-kinds-for-indieweb-in-block-themes' ),
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

/**
 * Whether an ID attribute names a provider.
 *
 * @param {string|undefined} id Attribute value.
 * @return {boolean} True for a string that isn't blank.
 */
const namesProvider = ( id ) => 'string' === typeof id && '' !== id.trim();

/**
 * Whether a card is a board game: a BoardGameGeek ID and no RAWG or Steam
 * ID. The server's play_group_of_attrs() files a card the same way, and
 * prints the board game tabletop for it.
 *
 * @param {Object} attributes Block attributes.
 * @return {boolean} True for a board game card.
 */
export function isBoardGame( attributes = {} ) {
	return (
		namesProvider( attributes.bggId ) &&
		! namesProvider( attributes.rawgId ) &&
		! namesProvider( attributes.steamId )
	);
}

/**
 * The host of an http(s) URL, lowercase and without a leading www.
 *
 * @param {string} url URL.
 * @return {string} Host, or '' for anything else.
 */
function urlHost( url ) {
	let parsed;
	try {
		parsed = new URL( String( url ?? '' ).trim() );
	} catch {
		return '';
	}
	if ( ! /^https?:$/.test( parsed.protocol ) ) {
		return '';
	}
	return parsed.hostname
		.toLowerCase()
		.replace( /\.$/, '' )
		.replace( /^www\./, '' );
}

/**
 * The BoardGameGeek ID a board game page URL names.
 *
 * Only boardgamegeek.com /boardgame/<id> and /boardgameexpansion/<id>
 * pages count, as in the Micropub builder. A VideoGameGeek page, or a BGG
 * video game or RPG page, names an ID from another catalog, and a bggId
 * would file that game as a board game (#372).
 *
 * @param {string} url Game URL.
 * @return {string} The ID, or '' when the URL isn't a BGG board game page.
 */
export function bggIdFromUrl( url ) {
	if ( 'boardgamegeek.com' !== urlHost( url ) ) {
		return '';
	}
	const match = new URL( String( url ).trim() ).pathname.match(
		/^\/(?:boardgame|boardgameexpansion)\/(\d+)(?:\/|$)/
	);
	return match ? match[ 1 ] : '';
}

/**
 * A title from the slug of a BoardGameGeek or VideoGameGeek page URL:
 * ".../boardgame/13/wingspan-americas-expansion" gives "Wingspan Americas
 * Expansion".
 *
 * @param {string} url Game URL.
 * @return {string} Title, or '' when the URL has no slug.
 */
function geekSlugTitle( url ) {
	const match = String( url ?? '' ).match(
		/(?:boardgamegeek|videogamegeek)\.com\/(?:boardgame|boardgameexpansion|videogame|videogameexpansion|rpgitem|thing)\/\d+\/([^/?#]+)/
	);
	if ( ! match ) {
		return '';
	}
	return match[ 1 ]
		.split( '-' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );
}

/**
 * Link text for a game URL, by its host, as the server prints it.
 *
 * @param {string} url Game URL.
 * @return {string} 'View on BGG', 'View on RAWG', 'View on Steam', the
 *                  host, or '' when the URL isn't an http(s) URL.
 */
export function gameUrlLabel( url ) {
	const host = urlHost( url );
	switch ( host ) {
		case 'boardgamegeek.com':
			return __(
				'View on BGG',
				'post-kinds-for-indieweb-in-block-themes'
			);
		case 'rawg.io':
			return __(
				'View on RAWG',
				'post-kinds-for-indieweb-in-block-themes'
			);
		case 'store.steampowered.com':
			return __(
				'View on Steam',
				'post-kinds-for-indieweb-in-block-themes'
			);
		default:
			return host;
	}
}

export default function Edit( { attributes, setAttributes, isSelected } ) {
	const {
		className,
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

	// A board game card that isn't selected shows the server's tabletop,
	// so the canvas matches the published single. Selecting it brings the
	// edit UI back.
	const showPreview = ! isSelected && isBoardGame( attributes );

	const blockProps = useBlockProps( {
		className: showPreview
			? 'pk-play-preview'
			: 'play-card-block pk-card k-play',
	} );

	const { editPost } = useDispatch( 'core/editor' );

	// Get post meta and kind - meta is the source of truth for sidebar sync
	const { currentKind, postMeta, postId } = useSelect( ( select ) => {
		const terms = select( 'core/editor' ).getEditedPostAttribute( 'kind' );
		const meta =
			select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		return {
			currentKind: terms && terms.length > 0 ? terms[ 0 ] : null,
			postMeta: meta,
			postId: select( 'core/editor' ).getCurrentPostId(),
		};
	}, [] );

	// Set post kind to "play" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=play' } )
				.then( ( terms ) => {
					if ( terms && terms.length > 0 ) {
						editPost( { kind: [ terms[ 0 ].id ] } );
					}
				} )
				.catch( () => {} );
		}
	}, [] );

	// Two-way sync between block attributes and _pkiw_play_* post meta.
	//
	// Each direction reacts only to changes on ITS OWN side, tracked in a
	// prev-values ref. Diffing against the other side instead is what the
	// old code did, and it cannot work: registered meta reports its
	// schema default ('playing' for status) before anything is saved, so
	// "non-empty meta wins" adopted the default over a real attribute,
	// the attrs -> meta effect pushed the attribute back, and the two
	// writes re-armed each other every commit until React's update-depth
	// limit crashed the block. Any card inserted with a non-default
	// status hit it — paste, pattern, import, or Micropub.
	//
	// On the first commit the block's own content wins: attributes are
	// seeded into meta, never the reverse, so a card's saved markup is
	// authoritative over schema defaults.
	const SYNC_KEYS = [
		[ '_pkiw_play_title', 'title', '' ],
		[ '_pkiw_play_platform', 'platform', '' ],
		[ '_pkiw_play_cover', 'cover', '' ],
		[ '_pkiw_play_status', 'status', '' ],
		[ '_pkiw_play_hours', 'hoursPlayed', 0 ],
		[ '_pkiw_play_rating', 'rating', 0 ],
		[ '_pkiw_play_bgg_id', 'bggId', '' ],
		[ '_pkiw_play_rawg_id', 'rawgId', '' ],
		[ '_pkiw_play_steam_id', 'steamId', '' ],
		[ '_pkiw_play_official_url', 'officialUrl', '' ],
		[ '_pkiw_play_purchase_url', 'purchaseUrl', '' ],
	];

	const attrValues = {
		title: title || '',
		platform: platform || '',
		cover: cover || '',
		status: status || '',
		hoursPlayed: hoursPlayed || 0,
		rating: rating || 0,
		bggId: bggId || '',
		rawgId: rawgId || '',
		steamId: steamId || '',
		officialUrl: officialUrl || '',
		purchaseUrl: purchaseUrl || '',
	};

	const prevMeta = useRef( null );
	const prevAttrs = useRef( null );

	// Sync FROM post meta TO block attributes — sidebar (KindFields) edits.
	useEffect( () => {
		const snapshot = {};
		const updates = {};

		for ( const [ metaKey, attr, empty ] of SYNC_KEYS ) {
			const value = postMeta[ metaKey ] ?? empty;
			snapshot[ metaKey ] = value;

			if (
				prevMeta.current &&
				value !== prevMeta.current[ metaKey ] &&
				value !== attrValues[ attr ]
			) {
				updates[ attr ] = value;
			}
		}

		prevMeta.current = snapshot;

		if ( Object.keys( updates ).length > 0 ) {
			setAttributes( updates );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
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

	// Sync FROM block attributes TO post meta — block editor UI edits.
	// The first run seeds meta from the block's own content.
	useEffect( () => {
		const seeding = ! prevAttrs.current;
		const snapshot = {};
		const metaUpdates = {};

		for ( const [ metaKey, attr, empty ] of SYNC_KEYS ) {
			const value = attrValues[ attr ];
			snapshot[ attr ] = value;

			const changed = seeding
				? value !== empty
				: value !== prevAttrs.current[ attr ];

			if ( changed && value !== ( postMeta[ metaKey ] ?? empty ) ) {
				metaUpdates[ metaKey ] = value;
			}
		}

		prevAttrs.current = snapshot;

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
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
			// A lookup names one provider, so the others go: a stale RAWG or
			// Steam ID would keep a BGG pick filed as a video game.
			bggId: item.source === 'bgg' ? String( item.id ) : '',
			rawgId: item.source === 'rawg' ? String( item.id ) : '',
			steamId: '',
		} );
		const suggested = applySuggestedStyle(
			className,
			suggestPlayStyle( {
				platform: selectedPlatform,
				source: item.source || '',
				title: item.title || item.name || '',
			} )
		);
		if ( suggested ) {
			setAttributes( { className: suggested } );
		}
		setIsSearching( false );
	};

	const handleImageSelect = ( media ) => {
		setAttributes( {
			cover: media.url,
			coverAlt:
				media.alt ||
				title ||
				__( 'Game cover', 'post-kinds-for-indieweb-in-block-themes' ),
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

	// The inspector shows only for the selected block, so the preview needs
	// none.
	if ( showPreview ) {
		return (
			<div { ...blockProps }>
				<Disabled>
					<ServerSideRender
						block="post-kinds-indieweb/play-card"
						attributes={ attributes }
						urlQueryArgs={ postId ? { post_id: postId } : {} }
						skipBlockSupportAttributes
					/>
				</Disabled>
			</div>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Find Game',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ ! title }
				>
					<p
						className="components-base-control__help"
						style={ { marginBottom: '12px' } }
					>
						{ __(
							'Search for your game on these sites, then paste the URL below:',
							'post-kinds-for-indieweb-in-block-themes'
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
							{ __(
								'BoardGameGeek',
								'post-kinds-for-indieweb-in-block-themes'
							) }
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
							{ __(
								'VideoGameGeek',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</ExternalLink>
					</div>
					<TextControl
						label={ __(
							'Paste BGG/VGG URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ gameUrl || '' }
						onChange={ ( value ) => {
							// A BGG board game page sets bggId; any BGG or
							// VGG page with a slug sets the title.
							const updates = { gameUrl: value };
							const pastedBggId = bggIdFromUrl( value );
							if ( pastedBggId ) {
								updates.bggId = pastedBggId;
							}
							const titleFromSlug = geekSlugTitle( value );
							if ( titleFromSlug ) {
								updates.title = titleFromSlug;
							}
							setAttributes( updates );
						} }
						placeholder="https://boardgamegeek.com/boardgame/13/catan"
						help={ __(
							'The game ID will be extracted automatically.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					{ bggId && (
						<p
							style={ {
								marginTop: '4px',
								color: 'var(--wp-components-color-accent, #007cba)',
							} }
						>
							{ __(
								'BGG ID:',
								'post-kinds-for-indieweb-in-block-themes'
							) }{ ' ' }
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
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</p>
					<MediaSearch
						type="game"
						placeholder={ __(
							'Search BoardGameGeek…',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						onSelect={ handleSearchSelect }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Game Details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Title',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ title || '' }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ __(
							'Game title',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<SelectControl
						label={ __(
							'Status',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ status || 'playing' }
						options={ statusOptions }
						onChange={ ( value ) =>
							setAttributes( { status: value } )
						}
					/>
					<SelectControl
						label={ __(
							'Platform',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ getPlatformSelectValue() }
						options={ PLATFORM_OPTIONS }
						onChange={ ( value ) => {
							if ( value === 'other' ) {
								setShowCustomPlatform( true );
								setAttributes( { platform: '' } );
							} else {
								setShowCustomPlatform( false );
								setAttributes( { platform: value } );
								const suggested = applySuggestedStyle(
									className,
									suggestPlayStyle( {
										platform: value,
										title: title || '',
									} )
								);
								if ( suggested ) {
									setAttributes( {
										className: suggested,
									} );
								}
							}
						} }
					/>
					{ ( showCustomPlatform ||
						( platform && ! isPredefinedPlatform ) ) && (
						<TextControl
							label={ __(
								'Custom Platform',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ platform || '' }
							onChange={ ( value ) =>
								setAttributes( { platform: value } )
							}
							placeholder={ __(
								'Enter platform name…',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>
					) }
					<RangeControl
						label={ __(
							'Hours Played',
							'post-kinds-for-indieweb-in-block-themes'
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
						label={ __(
							'Rating',
							'post-kinds-for-indieweb-in-block-themes'
						) }
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
					title={ __(
						'Links',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Official Website',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ officialUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { officialUrl: value } )
						}
						type="url"
						placeholder="https://..."
						help={ __(
							'Link to the official game website.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'Purchase Link',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ purchaseUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { purchaseUrl: value } )
						}
						type="url"
						placeholder="https://amazon.com/..."
						help={ __(
							'Link to buy the game (Amazon, Target, etc).',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<hr style={ { margin: '16px 0' } } />
					<p
						className="components-base-control__help"
						style={ { marginBottom: '8px' } }
					>
						{ __(
							'Database IDs (auto-filled from BGG URL):',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</p>
					<TextControl
						label={ __(
							'BGG/VGG URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ gameUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { gameUrl: value } )
						}
						type="url"
					/>
					<TextControl
						label={ __(
							'BoardGameGeek ID',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ bggId || '' }
						onChange={ ( value ) =>
							setAttributes( { bggId: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Review',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Review',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ review || '' }
						onChange={ ( value ) =>
							setAttributes( { review: value } )
						}
						placeholder={ __(
							'Your thoughts…',
							'post-kinds-for-indieweb-in-block-themes'
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
									'post-kinds-for-indieweb-in-block-themes'
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
										<div className="post-kinds-card__media-frame">
											<button
												type="button"
												className="post-kinds-card__media-button"
												onClick={ open }
												style={ {
													aspectRatio: '3/4',
												} }
											>
												{ cover ? (
													<>
														<img
															src={ cover }
															alt={
																coverAlt ||
																title
															}
															className="post-kinds-card__image"
														/>
													</>
												) : (
													<div className="post-kinds-card__media-placeholder">
														<span className="post-kinds-card__media-icon">
															🎮
														</span>
														<span className="post-kinds-card__media-text">
															{ __(
																'Add Cover',
																'post-kinds-for-indieweb-in-block-themes'
															) }
														</span>
													</div>
												) }
											</button>
											{ cover && (
												<button
													type="button"
													className="post-kinds-card__media-remove"
													onClick={
														handleImageRemove
													}
													aria-label={ __(
														'Remove cover',
														'post-kinds-for-indieweb-in-block-themes'
													) }
												>
													×
												</button>
											) }
										</div>
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
										'post-kinds-for-indieweb-in-block-themes'
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
									'post-kinds-for-indieweb-in-block-themes'
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
										'post-kinds-for-indieweb-in-block-themes'
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
									{ __(
										'hours',
										'post-kinds-for-indieweb-in-block-themes'
									) }
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
									'post-kinds-for-indieweb-in-block-themes'
								) }
							/>

							{ /* Links */ }
							<div className="post-kinds-card__links">
								{ gameUrlLabel( gameUrl ) && (
									<a
										href={ gameUrl }
										className="post-kinds-card__link"
										target="_blank"
										rel="noopener noreferrer"
										onClick={ ( e ) => e.preventDefault() }
									>
										{ gameUrlLabel( gameUrl ) }
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
											'post-kinds-for-indieweb-in-block-themes'
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
											'post-kinds-for-indieweb-in-block-themes'
										) }
									</a>
								) }
							</div>

							{ /* Input fields for links when none are set */ }
							{ ! gameUrl && ! officialUrl && ! purchaseUrl && (
								<div
									className="post-kinds-card__input-row"
									style={ {
										flexWrap: 'wrap',
										gap: '8px',
									} }
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
											'post-kinds-for-indieweb-in-block-themes'
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
											'post-kinds-for-indieweb-in-block-themes'
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
