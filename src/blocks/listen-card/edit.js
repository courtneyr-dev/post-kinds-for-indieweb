/**
 * Listen Card Block - Edit Component
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
	Button,
	DateTimePicker,
	Popover,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
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
	} = attributes;

	const [ showDatePicker, setShowDatePicker ] = useState( false );
	const [ isSearching, setIsSearching ] = useState( false );

	const blockProps = useBlockProps( {
		className: `listen-card layout-${ layout }`,
	} );

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
					label={ __( 'Listen Card', 'post-kinds-for-indieweb' ) }
					instructions={ __(
						'Add a track you listened to. Search for music or enter details manually.',
						'post-kinds-for-indieweb'
					) }
				>
					{ isSearching ? (
						<div className="search-mode">
							<MediaSearch
								type="music"
								placeholder={ __(
									'Search for a song or album…',
									'post-kinds-for-indieweb'
								) }
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
									'Search Music',
									'post-kinds-for-indieweb'
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
					title={ __( 'Search Music', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<MediaSearch
						type="music"
						placeholder={ __(
							'Search for a song or album…',
							'post-kinds-for-indieweb'
						) }
						onSelect={ handleSearchSelect }
					/>
					<p
						className="components-base-control__help"
						style={ { marginTop: '8px' } }
					>
						{ __(
							'Search MusicBrainz to auto-fill track details.',
							'post-kinds-for-indieweb'
						) }
					</p>
				</PanelBody>
				<PanelBody
					title={ __( 'Track Details', 'post-kinds-for-indieweb' ) }
				>
					<TextControl
						label={ __( 'Track Title', 'post-kinds-for-indieweb' ) }
						value={ trackTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { trackTitle: value } )
						}
					/>
					<TextControl
						label={ __( 'Artist', 'post-kinds-for-indieweb' ) }
						value={ artistName || '' }
						onChange={ ( value ) =>
							setAttributes( { artistName: value } )
						}
					/>
					<TextControl
						label={ __( 'Album', 'post-kinds-for-indieweb' ) }
						value={ albumTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { albumTitle: value } )
						}
					/>
					<TextControl
						label={ __(
							'Release Date',
							'post-kinds-for-indieweb'
						) }
						value={ releaseDate || '' }
						onChange={ ( value ) =>
							setAttributes( { releaseDate: value } )
						}
						type="date"
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Listen Info', 'post-kinds-for-indieweb' ) }
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

					<div className="components-base-control">
						<span className="components-base-control__label">
							{ __( 'Listened At', 'post-kinds-for-indieweb' ) }
						</span>
						<Button
							variant="secondary"
							onClick={ () => setShowDatePicker( true ) }
							aria-label={ __(
								'Set listened date/time',
								'post-kinds-for-indieweb'
							) }
						>
							{ listenedAt
								? new Date( listenedAt ).toLocaleString()
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
						label={ __( 'Listen URL', 'post-kinds-for-indieweb' ) }
						value={ listenUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { listenUrl: value } )
						}
						type="url"
						help={ __(
							'Link to the track on a streaming service.',
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
						label={ __(
							'MusicBrainz ID',
							'post-kinds-for-indieweb'
						) }
						value={ musicbrainzId || '' }
						onChange={ ( value ) =>
							setAttributes( { musicbrainzId: value } )
						}
						help={ __(
							'Used for linking to music databases.',
							'post-kinds-for-indieweb'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card h-cite">
					<div className="cover-image">
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

					<div className="listen-info">
						<RichText
							tagName="h3"
							className="track-title p-name"
							value={ trackTitle }
							onChange={ ( value ) =>
								setAttributes( { trackTitle: value } )
							}
							placeholder={ __(
								'Track title',
								'post-kinds-for-indieweb'
							) }
						/>

						<RichText
							tagName="p"
							className="artist-name p-author h-card"
							value={ artistName }
							onChange={ ( value ) =>
								setAttributes( { artistName: value } )
							}
							placeholder={ __(
								'Artist name',
								'post-kinds-for-indieweb'
							) }
						/>

						{ ( albumTitle || layout !== 'compact' ) && (
							<RichText
								tagName="p"
								className="album-title"
								value={ albumTitle }
								onChange={ ( value ) =>
									setAttributes( { albumTitle: value } )
								}
								placeholder={ __(
									'Album title',
									'post-kinds-for-indieweb'
								) }
							/>
						) }

						{ rating > 0 && (
							<div className="rating-display">
								<StarRating
									value={ rating }
									readonly={ true }
									max={ 5 }
								/>
							</div>
						) }
					</div>
				</div>
			</div>
		</>
	);
}
