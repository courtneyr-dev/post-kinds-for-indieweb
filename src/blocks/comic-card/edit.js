/**
 * Comic Card Block - Edit Component
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
	TextareaControl,
	SelectControl,
	Button,
	DatePicker,
	Popover,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { StarRating, CoverImage } from '../shared/components';
import {
	calendarDay,
	dateLines,
	displayDay,
	issueParts,
	statusLabel,
} from './labels';

/**
 * A date-only field: a button that opens a day picker, and a way to clear it.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.label    Field label.
 * @param {string}   props.value    Stored date.
 * @param {Function} props.onChange Receives YYYY-MM-DD, or an empty string.
 * @return {JSX.Element} Field.
 */
function DayField( { label, value, onChange } ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const day = calendarDay( value );

	return (
		<div className="components-base-control">
			<span className="components-base-control__label">{ label }</span>
			<Button variant="secondary" onClick={ () => setIsOpen( true ) }>
				{ day
					? displayDay( day )
					: __(
							'Set date',
							'post-kinds-for-indieweb-in-block-themes'
						) }
			</Button>
			{ day && (
				<Button variant="tertiary" onClick={ () => onChange( '' ) }>
					{ __( 'Clear', 'post-kinds-for-indieweb-in-block-themes' ) }
				</Button>
			) }
			{ isOpen && (
				<Popover onClose={ () => setIsOpen( false ) }>
					<DatePicker
						currentDate={ day ? `${ day }T12:00:00` : undefined }
						onChange={ ( picked ) => {
							onChange( calendarDay( picked ) );
							setIsOpen( false );
						} }
					/>
				</Popover>
			) }
		</div>
	);
}

/**
 * Edit component for the Comic Card block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		creators,
		series,
		volume,
		issueNumber,
		publisher,
		coverImage,
		coverImageAlt,
		sourceUrl,
		readStatus,
		rating,
		startedAt,
		finishedAt,
		review,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'post-kinds-card-block',
	} );
	// A card with no stored status is being read. block.json sets no default
	// for it, so whichever status an author picks is saved with the block.
	const status = readStatus || 'reading';
	const hasEnded = status === 'finished' || status === 'abandoned';
	const issue = issueParts( attributes );
	const dates = dateLines( { ...attributes, readStatus: status } );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			coverImage: media.url,
			coverImageAlt: media.alt || coverImageAlt || '',
		} );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Comic Details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
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
					/>
					<TextControl
						label={ __(
							'Creators',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						help={ __(
							'Writers and artists, as you want them shown.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ creators || '' }
						onChange={ ( value ) =>
							setAttributes( { creators: value } )
						}
					/>
					<TextControl
						label={ __(
							'Series',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ series || '' }
						onChange={ ( value ) =>
							setAttributes( { series: value } )
						}
					/>
					<TextControl
						label={ __(
							'Volume',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ volume || '' }
						onChange={ ( value ) =>
							setAttributes( { volume: value } )
						}
					/>
					<TextControl
						label={ __(
							'Issue number',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ issueNumber || '' }
						onChange={ ( value ) =>
							setAttributes( { issueNumber: value } )
						}
					/>
					<TextControl
						label={ __(
							'Publisher',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ publisher || '' }
						onChange={ ( value ) =>
							setAttributes( { publisher: value } )
						}
					/>
					<TextControl
						label={ __(
							'Link',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						help={ __(
							'Where the comic can be found or read.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="url"
						value={ sourceUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { sourceUrl: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Cover',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<MediaUploadCheck>
						<MediaUpload
							onSelect={ handleImageSelect }
							allowedTypes={ [ 'image' ] }
							render={ ( { open } ) => (
								<Button variant="secondary" onClick={ open }>
									{ coverImage
										? __(
												'Replace cover',
												'post-kinds-for-indieweb-in-block-themes'
											)
										: __(
												'Choose cover',
												'post-kinds-for-indieweb-in-block-themes'
											) }
								</Button>
							) }
						/>
					</MediaUploadCheck>
					{ coverImage && (
						<>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									setAttributes( {
										coverImage: '',
										coverImageAlt: '',
									} )
								}
							>
								{ __(
									'Remove cover',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							</Button>
							<TextareaControl
								label={ __(
									'Cover alt text',
									'post-kinds-for-indieweb-in-block-themes'
								) }
								help={ __(
									'Describe the cover art. Left empty, the cover is announced as "Cover of" and the title.',
									'post-kinds-for-indieweb-in-block-themes'
								) }
								value={ coverImageAlt || '' }
								onChange={ ( value ) =>
									setAttributes( { coverImageAlt: value } )
								}
							/>
						</>
					) }
				</PanelBody>

				<PanelBody
					title={ __(
						'Reading Status',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Status',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ status }
						options={ [
							'to-read',
							'reading',
							'finished',
							'abandoned',
						].map( ( value ) => ( {
							label: statusLabel( value ),
							value,
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( { readStatus: value } )
						}
					/>

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

					{ status !== 'to-read' && (
						<DayField
							label={ __(
								'Started',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ startedAt }
							onChange={ ( value ) =>
								setAttributes( { startedAt: value } )
							}
						/>
					) }

					{ hasEnded && (
						<DayField
							label={
								status === 'finished'
									? __(
											'Finished',
											'post-kinds-for-indieweb-in-block-themes'
										)
									: __(
											'Set aside',
											'post-kinds-for-indieweb-in-block-themes'
										)
							}
							value={ finishedAt }
							onChange={ ( value ) =>
								setAttributes( { finishedAt: value } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card h-cite">
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
										aria-label={ __(
											'Choose cover',
											'post-kinds-for-indieweb-in-block-themes'
										) }
									>
										<CoverImage
											src={ coverImage }
											alt={ coverImageAlt }
											size="large"
										/>
									</div>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="post-kinds-card__content">
						<span
							className={ `post-kinds-card__badge post-kinds-card__badge--${ status }` }
						>
							{ statusLabel( status ) }
						</span>

						<RichText
							tagName="h3"
							className="post-kinds-card__title p-name"
							value={ title }
							onChange={ ( value ) =>
								setAttributes( { title: value } )
							}
							allowedFormats={ [] }
							placeholder={ __(
								'Comic title',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						{ issue.length > 0 && (
							<p className="post-kinds-card__issue">
								{ issue.join( ' · ' ) }
							</p>
						) }

						<RichText
							tagName="p"
							className="post-kinds-card__subtitle p-author h-card"
							value={ creators }
							onChange={ ( value ) =>
								setAttributes( { creators: value } )
							}
							allowedFormats={ [] }
							placeholder={ __(
								'Creators',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						{ publisher && (
							<p className="post-kinds-card__issue">
								{ publisher }
							</p>
						) }

						{ rating > 0 && (
							<div className="post-kinds-card__rating">
								<StarRating
									value={ rating }
									readOnly={ true }
									max={ 5 }
								/>
							</div>
						) }

						{ dates.length > 0 && (
							<p className="post-kinds-card__dates">
								{ dates.join( ' · ' ) }
							</p>
						) }

						<RichText
							tagName="p"
							className="post-kinds-card__notes"
							value={ review }
							onChange={ ( value ) =>
								setAttributes( { review: value } )
							}
							placeholder={ __(
								'Write a note…',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
