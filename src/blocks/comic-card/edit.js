/**
 * Comic Card Block - Edit Component
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { StarRating } from '../shared/components';
import { comicsIcon } from '../shared/icons';

const STATUS_OPTIONS = [
	{
		label: __( 'To read', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'to-read',
	},
	{
		label: __( 'Reading', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'reading',
	},
	{
		label: __( 'Finished', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'finished',
	},
	{
		label: __( 'Abandoned', 'post-kinds-for-indieweb-in-block-themes' ),
		value: 'abandoned',
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		seriesTitle,
		issueTitle,
		volumeNumber,
		issueNumber,
		creatorNames,
		publisher,
		publishDate,
		coverImage,
		coverImageAlt,
		comicUrl,
		readStatus,
		rating,
		readAt,
		review,
		layout,
	} = attributes;

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

	useEffect( () => {
		if ( currentKind ) {
			return;
		}

		apiFetch( { path: '/wp/v2/kind?slug=comics' } )
			.then( ( terms ) => {
				if ( terms && terms.length > 0 ) {
					editPost( { indieblocks_kind: [ terms[ 0 ].id ] } );
				}
			} )
			.catch( () => {} );
	}, [ currentKind, editPost ] );

	useEffect( () => {
		editPost( {
			meta: {
				_pkiw_comic_series: seriesTitle || '',
				_pkiw_comic_issue_title: issueTitle || '',
				_pkiw_comic_volume: volumeNumber || '',
				_pkiw_comic_issue_number: issueNumber || '',
				_pkiw_comic_creators: creatorNames || '',
				_pkiw_comic_publisher: publisher || '',
				_pkiw_comic_publish_date: publishDate || '',
				_pkiw_comic_cover: coverImage || '',
				_pkiw_comic_url: comicUrl || '',
				_pkiw_comic_read_status: readStatus || '',
				_pkiw_comic_rating: rating || 0,
				_pkiw_comic_read_at: readAt || '',
				_pkiw_comic_review: review || '',
			},
		} );
	}, [
		seriesTitle,
		issueTitle,
		volumeNumber,
		issueNumber,
		creatorNames,
		publisher,
		publishDate,
		coverImage,
		comicUrl,
		readStatus,
		rating,
		readAt,
		review,
		editPost,
	] );

	const blockProps = useBlockProps( {
		className: `comic-card layout-${ layout } status-${ readStatus } pk-card k-comics`,
	} );

	const selectCover = ( media ) => {
		setAttributes( {
			coverImage: media.url,
			coverImageAlt: media.alt || seriesTitle || issueTitle || '',
		} );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Comic details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<TextControl
						label={ __(
							'Series title',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ seriesTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { seriesTitle: value } )
						}
					/>
					<TextControl
						label={ __(
							'Issue or story title',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ issueTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { issueTitle: value } )
						}
					/>
					<TextControl
						label={ __(
							'Volume',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ volumeNumber || '' }
						onChange={ ( value ) =>
							setAttributes( { volumeNumber: value } )
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
							'Creators',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ creatorNames || '' }
						onChange={ ( value ) =>
							setAttributes( { creatorNames: value } )
						}
						help={ __(
							'Writers, artists, colorists, and other credited creators.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
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
							'Publication date',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ publishDate || '' }
						onChange={ ( value ) =>
							setAttributes( { publishDate: value } )
						}
					/>
					<TextControl
						label={ __(
							'Comic URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="url"
						value={ comicUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { comicUrl: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Reading details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Status',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ readStatus || 'finished' }
						options={ STATUS_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { readStatus: value } )
						}
					/>
					<TextControl
						label={ __(
							'Date read',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="date"
						value={ readAt || '' }
						onChange={ ( value ) =>
							setAttributes( { readAt: value } )
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
						/>
					</div>
				</PanelBody>

				<PanelBody
					title={ __(
						'Display',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __(
							'Layout',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ layout || 'horizontal' }
						options={ [
							{
								label: __(
									'Horizontal',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'horizontal',
							},
							{
								label: __(
									'Vertical',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'vertical',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<article { ...blockProps }>
				<div className="pk-badge" aria-hidden="true">
					{ comicsIcon }
				</div>
				<div className="pk-body">
					<p className="pk-kindlabel">
						{ __(
							'Comic',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</p>
					<RichText
						tagName="h2"
						className="pk-title"
						value={ seriesTitle }
						onChange={ ( value ) =>
							setAttributes( { seriesTitle: value } )
						}
						placeholder={ __(
							'Comic series',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<RichText
						tagName="p"
						className="pk-sub"
						value={ issueTitle }
						onChange={ ( value ) =>
							setAttributes( { issueTitle: value } )
						}
						placeholder={ __(
							'Issue or story title',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					{ ( volumeNumber || issueNumber ) && (
						<p className="pk-sub">
							{ volumeNumber && `Vol. ${ volumeNumber }` }
							{ volumeNumber && issueNumber && ' · ' }
							{ issueNumber && `#${ issueNumber }` }
						</p>
					) }
					<RichText
						tagName="p"
						className="pk-sub"
						value={ creatorNames }
						onChange={ ( value ) =>
							setAttributes( { creatorNames: value } )
						}
						placeholder={ __(
							'Creators',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>

					<div className="pk-media">
						<MediaUploadCheck>
							<MediaUpload
								onSelect={ selectCover }
								allowedTypes={ [ 'image' ] }
								render={ ( { open } ) =>
									coverImage ? (
										<Button
											className="comic-card-cover-button"
											onClick={ open }
											label={ __(
												'Replace comic cover',
												'post-kinds-for-indieweb-in-block-themes'
											) }
										>
											<img
												className="pk-thumb--poster"
												src={ coverImage }
												alt={ coverImageAlt || '' }
											/>
										</Button>
									) : (
										<Button
											variant="secondary"
											onClick={ open }
										>
											{ __(
												'Choose cover',
												'post-kinds-for-indieweb-in-block-themes'
											) }
										</Button>
									)
								}
							/>
						</MediaUploadCheck>
					</div>

					{ rating > 0 && (
						<StarRating value={ rating } readOnly={ true } />
					) }
					<RichText
						tagName="p"
						className="pk-note"
						value={ review }
						onChange={ ( value ) =>
							setAttributes( { review: value } )
						}
						placeholder={ __(
							'Add a review or note…',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</div>
			</article>
		</>
	);
}
