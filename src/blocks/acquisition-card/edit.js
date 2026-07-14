/**
 * Acquisition Card Block - Edit Component
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
import { PanelBody, TextControl, SelectControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

/**
 * Acquisition type options with emojis.
 */
const ACQUISITION_TYPES = [
	{
		label: __( 'Purchase', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'purchase',
		emoji: '🛒',
	},
	{
		label: __( 'Gift', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'gift',
		emoji: '🎁',
	},
	{
		label: __( 'Found', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'found',
		emoji: '🔍',
	},
	{
		label: __( 'Won', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'won',
		emoji: '🏆',
	},
	{
		label: __( 'Trade', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'trade',
		emoji: '🔄',
	},
	{
		label: __( 'Free', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'free',
		emoji: '✨',
	},
	{
		label: __( 'Inherited', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'inherited',
		emoji: '📜',
	},
	{
		label: __( 'Other', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'other',
		emoji: '📦',
	},
];

function getAcquisitionTypeInfo( type ) {
	return (
		ACQUISITION_TYPES.find( ( t ) => t.value === type ) ||
		ACQUISITION_TYPES[ 0 ]
	);
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
		className: 'acquisition-card-block pk-card k-acquisition',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

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
		if ( title !== undefined ) {
			metaUpdates._pkiw_acquisition_title = title || '';
		}
		if ( acquisitionType !== undefined ) {
			metaUpdates._pkiw_acquisition_type = acquisitionType || '';
		}
		if ( cost !== undefined ) {
			metaUpdates._pkiw_acquisition_cost = cost || '';
		}
		if ( where !== undefined ) {
			metaUpdates._pkiw_acquisition_where = where || '';
		}
		if ( whereUrl !== undefined ) {
			metaUpdates._pkiw_acquisition_where_url = whereUrl || '';
		}
		if ( photo !== undefined ) {
			metaUpdates._pkiw_acquisition_photo = photo || '';
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ title, acquisitionType, cost, where, whereUrl, photo ] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			photo: media.url,
			photoAlt:
				media.alt ||
				title ||
				__(
					'Acquisition photo',
					'post-kinds-for-indieweb-in-block-themes'
				),
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
				<PanelBody
					title={ __(
						'Acquisition Details',
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
							'What did you get?',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<SelectControl
						label={ __(
							'Type',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ acquisitionType || 'purchase' }
						options={ acquisitionTypeOptions }
						onChange={ ( value ) =>
							setAttributes( { acquisitionType: value } )
						}
					/>
					<TextControl
						label={ __(
							'Cost',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ cost || '' }
						onChange={ ( value ) =>
							setAttributes( { cost: value } )
						}
						placeholder={ __(
							'$0.00',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'From Where',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ where || '' }
						onChange={ ( value ) =>
							setAttributes( { where: value } )
						}
						placeholder={ __(
							'Store or source',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'Store/Source URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ whereUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { whereUrl: value } )
						}
						type="url"
						help={ __(
							'Link to where you got it',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Notes',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Notes',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ notes || '' }
						onChange={ ( value ) =>
							setAttributes( { notes: value } )
						}
						placeholder={ __(
							'Notes about this…',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
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
									>
										{ photo ? (
											<>
												<img
													src={ photo }
													alt={ photoAlt || title }
													className="post-kinds-card__image"
												/>
												<button
													type="button"
													className="post-kinds-card__media-remove"
													onClick={
														handleImageRemove
													}
													aria-label={ __(
														'Remove photo',
														'post-kinds-for-indieweb-in-block-themes'
													) }
												>
													×
												</button>
											</>
										) : (
											<div className="post-kinds-card__media-placeholder">
												<span className="post-kinds-card__media-icon">
													{ typeInfo.emoji }
												</span>
												<span className="post-kinds-card__media-text">
													{ __(
														'Add Photo (Optional)',
														'post-kinds-for-indieweb-in-block-themes'
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
						<div className="post-kinds-card__type-row">
							<select
								className="post-kinds-card__type-select"
								value={ acquisitionType || 'purchase' }
								onChange={ ( e ) =>
									setAttributes( {
										acquisitionType: e.target.value,
									} )
								}
							>
								{ ACQUISITION_TYPES.map( ( type ) => (
									<option
										key={ type.value }
										value={ type.value }
									>
										{ type.emoji } { type.label }
									</option>
								) ) }
							</select>
						</div>

						<RichText
							tagName="h3"
							className="post-kinds-card__title"
							value={ title }
							onChange={ ( value ) =>
								setAttributes( { title: value } )
							}
							placeholder={ __(
								'What did you get?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						<div className="post-kinds-card__input-row">
							<span className="post-kinds-card__input-icon">
								💰
							</span>
							<input
								type="text"
								className="post-kinds-card__input post-kinds-card__input--price"
								value={ cost || '' }
								onChange={ ( e ) =>
									setAttributes( { cost: e.target.value } )
								}
								placeholder={
									acquisitionType === 'gift' ||
									acquisitionType === 'free'
										? __(
												'Free!',
												'post-kinds-for-indieweb-in-block-themes'
										  )
										: __(
												'$0.00',
												'post-kinds-for-indieweb-in-block-themes'
										  )
								}
							/>
						</div>

						<RichText
							tagName="p"
							className="post-kinds-card__location"
							value={ where }
							onChange={ ( value ) =>
								setAttributes( { where: value } )
							}
							placeholder={ __(
								'From where?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						<RichText
							tagName="p"
							className="post-kinds-card__notes"
							value={ notes }
							onChange={ ( value ) =>
								setAttributes( { notes: value } )
							}
							placeholder={ __(
								'Notes about this…',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
