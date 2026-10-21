/**
 * Listen Card Block - Edit Component
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';
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
	SandBox,
	Disabled,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { listenIcon } from '../shared/icons';
import {
	StarRating,
	CoverImage,
	MediaSearch,
	BlockPlaceholder,
} from '../shared/components';

/**
 * Edit component for the Listen Card block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		trackTitle,
		artistName,
		albumTitle,
		releaseDate,
		coverImage,
		coverImageAlt,
		listenUrl,
		musicbrainzId,
		rating,
		listenedAt,
		layout,
		mediaType,
	} = attributes;
	const isPodcast = 'podcast' === mediaType;

	const [ showDatePicker, setShowDatePicker ] = useState( false );
	const [ isSearching, setIsSearching ] = useState( false );

	const blockProps = useBlockProps( {
		className: `listen-card layout-${ layout } pk-card k-listen`,
	} );

	const embedPreview = useSelect(
		( select ) => {
			if ( ! listenUrl ) {
				return null;
			}
			return select( coreStore ).getEmbedPreview( listenUrl );
		},
		[ listenUrl ]
	);

	/**
	 * Handle media search result selection
	 *
	 * @param {Object} item Selected search result item.
	 */
	const handleSearchSelect = ( item ) => {
		// MusicBrainz returns 'track', other APIs may return 'title' or 'name'
		const trackName = item.track || item.title || item.name || '';
		setAttributes( {
			trackTitle: trackName,
			artistName: item.artist || '',
			albumTitle: item.album || '',
			releaseDate: item.releaseDate || item.date || '',
			coverImage: item.cover || item.image || '',
			coverImageAlt: `${ trackName } by ${ item.artist || '' }`,
			musicbrainzId: item.mbid || item.id || '',
		} );
		setIsSearching( false );
	};

	/**
	 * Handle podcast search result selection
	 *
	 * @param {Object} item Selected PodcastIndex feed.
	 */
	const handlePodcastSelect = ( item ) => {
		const resolvedCoverImage = item.image || item.artwork || '';
		const update = {
			mediaType: 'podcast',
			albumTitle: item.title || '',
			artistName: item.author || '',
			coverImage: resolvedCoverImage,
		};

		if (
			! listenUrl &&
			typeof item.link === 'string' &&
			item.link.trim()
		) {
			update.listenUrl = item.link;
		}

		if ( ! coverImageAlt && resolvedCoverImage && item.title ) {
			update.coverImageAlt = sprintf(
				/* translators: %s: podcast show title. */
				__(
					'Cover art for %s',
					'post-kinds-for-indieweb-in-block-themes'
				),
				item.title
			);
		}

		setAttributes( update );
		setIsSearching( false );
	};

	/**
	 * Handle cover image selection
	 *
	 * @param {Object} media Selected media object.
	 */
	const handleImageSelect = ( media ) => {
		setAttributes( {
			coverImage: media.url,
			coverImageAlt: media.alt || `${ trackTitle } by ${ artistName }`,
		} );
	};

	// Show placeholder if no track info
	if ( ! trackTitle && ! artistName ) {
		return (
			<div { ...blockProps }>
				<BlockPlaceholder
					icon={ listenIcon }
					label={ __(
						'Listen Card',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					instructions={ __(
						'Add a track you listened to. Search for music or enter details manually.',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Media type',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ mediaType || 'music' }
						options={ [
							{
								label: __(
									'Music',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'music',
							},
							{
								label: __(
									'Podcast',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'podcast',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { mediaType: value } )
						}
					/>
					<div className="post-kinds-card__url-prompt">
						<TextControl
							value={ listenUrl || '' }
							onChange={ ( value ) =>
								setAttributes( { listenUrl: value } )
							}
							type="url"
							placeholder={ __(
								'Paste a Spotify, Apple Music, or other URL…',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							label={ __(
								'What are you listening to?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							hideLabelFromVision={ false }
						/>
					</div>
					{ isSearching ? (
						<div className="search-mode">
							<MediaSearch
								type={ isPodcast ? 'podcast' : 'music' }
								placeholder={
									isPodcast
										? __(
												'Search for a podcast…',
												'post-kinds-for-indieweb-in-block-themes'
										  )
										: __(
												'Search for a song or album…',
												'post-kinds-for-indieweb-in-block-themes'
										  )
								}
								onSelect={
									isPodcast
										? handlePodcastSelect
										: handleSearchSelect
								}
							/>
							<Button
								variant="link"
								onClick={ () => setIsSearching( false ) }
							>
								{ __(
									'Enter manually',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							</Button>
						</div>
					) : (
						<div className="placeholder-actions">
							<Button
								variant="primary"
								onClick={ () => setIsSearching( true ) }
							>
								{ isPodcast
									? __(
											'Search Podcasts',
											'post-kinds-for-indieweb-in-block-themes'
									  )
									: __(
											'Search Music',
											'post-kinds-for-indieweb-in-block-themes'
									  ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () =>
									setAttributes( { trackTitle: '' } )
								}
							>
								{ __(
									'Enter Manually',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							</Button>
						</div>
					) }
				</BlockPlaceholder>
			</div>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={
						isPodcast
							? __(
									'Search Podcasts',
									'post-kinds-for-indieweb-in-block-themes'
							  )
							: __(
									'Search Music',
									'post-kinds-for-indieweb-in-block-themes'
							  )
					}
					initialOpen={ false }
				>
					<MediaSearch
						type={ isPodcast ? 'podcast' : 'music' }
						placeholder={
							isPodcast
								? __(
										'Search for a podcast…',
										'post-kinds-for-indieweb-in-block-themes'
								  )
								: __(
										'Search for a song or album…',
										'post-kinds-for-indieweb-in-block-themes'
								  )
						}
						onSelect={
							isPodcast ? handlePodcastSelect : handleSearchSelect
						}
					/>
					<p
						className="components-base-control__help"
						style={ { marginTop: '8px' } }
					>
						{ isPodcast
							? __(
									'Search PodcastIndex to auto-fill show details.',
									'post-kinds-for-indieweb-in-block-themes'
							  )
							: __(
									'Search MusicBrainz to auto-fill track details.',
									'post-kinds-for-indieweb-in-block-themes'
							  ) }
					</p>
				</PanelBody>
				<PanelBody
					title={ __(
						'Track Details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Media type',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ mediaType || 'music' }
						options={ [
							{
								label: __(
									'Music',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'music',
							},
							{
								label: __(
									'Podcast',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'podcast',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { mediaType: value } )
						}
					/>
					<TextControl
						label={
							isPodcast
								? __(
										'Episode',
										'post-kinds-for-indieweb-in-block-themes'
								  )
								: __(
										'Track Title',
										'post-kinds-for-indieweb-in-block-themes'
								  )
						}
						value={ trackTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { trackTitle: value } )
						}
					/>
					<TextControl
						label={ __(
							'Artist',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ artistName || '' }
						onChange={ ( value ) =>
							setAttributes( { artistName: value } )
						}
					/>
					<TextControl
						label={
							isPodcast
								? __(
										'Show',
										'post-kinds-for-indieweb-in-block-themes'
								  )
								: __(
										'Album',
										'post-kinds-for-indieweb-in-block-themes'
								  )
						}
						value={ albumTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { albumTitle: value } )
						}
					/>
					<TextControl
						label={ __(
							'Release Date',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ releaseDate || '' }
						onChange={ ( value ) =>
							setAttributes( { releaseDate: value } )
						}
						type="date"
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Listen Info',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __(
								'Rating',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</span>
						<StarRating
							value={ rating }
							onChange={ ( value ) =>
								setAttributes( { rating: value } )
							}
							max={ 5 }
						/>
					</div>

					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __(
								'Listened At',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</span>
						<Button
							variant="secondary"
							onClick={ () => setShowDatePicker( true ) }
							aria-label={ __(
								'Set listened date/time',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						>
							{ listenedAt
								? new Date( listenedAt ).toLocaleString()
								: __(
										'Set date/time',
										'post-kinds-for-indieweb-in-block-themes'
								  ) }
						</Button>
						{ showDatePicker && (
							<Popover
								onClose={ () => setShowDatePicker( false ) }
							>
								<DateTimePicker
									currentDate={ listenedAt }
									onChange={ ( value ) => {
										setAttributes( { listenedAt: value } );
										setShowDatePicker( false );
									} }
									is12Hour={ true }
								/>
							</Popover>
						) }
					</div>

					<TextControl
						label={ __(
							'Listen URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ listenUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { listenUrl: value } )
						}
						type="url"
						help={ __(
							'Link to the track on a streaming service.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Layout',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Layout Style',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ layout }
						options={ [
							{
								label: __(
									'Horizontal',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'horizontal',
							},
							{
								label: __(
									'Vertical',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'vertical',
							},
							{
								label: __(
									'Compact',
									'post-kinds-for-indieweb-in-block-themes'
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
					title={ __(
						'Metadata',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'MusicBrainz ID',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ musicbrainzId || '' }
						onChange={ ( value ) =>
							setAttributes( { musicbrainzId: value } )
						}
						help={ __(
							'Used for linking to music databases.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card h-cite">
					<span
						className="post-kinds-card__type-icon"
						aria-hidden="true"
					>
						&#127925;
					</span>
					<div className="post-kinds-card__media">
						<MediaUploadCheck>
							<MediaUpload
								onSelect={ handleImageSelect }
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
										<CoverImage
											src={ coverImage }
											alt={ coverImageAlt }
											size="medium"
										/>
									</div>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="post-kinds-card__content">
						<RichText
							tagName="h3"
							className="post-kinds-card__title p-name"
							value={ trackTitle }
							onChange={ ( value ) =>
								setAttributes( { trackTitle: value } )
							}
							placeholder={
								isPodcast
									? __(
											'Episode title',
											'post-kinds-for-indieweb-in-block-themes'
									  )
									: __(
											'Track title',
											'post-kinds-for-indieweb-in-block-themes'
									  )
							}
						/>

						<RichText
							tagName="p"
							className="post-kinds-card__subtitle p-author h-card"
							value={ artistName }
							onChange={ ( value ) =>
								setAttributes( { artistName: value } )
							}
							placeholder={ __(
								'Artist name',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						{ ( albumTitle || layout !== 'compact' ) && (
							<RichText
								tagName="p"
								className="post-kinds-card__meta"
								value={ albumTitle }
								onChange={ ( value ) =>
									setAttributes( { albumTitle: value } )
								}
								placeholder={
									isPodcast
										? __(
												'Show title',
												'post-kinds-for-indieweb-in-block-themes'
										  )
										: __(
												'Album title',
												'post-kinds-for-indieweb-in-block-themes'
										  )
								}
							/>
						) }

						{ rating > 0 && (
							<div className="post-kinds-card__rating">
								<StarRating
									value={ rating }
									readonly={ true }
									max={ 5 }
								/>
							</div>
						) }
					</div>
				</div>
				<div className="post-kinds-card__url-prompt post-kinds-card__url-prompt--inline">
					<TextControl
						value={ listenUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { listenUrl: value } )
						}
						type="url"
						placeholder={ __(
							'Paste a Spotify, Apple Music, or other URL…',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						label={ __(
							'Source URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						hideLabelFromVision={ false }
					/>
				</div>
				{ embedPreview?.html && (
					<div className="post-kinds-card__embed" tabIndex={ -1 }>
						<Disabled>
							<SandBox html={ embedPreview.html } />
						</Disabled>
					</div>
				) }
			</div>
		</>
	);
}
