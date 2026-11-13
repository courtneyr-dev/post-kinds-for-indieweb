import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	FormTokenField,
	Notice,
	PanelBody,
	RadioControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

const DOMAIN = 'post-kinds-for-indieweb-in-block-themes';

export default function Edit( { attributes, setAttributes } ) {
	const {
		targetType = 'content',
		url = '',
		title = '',
		description = '',
		imageId = 0,
		imageAlt = '',
		taggedAt = '',
		labels = [],
	} = attributes;
	const person = targetType === 'person';
	const initial = ( title || url || '?' )
		.trim()
		.charAt( 0 )
		.toUpperCase();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Tag details', DOMAIN ) }>
					<RadioControl
						label={ __( 'Target', DOMAIN ) }
						selected={ targetType }
						options={ [
							{ label: __( 'Content page', DOMAIN ), value: 'content' },
							{ label: __( 'Person', DOMAIN ), value: 'person' },
						] }
						onChange={ ( value ) => setAttributes( { targetType: value } ) }
					/>
					<TextControl label={ __( 'URL', DOMAIN ) } type="url" value={ url } onChange={ ( value ) => setAttributes( { url: value } ) } />
					<TextControl label={ person ? __( 'Name', DOMAIN ) : __( 'Title', DOMAIN ) } value={ title } onChange={ ( value ) => setAttributes( { title: value } ) } />
					{ ! person && <TextareaControl label={ __( 'Description', DOMAIN ) } value={ description } onChange={ ( value ) => setAttributes( { description: value } ) } /> }
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							value={ imageId }
							onSelect={ ( media ) => setAttributes( { imageId: media.id, imageAlt: media.alt || '' } ) }
							render={ ( { open } ) => <Button variant="secondary" onClick={ open }>{ imageId ? __( 'Replace image', DOMAIN ) : __( 'Choose image', DOMAIN ) }</Button> }
						/>
					</MediaUploadCheck>
					{ imageId > 0 && <Button variant="link" isDestructive onClick={ () => setAttributes( { imageId: 0, imageAlt: '' } ) }>{ __( 'Remove image', DOMAIN ) }</Button> }
					{ ! person && imageId > 0 && <TextControl label={ __( 'Image alt text', DOMAIN ) } value={ imageAlt } onChange={ ( value ) => setAttributes( { imageAlt: value } ) } /> }
					<FormTokenField
						label={ __( 'Labels', DOMAIN ) }
						value={ labels }
						onChange={ ( value ) => setAttributes( { labels: value } ) }
						__experimentalValidateInput={ ( value ) => ! /^https?:\/\//i.test( value ) }
					/>
					<TextControl label={ __( 'Tagged at', DOMAIN ) } type="date" value={ taggedAt } onChange={ ( value ) => setAttributes( { taggedAt: value } ) } />
					{ person && <Notice status="info" isDismissible={ false }>{ __( "The person's name, link and photo appear on the site only while this post is published and public. Private, draft, scheduled and password-protected posts never show them.", DOMAIN ) }</Notice> }
				</PanelBody>
			</InspectorControls>

			<article { ...useBlockProps( { className: `pk-card k-tag pkiw-tag-card pkiw-tag-card--${ targetType }` } ) }>
				<div className="pk-badge" aria-hidden="true">#</div>
				<div className="pk-body">
					<span className="pk-kindlabel">{ __( 'Tag', DOMAIN ) }</span>
					{ person ? (
						<p className="pk-title pkiw-tag-card__person"><span className="u-tag-of h-card"><span className="pkiw-tag-card__initial" aria-hidden="true">{ initial }</span><span className="p-name">{ title || __( 'Person name', DOMAIN ) }</span></span></p>
					) : (
						<><h2 className="pk-title">{ title || url || __( 'Tagged page', DOMAIN ) }</h2>{ description && <p className="pk-note pkiw-tag-card__description">{ description }</p> }</>
					) }
					{ labels.length > 0 && <p className="pkiw-tag-card__labels">{ labels.map( ( label ) => <span className="p-category" key={ label }>{ label }</span> ) }</p> }
				</div>
			</article>
		</>
	);
}
