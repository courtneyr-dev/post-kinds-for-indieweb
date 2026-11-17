import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
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

function urlHost( value ) {
	try {
		return new URL( value ).host;
	} catch {
		return '';
	}
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		targetType = 'content',
		url = '',
		title = '',
		description = '',
		imageId = 0,
		imageAlt = '',
		taggedAt = '',
		labels: rawLabels = [],
	} = attributes;
	// Saved or pasted markup can carry a non-array value; render it as no labels instead of crashing.
	const labels = Array.isArray( rawLabels )
		? rawLabels.filter( ( label ) => 'string' === typeof label )
		: [];
	const person = targetType === 'person';
	const host = urlHost( url );
	const initial = ( title || url || '?' ).trim().charAt( 0 ).toUpperCase();
	const media = useSelect(
		( select ) =>
			imageId > 0 ? select( 'core' ).getMedia( imageId ) : null,
		[ imageId ]
	);
	const imageUrl =
		media?.media_details?.sizes?.medium?.source_url ||
		media?.source_url ||
		'';
	const linkText = title || host;
	const labelPrefix =
		labels.length === 1
			? __( 'Label:', 'post-kinds-for-indieweb-in-block-themes' )
			: __( 'Labels:', 'post-kinds-for-indieweb-in-block-themes' );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Tag details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<RadioControl
						label={ __(
							'Target',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						selected={ targetType }
						options={ [
							{
								label: __(
									'Content page',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'content',
							},
							{
								label: __(
									'Person',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'person',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { targetType: value } )
						}
					/>
					<TextControl
						label={ __(
							'URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="url"
						value={ url }
						onChange={ ( value ) =>
							setAttributes( { url: value } )
						}
					/>
					<TextControl
						label={
							person
								? __(
										'Name',
										'post-kinds-for-indieweb-in-block-themes'
									)
								: __(
										'Title',
										'post-kinds-for-indieweb-in-block-themes'
									)
						}
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
					/>
					{ ! person && (
						<TextareaControl
							label={ __(
								'Description',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ description }
							onChange={ ( value ) =>
								setAttributes( { description: value } )
							}
						/>
					) }
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							value={ imageId }
							onSelect={ ( selected ) =>
								setAttributes( {
									imageId: selected.id,
									imageAlt: selected.alt || '',
								} )
							}
							render={ ( { open } ) => (
								<Button variant="secondary" onClick={ open }>
									{ imageId
										? __(
												'Replace image',
												'post-kinds-for-indieweb-in-block-themes'
											)
										: __(
												'Choose image',
												'post-kinds-for-indieweb-in-block-themes'
											) }
								</Button>
							) }
						/>
					</MediaUploadCheck>
					{ imageId > 0 && (
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								setAttributes( { imageId: 0, imageAlt: '' } )
							}
						>
							{ __(
								'Remove image',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</Button>
					) }
					{ ! person && imageId > 0 && (
						<TextControl
							label={ __(
								'Image alt text',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ imageAlt }
							onChange={ ( value ) =>
								setAttributes( { imageAlt: value } )
							}
						/>
					) }
					<FormTokenField
						label={ __(
							'Labels',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ labels }
						onChange={ ( value ) =>
							setAttributes( { labels: value } )
						}
						__experimentalValidateInput={ ( value ) =>
							! /^https?:\/\//i.test( value )
						}
					/>
					<TextControl
						label={ __(
							'Tagged at',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="date"
						value={ taggedAt }
						onChange={ ( value ) =>
							setAttributes( { taggedAt: value } )
						}
					/>
					{ person && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								"The person's name, link and photo appear on the site only while this post is published and public. Private, draft, scheduled and password-protected posts never show them.",
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</Notice>
					) }
				</PanelBody>
			</InspectorControls>

			<article
				{ ...useBlockProps( {
					className: `pk-card k-tag pkiw-tag-card pkiw-tag-card--${ targetType }`,
				} ) }
			>
				<div className="pk-badge">
					<svg
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="2"
						strokeLinecap="round"
						strokeLinejoin="round"
						aria-hidden="true"
						focusable="false"
					>
						<path d="M20.6 13.4L12 22l-9-9V4h9l8.6 8.6a1 1 0 0 1 0 .8z" />
						<circle cx="7.5" cy="7.5" r="1.5" />
					</svg>
				</div>
				<div className="pk-body">
					<span className="pk-kindlabel">
						{ __(
							'Tag',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</span>
					{ person ? (
						<>
							<p className="pk-title pkiw-tag-card__person">
								<span className="u-tag-of h-card">
									{ imageUrl ? (
										<img
											className="u-photo"
											src={ imageUrl }
											alt=""
										/>
									) : (
										<span
											className="pkiw-tag-card__initial"
											aria-hidden="true"
										>
											{ initial }
										</span>
									) }
									{ url ? (
										<a
											className="p-name u-url"
											href={ url }
											rel="noopener noreferrer"
											onClick={ ( event ) =>
												event.preventDefault()
											}
										>
											{ title ||
												host ||
												__(
													'Person name',
													'post-kinds-for-indieweb-in-block-themes'
												) }
										</a>
									) : (
										<span className="p-name">
											{ title ||
												__(
													'Person name',
													'post-kinds-for-indieweb-in-block-themes'
												) }
										</span>
									) }
								</span>
							</p>
							{ host && (
								<p className="pk-sub pkiw-tag-card__host">
									{ host }
								</p>
							) }
						</>
					) : (
						<>
							{ linkText && (
								<h2 className="pk-title">
									{ url ? (
										<a
											className="u-tag-of"
											href={ url }
											rel="noopener noreferrer"
											target="_blank"
											onClick={ ( event ) =>
												event.preventDefault()
											}
										>
											{ linkText }
										</a>
									) : (
										linkText
									) }
								</h2>
							) }
							{ host && (
								<p className="pk-sub pkiw-tag-card__host">
									{ host }
								</p>
							) }
							{ description && (
								<p className="pk-note pkiw-tag-card__description">
									{ description }
								</p>
							) }
							{ imageUrl && (
								<figure className="pk-embed pk-embed--photo">
									<img src={ imageUrl } alt={ imageAlt } />
								</figure>
							) }
						</>
					) }
					{ labels.length > 0 && (
						<p className="pkiw-tag-card__labels">
							<span className="screen-reader-text">
								{ labelPrefix }
							</span>
							{ labels.map( ( label ) => (
								<span className="p-category" key={ label }>
									{ label }
								</span>
							) ) }
						</p>
					) }
					{ taggedAt && (
						<div className="pk-meta">
							<time dateTime={ taggedAt }>{ taggedAt }</time>
						</div>
					) }
				</div>
			</article>
		</>
	);
}
