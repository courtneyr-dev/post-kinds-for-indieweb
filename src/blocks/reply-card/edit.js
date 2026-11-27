/**
 * Reply Card Block - Edit Component
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
import { PanelBody, TextareaControl, TextControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { useInstanceId } from '@wordpress/compose';
import StandardSitePanel from '../../components/standard-site-panel';

const DISPLAY_TEXT = [ 'title', 'description', 'quotation' ];
const FIELD_LABEL_STYLE = {
	flexShrink: 0,
	fontSize: 'var(--wp--preset--font-size--small, 0.875rem)',
};

export default function Edit( {
	attributes,
	setAttributes,
	context,
	clientId,
} ) {
	const inPatternInstance =
		!! context?.[ 'pattern/overrides' ] &&
		Object.values( attributes.metadata?.bindings ?? {} ).some(
			( binding ) => binding?.source === 'core/pattern-overrides'
		);
	const storedAttributes = useSelect(
		( select ) =>
			select( 'core/block-editor' ).getBlockAttributes( clientId ),
		[ clientId ]
	);
	const postMeta = useSelect(
		( select ) =>
			context?.postId && context?.postType
				? select( 'core' ).getEditedEntityRecord(
						'postType',
						context.postType,
						context.postId
					)?.meta
				: select( 'core/editor' ).getEditedPostAttribute( 'meta' ),
		[ context?.postId, context?.postType ]
	);
	const displayText = Object.fromEntries(
		DISPLAY_TEXT.map( ( key ) => [
			key,
			typeof attributes[ key ] === 'string' ||
			attributes[ key ] === undefined
				? attributes[ key ]
				: storedAttributes?.[ key ],
		] )
	);
	const { title, url, description, quotation, image, imageAlt, author } = {
		...attributes,
		...displayText,
		...( inPatternInstance && {
			url: postMeta?._pkiw_cite_url ?? '',
			author: postMeta?._pkiw_cite_author ?? '',
		} ),
	};
	const fieldId = useInstanceId( Edit, 'reply-card' );

	const blockProps = useBlockProps( {
		className: 'reply-card-block pk-card k-reply',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms = select( 'core/editor' ).getEditedPostAttribute( 'kind' );
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

	// Set post kind to "reply" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=reply' } )
				.then( ( terms ) => {
					if ( terms && terms.length > 0 ) {
						editPost( { kind: [ terms[ 0 ].id ] } );
					}
				} )
				.catch( () => {} );
		}
	}, [] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			image: media.url,
			imageAlt:
				media.alt ||
				title ||
				__(
					'Reply context image',
					'post-kinds-for-indieweb-in-block-themes'
				),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { image: '', imageAlt: '' } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Reply Details',
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
							'What are you replying to?',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ url || '' }
						onChange={ ( value ) =>
							setAttributes( { url: value } )
						}
						type="url"
						placeholder={ __(
							'https://…',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'Author',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ author || '' }
						onChange={ ( value ) =>
							setAttributes( { author: value } )
						}
						placeholder={ __(
							'Original author',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
			</InspectorControls>
			<InspectorControls
				group={ inPatternInstance ? 'content' : 'default' }
			>
				<PanelBody
					title={ __(
						'Description',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Description',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ description || '' }
						onChange={ ( value ) =>
							setAttributes( { description: value } )
						}
						placeholder={ __(
							'Context from the original post',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Quotation',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextareaControl
						label={ __(
							'Quoted excerpt (optional)',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						help={ __(
							'When filled in, the quote appears in place of the source description.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ quotation || '' }
						onChange={ ( value ) =>
							setAttributes( { quotation: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<InspectorControls>
				<StandardSitePanel url={ url } />
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card">
					<div className="post-kinds-card__media">
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
										>
											{ image ? (
												<>
													<img
														src={ image }
														alt={
															imageAlt || title
														}
														className="post-kinds-card__image"
													/>
												</>
											) : (
												<div className="post-kinds-card__media-placeholder">
													<span className="post-kinds-card__media-icon">
														&#8618;
													</span>
													<span className="post-kinds-card__media-text">
														{ __(
															'Add Image',
															'post-kinds-for-indieweb-in-block-themes'
														) }
													</span>
												</div>
											) }
										</button>
										{ image && (
											<button
												type="button"
												className="post-kinds-card__media-remove"
												onClick={ handleImageRemove }
												aria-label={ __(
													'Remove image',
													'post-kinds-for-indieweb-in-block-themes'
												) }
											>
												&times;
											</button>
										) }
									</div>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="post-kinds-card__content">
						<span className="post-kinds-card__badge">
							&#8618;{ ' ' }
							{ __(
								'In reply to',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</span>

						<RichText
							tagName="h3"
							className="post-kinds-card__title"
							value={ title }
							onChange={ ( value ) =>
								setAttributes( { title: value } )
							}
							placeholder={ __(
								'What are you replying to?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						<div className="post-kinds-card__input-row">
							<span className="post-kinds-card__input-icon">
								&#128279;
							</span>
							<label
								className="post-kinds-card__field-label"
								style={ FIELD_LABEL_STYLE }
								htmlFor={ `${ fieldId }-url` }
							>
								{ __(
									'Reply target URL',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							</label>
							<input
								id={ `${ fieldId }-url` }
								type="url"
								className="post-kinds-card__input post-kinds-card__input--url"
								value={ url || '' }
								readOnly={ inPatternInstance }
								onChange={ ( e ) =>
									setAttributes( { url: e.target.value } )
								}
								placeholder={ __(
									'https://example.com/…',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							/>
						</div>

						<div className="post-kinds-card__input-row">
							<span
								id={ `${ fieldId }-author` }
								className="post-kinds-card__field-label"
								style={ FIELD_LABEL_STYLE }
							>
								{ __(
									'Original author',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							</span>
							{ inPatternInstance ? (
								<output
									className="post-kinds-card__subtitle"
									aria-labelledby={ `${ fieldId }-author` }
								>
									{ author }
								</output>
							) : (
								<RichText
									tagName="p"
									className="post-kinds-card__subtitle"
									aria-labelledby={ `${ fieldId }-author` }
									value={ author }
									onChange={ ( value ) =>
										setAttributes( { author: value } )
									}
									placeholder={ __(
										'By whom?',
										'post-kinds-for-indieweb-in-block-themes'
									) }
								/>
							) }
						</div>

						{ quotation?.trim() ? (
							<blockquote className="post-kinds-card__notes pk-note">
								{ quotation }
							</blockquote>
						) : (
							<RichText
								tagName="p"
								className="post-kinds-card__notes"
								value={ description }
								onChange={ ( value ) =>
									setAttributes( { description: value } )
								}
								placeholder={ __(
									'Context from the original post',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							/>
						) }
					</div>
				</div>
			</div>
		</>
	);
}
