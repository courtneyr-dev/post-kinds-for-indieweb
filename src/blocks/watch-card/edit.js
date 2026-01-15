/**
 * Watch Card Block - Edit Component
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
	ToggleControl,
	Button,
	DateTimePicker,
	Popover,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { watchIcon } from '../shared/icons';
import {
	StarRating,
	CoverImage,
	MediaSearch,
	BlockPlaceholder,
} from '../shared/components';

/**
 * Edit component for the Watch Card block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		mediaTitle,
		mediaType,
		showTitle,
		seasonNumber,
		episodeNumber,
		episodeTitle,
		releaseYear,
		director,
		posterImage,
		posterImageAlt,
		watchUrl,
		tmdbId,
		imdbId,
		rating,
		isRewatch,
		watchedAt,
		review,
		layout,
	} = attributes;

	const [ showDatePicker, setShowDatePicker ] = useState( false );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ searchType, setSearchType ] = useState( 'movie' );

	const blockProps = useBlockProps( {
		className: `watch-card layout-${ layout } type-${ mediaType }`,
	} );

	/**
	 * Handle media search result selection
	 *
	 * @param {Object} item Selected search result item.
	 */
	const handleSearchSelect = ( item ) => {
		setAttributes( {
			mediaTitle: item.title || item.name || '',
			releaseYear: item.year || item.release_year || null,
			director: item.director || '',
			posterImage: item.poster || item.image || '',
			posterImageAlt: item.title || item.name || '',
			tmdbId: item.tmdb_id || item.id || '',
			imdbId: item.imdb_id || '',
			mediaType: searchType,
		} );
		setIsSearching( false );
	};

	/**
	 * Handle poster image selection
	 *
	 * @param {Object} media Selected media object.
	 */
	const handleImageSelect = ( media ) => {
		setAttributes( {
			posterImage: media.url,
			posterImageAlt: media.alt || mediaTitle,
		} );
	};

	// Show placeholder if no media info
	if ( ! mediaTitle ) {
		return (
			<div { ...blockProps }>
				<BlockPlaceholder
					icon={ watchIcon }
					label={ __( 'Watch Card', 'post-kinds-for-indieweb' ) }
					instructions={ __(
						'Add a movie or TV show you watched. Search or enter details manually.',
						'post-kinds-for-indieweb'
					) }
				>
					{ isSearching ? (
						<div className="search-mode">
							<div className="search-type-toggle">
								<Button
									variant={
										searchType === 'movie'
											? 'primary'
											: 'secondary'
									}
									onClick={ () => setSearchType( 'movie' ) }
								>
									{ __( 'Movie', 'post-kinds-for-indieweb' ) }
								</Button>
								<Button
									variant={
										searchType === 'tv'
											? 'primary'
											: 'secondary'
									}
									onClick={ () => setSearchType( 'tv' ) }
								>
									{ __(
										'TV Show',
										'post-kinds-for-indieweb'
									) }
								</Button>
							</div>
							<MediaSearch
								type={ searchType }
								placeholder={
									searchType === 'movie'
										? __(
												'Search for a movie…',
												'post-kinds-for-indieweb'
										  )
										: __(
												'Search for a TV show…',
												'post-kinds-for-indieweb'
										  )
								}
								onSelect={ handleSearchSelect }
							/>
							<Button
								variant="link"
								onClick={ () => setIsSearching( false ) }
							>
								{ __(
									'Enter manually',
									'post-kinds-for-indieweb'
								) }
							</Button>
						</div>
					) : (
						<div className="placeholder-actions">
							<Button
								variant="primary"
								onClick={ () => setIsSearching( true ) }
							>
								{ __(
									'Search Movies & TV',
									'post-kinds-for-indieweb'
								) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () =>
									setAttributes( { mediaTitle: '' } )
								}
							>
								{ __(
									'Enter Manually',
									'post-kinds-for-indieweb'
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
					title={ __( 'Search', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<div
						className="search-type-toggle"
						style={ {
							marginBottom: '12px',
							display: 'flex',
							gap: '8px',
						} }
					>
						<Button
							variant={
								searchType === 'movie' ? 'primary' : 'secondary'
							}
							onClick={ () => setSearchType( 'movie' ) }
							size="small"
						>
							{ __( 'Movie', 'post-kinds-for-indieweb' ) }
						</Button>
						<Button
							variant={
								searchType === 'tv' ? 'primary' : 'secondary'
							}
							onClick={ () => setSearchType( 'tv' ) }
							size="small"
						>
							{ __( 'TV Show', 'post-kinds-for-indieweb' ) }
						</Button>
					</div>
					<MediaSearch
						type={ searchType }
						placeholder={
							searchType === 'movie'
								? __(
										'Search for a movie…',
										'post-kinds-for-indieweb'
								  )
								: __(
										'Search for a TV show…',
										'post-kinds-for-indieweb'
								  )
						}
						onSelect={ handleSearchSelect }
					/>
					<p
						className="components-base-control__help"
						style={ { marginTop: '8px' } }
					>
						{ __(
							'Search to auto-fill details from TMDB.',
							'post-kinds-for-indieweb'
						) }
					</p>
				</PanelBody>
				<PanelBody
					title={ __( 'Media Details', 'post-kinds-for-indieweb' ) }
				>
					<SelectControl
						label={ __( 'Type', 'post-kinds-for-indieweb' ) }
						value={ mediaType }
						options={ [
							{
								label: __( 'Movie', 'post-kinds-for-indieweb' ),
								value: 'movie',
							},
							{
								label: __(
									'TV Show',
									'post-kinds-for-indieweb'
								),
								value: 'tv',
							},
							{
								label: __(
									'TV Episode',
									'post-kinds-for-indieweb'
								),
								value: 'episode',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { mediaType: value } )
						}
					/>

					<TextControl
						label={ __( 'Title', 'post-kinds-for-indieweb' ) }
						value={ mediaTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { mediaTitle: value } )
						}
					/>

					{ mediaType === 'episode' && (
						<>
							<TextControl
								label={ __(
									'Show Title',
									'post-kinds-for-indieweb'
								) }
								value={ showTitle || '' }
								onChange={ ( value ) =>
									setAttributes( { showTitle: value } )
								}
							/>
							<div className="episode-numbers">
								<TextControl
									type="number"
									label={ __(
										'Season',
										'post-kinds-for-indieweb'
									) }
									value={ seasonNumber }
									onChange={ ( value ) =>
										setAttributes( {
											seasonNumber:
												parseInt( value ) || null,
										} )
									}
									min={ 1 }
								/>
								<TextControl
									type="number"
									label={ __(
										'Episode',
										'post-kinds-for-indieweb'
									) }
									value={ episodeNumber }
									onChange={ ( value ) =>
										setAttributes( {
											episodeNumber:
												parseInt( value ) || null,
										} )
									}
									min={ 1 }
								/>
							</div>
						</>
					) }

					<TextControl
						type="number"
						label={ __( 'Year', 'post-kinds-for-indieweb' ) }
						value={ releaseYear }
						onChange={ ( value ) =>
							setAttributes( {
								releaseYear: parseInt( value ) || null,
							} )
						}
						min={ 1900 }
						max={ 2100 }
					/>

					<TextControl
						label={ __( 'Director', 'post-kinds-for-indieweb' ) }
						value={ director || '' }
						onChange={ ( value ) =>
							setAttributes( { director: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Watch Info', 'post-kinds-for-indieweb' ) }
				>
					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __( 'Rating', 'post-kinds-for-indieweb' ) }
						</span>
						<StarRating
							value={ rating }
							onChange={ ( value ) =>
								setAttributes( { rating: value } )
							}
							max={ 5 }
						/>
					</div>

					<ToggleControl
						label={ __( 'Rewatch', 'post-kinds-for-indieweb' ) }
						checked={ isRewatch }
						onChange={ ( value ) =>
							setAttributes( { isRewatch: value } )
						}
						help={ __(
							'Check if this is a rewatch.',
							'post-kinds-for-indieweb'
						) }
					/>

					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __( 'Watched At', 'post-kinds-for-indieweb' ) }
						</span>
						<Button
							variant="secondary"
							onClick={ () => setShowDatePicker( true ) }
							aria-label={ __(
								'Set watched date/time',
								'post-kinds-for-indieweb'
							) }
						>
							{ watchedAt
								? new Date( watchedAt ).toLocaleString()
								: __(
										'Set date/time',
										'post-kinds-for-indieweb'
								  ) }
						</Button>
						{ showDatePicker && (
							<Popover
								onClose={ () => setShowDatePicker( false ) }
							>
								<DateTimePicker
									currentDate={ watchedAt }
									onChange={ ( value ) => {
										setAttributes( { watchedAt: value } );
										setShowDatePicker( false );
									} }
									is12Hour={ true }
								/>
							</Popover>
						) }
					</div>

					<TextControl
						label={ __( 'Watch URL', 'post-kinds-for-indieweb' ) }
						value={ watchUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { watchUrl: value } )
						}
						type="url"
						help={ __(
							'Link to the content on a streaming service.',
							'post-kinds-for-indieweb'
						) }
					/>
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
									'Poster Focus',
									'post-kinds-for-indieweb'
								),
								value: 'poster',
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
					title={ __( 'Metadata', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'TMDB ID', 'post-kinds-for-indieweb' ) }
						value={ tmdbId || '' }
						onChange={ ( value ) =>
							setAttributes( { tmdbId: value } )
						}
					/>
					<TextControl
						label={ __( 'IMDb ID', 'post-kinds-for-indieweb' ) }
						value={ imdbId || '' }
						onChange={ ( value ) =>
							setAttributes( { imdbId: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card h-cite">
					<div className="poster-image">
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
											src={ posterImage }
											alt={ posterImageAlt }
											size="large"
										/>
									</div>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="watch-info">
						{ mediaType === 'episode' && showTitle && (
							<p className="show-title">{ showTitle }</p>
						) }

						<RichText
							tagName="h3"
							className="media-title p-name"
							value={ mediaTitle }
							onChange={ ( value ) =>
								setAttributes( { mediaTitle: value } )
							}
							placeholder={ __(
								'Title',
								'post-kinds-for-indieweb'
							) }
						/>

						{ mediaType === 'episode' &&
							( seasonNumber || episodeNumber ) && (
								<p className="episode-info">
									{ seasonNumber &&
										`S${ String( seasonNumber ).padStart(
											2,
											'0'
										) }` }
									{ episodeNumber &&
										`E${ String( episodeNumber ).padStart(
											2,
											'0'
										) }` }
									{ episodeTitle && ` - ${ episodeTitle }` }
								</p>
							) }

						<div className="meta-line">
							{ releaseYear && (
								<span className="year">({ releaseYear })</span>
							) }
							{ director && (
								<span className="director">
									{ __( 'Dir.', 'post-kinds-for-indieweb' ) }{ ' ' }
									{ director }
								</span>
							) }
							{ isRewatch && (
								<span className="rewatch-badge">
									{ __(
										'Rewatch',
										'post-kinds-for-indieweb'
									) }
								</span>
							) }
						</div>

						{ rating > 0 && (
							<div className="rating-display">
								<StarRating
									value={ rating }
									readonly={ true }
									max={ 5 }
								/>
							</div>
						) }

						<RichText
							tagName="p"
							className="watch-review"
							value={ review }
							onChange={ ( value ) =>
								setAttributes( { review: value } )
							}
							placeholder={ __(
								'Write a review…',
								'post-kinds-for-indieweb'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
