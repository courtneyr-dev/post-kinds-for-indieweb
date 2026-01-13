/**
 * Jam Card Block - Edit Component
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
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { MediaSearch } from '../shared/components';

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		artist,
		album,
		cover,
		coverAlt,
		url,
		note,
	} = attributes;

	const [ isSearching, setIsSearching ] = useState( false );

	const blockProps = useBlockProps( {
		className: 'jam-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			return terms && terms.length > 0 ? terms[ 0 ] : null;
		},
		[]
	);

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
		if ( title !== undefined ) metaUpdates._reactions_jam_title = title || '';
		if ( artist !== undefined ) metaUpdates._reactions_jam_artist = artist || '';
		if ( album !== undefined ) metaUpdates._reactions_jam_album = album || '';
		if ( cover !== undefined ) metaUpdates._reactions_jam_cover = cover || '';
		if ( url !== undefined ) metaUpdates._reactions_jam_url = url || '';

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
				<PanelBody title={ __( 'Search Music', 'reactions-for-indieweb' ) } initialOpen={ ! title }>
					<MediaSearch
						type="music"
						placeholder={ __( 'Search for a song...', 'reactions-for-indieweb' ) }
						onSelect={ handleSearchSelect }
					/>
					<p className="components-base-control__help" style={ { marginTop: '8px' } }>
						{ __( 'Search MusicBrainz to auto-fill song details.', 'reactions-for-indieweb' ) }
					</p>
				</PanelBody>
				<PanelBody title={ __( 'Song Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Song Title', 'reactions-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) => setAttributes( { title: value } ) }
						placeholder={ __( 'Song name', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Artist', 'reactions-for-indieweb' ) }
						value={ artist || '' }
						onChange={ ( value ) => setAttributes( { artist: value } ) }
						placeholder={ __( 'Artist name', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Album', 'reactions-for-indieweb' ) }
						value={ album || '' }
						onChange={ ( value ) => setAttributes( { album: value } ) }
						placeholder={ __( 'Album name', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Listen URL', 'reactions-for-indieweb' ) }
						value={ url || '' }
						onChange={ ( value ) => setAttributes( { url: value } ) }
						type="url"
						help={ __( 'Link to Spotify, Apple Music, etc.', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Note', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Note', 'reactions-for-indieweb' ) }
						value={ note || '' }
						onChange={ ( value ) => setAttributes( { note: value } ) }
						placeholder={ __( 'Why are you jamming to this?', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="reactions-card-wrapper">
					{ /* Search Bar */ }
					{ isSearching && (
						<div className="reactions-card__search-bar">
							<MediaSearch
								type="music"
								placeholder={ __( 'Search for a song...', 'reactions-for-indieweb' ) }
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
						<div className="reactions-card__media">
							<MediaUploadCheck>
								<MediaUpload
									onSelect={ handleImageSelect }
									allowedTypes={ [ 'image' ] }
									render={ ( { open } ) => (
										<button
											type="button"
											className="reactions-card__media-button"
											onClick={ open }
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
													<span className="reactions-card__media-icon">🎵</span>
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
								<span className="reactions-card__badge">🎵 { __( 'Now Playing', 'reactions-for-indieweb' ) }</span>
								<button
									type="button"
									className="reactions-card__action-button"
									onClick={ () => setIsSearching( true ) }
									title={ __( 'Search for music', 'reactions-for-indieweb' ) }
								>
									🔍
								</button>
							</div>

							<RichText
								tagName="h3"
								className="reactions-card__title"
								value={ title }
								onChange={ ( value ) => setAttributes( { title: value } ) }
								placeholder={ __( 'What song are you jamming to?', 'reactions-for-indieweb' ) }
							/>

							<RichText
								tagName="p"
								className="reactions-card__subtitle"
								value={ artist }
								onChange={ ( value ) => setAttributes( { artist: value } ) }
								placeholder={ __( 'Artist name...', 'reactions-for-indieweb' ) }
							/>

							<RichText
								tagName="p"
								className="reactions-card__location"
								value={ album }
								onChange={ ( value ) => setAttributes( { album: value } ) }
								placeholder={ __( 'Album name...', 'reactions-for-indieweb' ) }
							/>

							<RichText
								tagName="p"
								className="reactions-card__notes"
								value={ note }
								onChange={ ( value ) => setAttributes( { note: value } ) }
								placeholder={ __( 'Why are you jamming to this?', 'reactions-for-indieweb' ) }
							/>
						</div>
					</div>
				</div>
			</div>
		</>
	);
}
