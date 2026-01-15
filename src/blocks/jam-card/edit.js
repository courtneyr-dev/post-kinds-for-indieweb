/**
 * Jam Card Block - Edit Component
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
import { PanelBody, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { MediaSearch } from '../shared/components';

export default function Edit( { attributes, setAttributes } ) {
	const { title, artist, album, cover, coverAlt, url, note } = attributes;

	const [ isSearching, setIsSearching ] = useState( false );

	const blockProps = useBlockProps( {
		className: 'jam-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

	// Set post kind to "jam" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=jam' } )
				.then( ( terms ) => {
					if ( terms && terms.length > 0 ) {
						editPost( { indieblocks_kind: [ terms[ 0 ].id ] } );
					}
				} )
				.catch( () => {} );
		}
	}, [] );

	// Sync block attributes to post meta
	useEffect( () => {
		const metaUpdates = {};
		if ( title !== undefined ) {
			metaUpdates._postkind_jam_title = title || '';
		}
		if ( artist !== undefined ) {
			metaUpdates._postkind_jam_artist = artist || '';
		}
		if ( album !== undefined ) {
			metaUpdates._postkind_jam_album = album || '';
		}
		if ( cover !== undefined ) {
			metaUpdates._postkind_jam_cover = cover || '';
		}
		if ( url !== undefined ) {
			metaUpdates._postkind_jam_url = url || '';
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ title, artist, album, cover, url ] );

	const handleSearchSelect = ( item ) => {
		// MusicBrainz returns 'track', other APIs may return 'title' or 'name'
		const trackName = item.track || item.title || item.name || '';
		setAttributes( {
			title: trackName,
			artist: item.artist || '',
			album: item.album || '',
			cover: item.cover || item.image || '',
			coverAlt: `${ trackName } by ${ item.artist || '' }`,
		} );
		setIsSearching( false );
	};

	const handleImageSelect = ( media ) => {
		setAttributes( {
			cover: media.url,
			coverAlt: media.alt || `${ title } by ${ artist }`,
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { cover: '', coverAlt: '' } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Search Music', 'post-kinds-for-indieweb' ) }
					initialOpen={ ! title }
				>
					<MediaSearch
						type="music"
						placeholder={ __(
							'Search for a song…',
							'post-kinds-for-indieweb'
						) }
						onSelect={ handleSearchSelect }
					/>
					<p
						className="components-base-control__help"
						style={ { marginTop: '8px' } }
					>
						{ __(
							'Search MusicBrainz to auto-fill song details.',
							'post-kinds-for-indieweb'
						) }
					</p>
				</PanelBody>
				<PanelBody
					title={ __( 'Song Details', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Song Title', 'post-kinds-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ __(
							'Song name',
							'post-kinds-for-indieweb'
						) }
					/>
					<TextControl
						label={ __( 'Artist', 'post-kinds-for-indieweb' ) }
						value={ artist || '' }
						onChange={ ( value ) =>
							setAttributes( { artist: value } )
						}
						placeholder={ __(
							'Artist name',
							'post-kinds-for-indieweb'
						) }
					/>
					<TextControl
						label={ __( 'Album', 'post-kinds-for-indieweb' ) }
						value={ album || '' }
						onChange={ ( value ) =>
							setAttributes( { album: value } )
						}
						placeholder={ __(
							'Album name',
							'post-kinds-for-indieweb'
						) }
					/>
					<TextControl
						label={ __( 'Listen URL', 'post-kinds-for-indieweb' ) }
						value={ url || '' }
						onChange={ ( value ) =>
							setAttributes( { url: value } )
						}
						type="url"
						help={ __(
							'Link to Spotify, Apple Music, etc.',
							'post-kinds-for-indieweb'
						) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Note', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Note', 'post-kinds-for-indieweb' ) }
						value={ note || '' }
						onChange={ ( value ) =>
							setAttributes( { note: value } )
						}
						placeholder={ __(
							'Why are you jamming to this?',
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
								type="music"
								placeholder={ __(
									'Search for a song…',
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
						<div className="post-kinds-card__media">
							<MediaUploadCheck>
								<MediaUpload
									onSelect={ handleImageSelect }
									allowedTypes={ [ 'image' ] }
									render={ ( { open } ) => (
										<button
											type="button"
											className="post-kinds-card__media-button"
											onClick={ open }
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
														🎵
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
								<span className="post-kinds-card__badge">
									🎵{ ' ' }
									{ __(
										'Now Playing',
										'post-kinds-for-indieweb'
									) }
								</span>
								<button
									type="button"
									className="post-kinds-card__action-button"
									onClick={ () => setIsSearching( true ) }
									title={ __(
										'Search for music',
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
									'What song are you jamming to?',
									'post-kinds-for-indieweb'
								) }
							/>

							<RichText
								tagName="p"
								className="post-kinds-card__subtitle"
								value={ artist }
								onChange={ ( value ) =>
									setAttributes( { artist: value } )
								}
								placeholder={ __(
									'Artist name…',
									'post-kinds-for-indieweb'
								) }
							/>

							<RichText
								tagName="p"
								className="post-kinds-card__location"
								value={ album }
								onChange={ ( value ) =>
									setAttributes( { album: value } )
								}
								placeholder={ __(
									'Album name…',
									'post-kinds-for-indieweb'
								) }
							/>

							<RichText
								tagName="p"
								className="post-kinds-card__notes"
								value={ note }
								onChange={ ( value ) =>
									setAttributes( { note: value } )
								}
								placeholder={ __(
									'Why are you jamming to this?',
									'post-kinds-for-indieweb'
								) }
							/>
						</div>
					</div>
				</div>
			</div>
		</>
	);
}
