/**
 * Read Card Block - Edit Component
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
	RangeControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { readIcon } from '../shared/icons';
import {
	StarRating,
	CoverImage,
	MediaSearch,
	BlockPlaceholder,
	ProgressBar,
} from '../shared/components';

/**
 * Edit component for the Read Card block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		bookTitle,
		authorName,
		isbn,
		publisher,
		pageCount,
		currentPage,
		coverImage,
		coverImageAlt,
		bookUrl,
		openlibraryId,
		readStatus,
		rating,
		startedAt,
		finishedAt,
		review,
		layout,
	} = attributes;

	const [ showStartPicker, setShowStartPicker ] = useState( false );
	const [ showFinishPicker, setShowFinishPicker ] = useState( false );
	const [ isSearching, setIsSearching ] = useState( false );

	const blockProps = useBlockProps( {
		className: `read-card layout-${ layout } status-${ readStatus }`,
	} );

	// Calculate progress percentage
	const progressPercent =
		pageCount && currentPage
			? Math.min( 100, Math.round( ( currentPage / pageCount ) * 100 ) )
			: 0;

	/**
	 * Handle book search result selection
	 *
	 * @param {Object} item Selected search result item.
	 */
	const handleSearchSelect = ( item ) => {
		setAttributes( {
			bookTitle: item.title || '',
			authorName: item.author || item.authors?.join( ', ' ) || '',
			isbn: item.isbn || item.isbn_13?.[ 0 ] || item.isbn_10?.[ 0 ] || '',
			publisher: item.publisher || item.publishers?.[ 0 ] || '',
			publishDate:
				item.publish_date || item.first_publish_year?.toString() || '',
			pageCount: item.number_of_pages || item.pages || null,
			coverImage: item.cover || item.image || '',
			coverImageAlt: item.title || '',
			openlibraryId: item.key || item.olid || '',
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
			coverImageAlt: media.alt || bookTitle,
		} );
	};

	// Show placeholder if no book info
	if ( ! bookTitle && ! authorName ) {
		return (
			<div { ...blockProps }>
				<BlockPlaceholder
					icon={ readIcon }
					label={ __( 'Read Card', 'post-kinds-for-indieweb' ) }
					instructions={ __(
						"Add a book you're reading or have read. Search or enter details manually.",
						'post-kinds-for-indieweb'
					) }
				>
					{ isSearching ? (
						<div className="search-mode">
							<MediaSearch
								type="book"
								placeholder={ __(
									'Search by title, author, or ISBN…',
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
									'Search Books',
									'post-kinds-for-indieweb'
								) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () =>
									setAttributes( { bookTitle: '' } )
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
					title={ __( 'Search Books', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<MediaSearch
						type="book"
						placeholder={ __(
							'Search by title, author, or ISBN…',
							'post-kinds-for-indieweb'
						) }
						onSelect={ handleSearchSelect }
					/>
					<p
						className="components-base-control__help"
						style={ { marginTop: '8px' } }
					>
						{ __(
							'Search Open Library to auto-fill book details.',
							'post-kinds-for-indieweb'
						) }
					</p>
				</PanelBody>
				<PanelBody
					title={ __( 'Book Details', 'post-kinds-for-indieweb' ) }
				>
					<TextControl
						label={ __( 'Title', 'post-kinds-for-indieweb' ) }
						value={ bookTitle || '' }
						onChange={ ( value ) =>
							setAttributes( { bookTitle: value } )
						}
					/>
					<TextControl
						label={ __( 'Author', 'post-kinds-for-indieweb' ) }
						value={ authorName || '' }
						onChange={ ( value ) =>
							setAttributes( { authorName: value } )
						}
					/>
					<TextControl
						label={ __( 'ISBN', 'post-kinds-for-indieweb' ) }
						value={ isbn || '' }
						onChange={ ( value ) =>
							setAttributes( { isbn: value } )
						}
					/>
					<TextControl
						label={ __( 'Publisher', 'post-kinds-for-indieweb' ) }
						value={ publisher || '' }
						onChange={ ( value ) =>
							setAttributes( { publisher: value } )
						}
					/>
					<TextControl
						type="number"
						label={ __( 'Total Pages', 'post-kinds-for-indieweb' ) }
						value={ pageCount }
						onChange={ ( value ) =>
							setAttributes( {
								pageCount: parseInt( value ) || null,
							} )
						}
						min={ 1 }
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Reading Status', 'post-kinds-for-indieweb' ) }
				>
					<SelectControl
						label={ __( 'Status', 'post-kinds-for-indieweb' ) }
						value={ readStatus }
						options={ [
							{
								label: __(
									'To Read',
									'post-kinds-for-indieweb'
								),
								value: 'to-read',
							},
							{
								label: __(
									'Currently Reading',
									'post-kinds-for-indieweb'
								),
								value: 'reading',
							},
							{
								label: __(
									'Finished',
									'post-kinds-for-indieweb'
								),
								value: 'finished',
							},
							{
								label: __(
									'Abandoned',
									'post-kinds-for-indieweb'
								),
								value: 'abandoned',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { readStatus: value } )
						}
					/>

					{ readStatus === 'reading' && pageCount && (
						<RangeControl
							label={ __(
								'Current Page',
								'post-kinds-for-indieweb'
							) }
							value={ currentPage || 0 }
							onChange={ ( value ) =>
								setAttributes( { currentPage: value } )
							}
							min={ 0 }
							max={ pageCount }
							help={ `${ progressPercent }% complete` }
						/>
					) }

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
							{ __( 'Started', 'post-kinds-for-indieweb' ) }
						</span>
						<Button
							variant="secondary"
							onClick={ () => setShowStartPicker( true ) }
							aria-label={ __(
								'Set start date',
								'post-kinds-for-indieweb'
							) }
						>
							{ startedAt
								? new Date( startedAt ).toLocaleDateString()
								: __( 'Set date', 'post-kinds-for-indieweb' ) }
						</Button>
						{ showStartPicker && (
							<Popover
								onClose={ () => setShowStartPicker( false ) }
							>
								<DateTimePicker
									currentDate={ startedAt }
									onChange={ ( value ) => {
										setAttributes( { startedAt: value } );
										setShowStartPicker( false );
									} }
								/>
							</Popover>
						) }
					</div>

					{ ( readStatus === 'finished' ||
						readStatus === 'abandoned' ) && (
						<div className="components-base-control">
							<span className="components-base-control__label">
								{ __( 'Finished', 'post-kinds-for-indieweb' ) }
							</span>
							<Button
								variant="secondary"
								onClick={ () => setShowFinishPicker( true ) }
								aria-label={ __(
									'Set finish date',
									'post-kinds-for-indieweb'
								) }
							>
								{ finishedAt
									? new Date(
											finishedAt
									  ).toLocaleDateString()
									: __(
											'Set date',
											'post-kinds-for-indieweb'
									  ) }
							</Button>
							{ showFinishPicker && (
								<Popover
									onClose={ () =>
										setShowFinishPicker( false )
									}
								>
									<DateTimePicker
										currentDate={ finishedAt }
										onChange={ ( value ) => {
											setAttributes( {
												finishedAt: value,
											} );
											setShowFinishPicker( false );
										} }
									/>
								</Popover>
							) }
						</div>
					) }
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
									'Cover Focus',
									'post-kinds-for-indieweb'
								),
								value: 'cover',
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
					title={ __( 'Links', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Book URL', 'post-kinds-for-indieweb' ) }
						value={ bookUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { bookUrl: value } )
						}
						type="url"
					/>
					<TextControl
						label={ __(
							'Open Library ID',
							'post-kinds-for-indieweb'
						) }
						value={ openlibraryId || '' }
						onChange={ ( value ) =>
							setAttributes( { openlibraryId: value } )
						}
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
											size="large"
										/>
									</div>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="read-info">
						<span
							className={ `status-badge status-${ readStatus }` }
						>
							{ readStatus === 'to-read' &&
								__( 'To Read', 'post-kinds-for-indieweb' ) }
							{ readStatus === 'reading' &&
								__( 'Reading', 'post-kinds-for-indieweb' ) }
							{ readStatus === 'finished' &&
								__( 'Finished', 'post-kinds-for-indieweb' ) }
							{ readStatus === 'abandoned' &&
								__( 'Abandoned', 'post-kinds-for-indieweb' ) }
						</span>

						<RichText
							tagName="h3"
							className="book-title p-name"
							value={ bookTitle }
							onChange={ ( value ) =>
								setAttributes( { bookTitle: value } )
							}
							placeholder={ __(
								'Book title',
								'post-kinds-for-indieweb'
							) }
						/>

						<RichText
							tagName="p"
							className="author-name p-author h-card"
							value={ authorName }
							onChange={ ( value ) =>
								setAttributes( { authorName: value } )
							}
							placeholder={ __(
								'Author name',
								'post-kinds-for-indieweb'
							) }
						/>

						{ readStatus === 'reading' && progressPercent > 0 && (
							<ProgressBar
								value={ progressPercent }
								label={ `${ currentPage } of ${ pageCount } pages` }
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

						<RichText
							tagName="p"
							className="book-review"
							value={ review }
							onChange={ ( value ) =>
								setAttributes( { review: value } )
							}
							placeholder={ __(
								'Write a review…',
								'post-kinds-for-indieweb'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
