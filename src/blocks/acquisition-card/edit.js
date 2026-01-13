/**
 * Acquisition Card Block - Edit Component
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
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

/**
 * Acquisition type options with emojis.
 */
const ACQUISITION_TYPES = [
	{ label: __( 'Purchase', 'reactions-for-indieweb' ), value: 'purchase', emoji: '🛒' },
	{ label: __( 'Gift', 'reactions-for-indieweb' ), value: 'gift', emoji: '🎁' },
	{ label: __( 'Found', 'reactions-for-indieweb' ), value: 'found', emoji: '🔍' },
	{ label: __( 'Won', 'reactions-for-indieweb' ), value: 'won', emoji: '🏆' },
	{ label: __( 'Trade', 'reactions-for-indieweb' ), value: 'trade', emoji: '🔄' },
	{ label: __( 'Free', 'reactions-for-indieweb' ), value: 'free', emoji: '✨' },
	{ label: __( 'Inherited', 'reactions-for-indieweb' ), value: 'inherited', emoji: '📜' },
	{ label: __( 'Other', 'reactions-for-indieweb' ), value: 'other', emoji: '📦' },
];

function getAcquisitionTypeInfo( type ) {
	return ACQUISITION_TYPES.find( ( t ) => t.value === type ) || ACQUISITION_TYPES[ 0 ];
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		acquisitionType,
		cost,
		where,
		whereUrl,
		photo,
		photoAlt,
		notes,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'acquisition-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			return terms && terms.length > 0 ? terms[ 0 ] : null;
		},
		[]
	);

	// Set post kind to "acquisition" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=acquisition' } )
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
		if ( title !== undefined ) metaUpdates._reactions_acquisition_title = title || '';
		if ( acquisitionType !== undefined ) metaUpdates._reactions_acquisition_type = acquisitionType || '';
		if ( cost !== undefined ) metaUpdates._reactions_acquisition_cost = cost || '';
		if ( where !== undefined ) metaUpdates._reactions_acquisition_where = where || '';
		if ( whereUrl !== undefined ) metaUpdates._reactions_acquisition_where_url = whereUrl || '';
		if ( photo !== undefined ) metaUpdates._reactions_acquisition_photo = photo || '';

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ title, acquisitionType, cost, where, whereUrl, photo ] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			photo: media.url,
			photoAlt: media.alt || title || __( 'Acquisition photo', 'reactions-for-indieweb' ),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { photo: '', photoAlt: '' } );
	};

	const typeInfo = getAcquisitionTypeInfo( acquisitionType );

	// Build select options for sidebar
	const acquisitionTypeOptions = ACQUISITION_TYPES.map( ( type ) => ( {
		label: `${ type.emoji } ${ type.label }`,
		value: type.value,
	} ) );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Acquisition Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Title', 'reactions-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) => setAttributes( { title: value } ) }
						placeholder={ __( 'What did you get?', 'reactions-for-indieweb' ) }
					/>
					<SelectControl
						label={ __( 'Type', 'reactions-for-indieweb' ) }
						value={ acquisitionType || 'purchase' }
						options={ acquisitionTypeOptions }
						onChange={ ( value ) => setAttributes( { acquisitionType: value } ) }
					/>
					<TextControl
						label={ __( 'Cost', 'reactions-for-indieweb' ) }
						value={ cost || '' }
						onChange={ ( value ) => setAttributes( { cost: value } ) }
						placeholder={ __( '$0.00', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'From Where', 'reactions-for-indieweb' ) }
						value={ where || '' }
						onChange={ ( value ) => setAttributes( { where: value } ) }
						placeholder={ __( 'Store or source', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Store/Source URL', 'reactions-for-indieweb' ) }
						value={ whereUrl || '' }
						onChange={ ( value ) => setAttributes( { whereUrl: value } ) }
						type="url"
						help={ __( 'Link to where you got it', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Notes', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Notes', 'reactions-for-indieweb' ) }
						value={ notes || '' }
						onChange={ ( value ) => setAttributes( { notes: value } ) }
						placeholder={ __( 'Notes about this...', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
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
									>
										{ photo ? (
											<>
												<img src={ photo } alt={ photoAlt || title } className="reactions-card__image" />
												<button
													type="button"
													className="reactions-card__media-remove"
													onClick={ handleImageRemove }
													aria-label={ __( 'Remove photo', 'reactions-for-indieweb' ) }
												>
													×
												</button>
											</>
										) : (
											<div className="reactions-card__media-placeholder">
												<span className="reactions-card__media-icon">{ typeInfo.emoji }</span>
												<span className="reactions-card__media-text">{ __( 'Add Photo', 'reactions-for-indieweb' ) }</span>
											</div>
										) }
									</button>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="reactions-card__content">
						<div className="reactions-card__type-row">
							<select
								className="reactions-card__type-select"
								value={ acquisitionType || 'purchase' }
								onChange={ ( e ) => setAttributes( { acquisitionType: e.target.value } ) }
							>
								{ ACQUISITION_TYPES.map( ( type ) => (
									<option key={ type.value } value={ type.value }>
										{ type.emoji } { type.label }
									</option>
								) ) }
							</select>
						</div>

						<RichText
							tagName="h3"
							className="reactions-card__title"
							value={ title }
							onChange={ ( value ) => setAttributes( { title: value } ) }
							placeholder={ __( 'What did you get?', 'reactions-for-indieweb' ) }
						/>

						<div className="reactions-card__input-row">
							<span className="reactions-card__input-icon">💰</span>
							<input
								type="text"
								className="reactions-card__input reactions-card__input--price"
								value={ cost || '' }
								onChange={ ( e ) => setAttributes( { cost: e.target.value } ) }
								placeholder={ acquisitionType === 'gift' || acquisitionType === 'free' ? __( 'Free!', 'reactions-for-indieweb' ) : __( '$0.00', 'reactions-for-indieweb' ) }
							/>
						</div>

						<RichText
							tagName="p"
							className="reactions-card__location"
							value={ where }
							onChange={ ( value ) => setAttributes( { where: value } ) }
							placeholder={ __( 'From where?', 'reactions-for-indieweb' ) }
						/>

						<RichText
							tagName="p"
							className="reactions-card__notes"
							value={ notes }
							onChange={ ( value ) => setAttributes( { notes: value } ) }
							placeholder={ __( 'Notes about this...', 'reactions-for-indieweb' ) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
