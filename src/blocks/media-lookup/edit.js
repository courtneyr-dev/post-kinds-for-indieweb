/**
 * Media Lookup Block - Edit Component
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	Button,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import {
	BlockPlaceholder,
	CoverImage,
	MediaSearch,
} from '../shared/components';

/**
 * Edit component for the Media Lookup block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		mediaType,
		selectedItem,
		displayStyle,
		showImage,
		showDescription,
		linkToSource,
	} = attributes;

	const [ , setIsSearching ] = useState( false );

	const blockProps = useBlockProps( {
		className: `media-lookup-block type-${ mediaType } style-${ displayStyle }`,
	} );

	// Media type options
	const mediaTypes = [
		{
			label: __( 'Book', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'book',
		},
		{
			label: __( 'Movie/TV', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'movie',
		},
		{
			label: __( 'Music', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'music',
		},
	];

	// Display style options
	const displayStyles = [
		{
			label: __( 'Card', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'card',
		},
		{
			label: __( 'Inline', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'inline',
		},
		{
			label: __( 'Compact', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'compact',
		},
	];

	/**
	 * Get icon for media type
	 */
	const getMediaIcon = () => {
		const icons = {
			book: '📚',
			movie: '🎬',
			music: '🎵',
		};
		return icons[ mediaType ] || '📦';
	};

	/**
	 * Get placeholder text for search
	 */
	const getSearchPlaceholder = () => {
		const placeholders = {
			book: __(
				'Search by title, author, or ISBN…',
				'post-kinds-for-indieweb-in-block-themes'
			),
			movie: __(
				'Search by title, year, or actor…',
				'post-kinds-for-indieweb-in-block-themes'
			),
			music: __(
				'Search by song, artist, or album…',
				'post-kinds-for-indieweb-in-block-themes'
			),
		};
		return (
			placeholders[ mediaType ] ||
			__( 'Search…', 'post-kinds-for-indieweb-in-block-themes' )
		);
	};

	/**
	 * Get source label
	 */
	const getSourceLabel = () => {
		const sources = {
			book: 'Open Library',
			movie: 'TMDB',
			music: 'MusicBrainz',
		};
		return sources[ mediaType ] || 'External API';
	};

	/**
	 * Handle search result selection
	 *
	 * @param {Object} item Selected search result item.
	 */
	const handleSelect = ( item ) => {
		setAttributes( {
			selectedItem: item,
			searchQuery: '',
		} );
		setIsSearching( false );
	};

	/**
	 * Clear selection
	 */
	const clearSelection = () => {
		setAttributes( {
			selectedItem: null,
			searchQuery: '',
		} );
	};

	/**
	 * Render selected item preview
	 */
	const renderSelectedItem = () => {
		if ( ! selectedItem ) {
			return null;
		}

		const title = selectedItem.title || selectedItem.name || '';
		const subtitle =
			selectedItem.author ||
			selectedItem.artist ||
			selectedItem.director ||
			'';
		const year =
			selectedItem.year ||
			selectedItem.release_year ||
			selectedItem.first_publish_year ||
			'';
		const image =
			selectedItem.cover ||
			selectedItem.image ||
			selectedItem.poster ||
			'';
		const description =
			selectedItem.description || selectedItem.overview || '';
		const url = selectedItem.url || selectedItem.link || '';

		return (
			<div className={ `media-item-preview style-${ displayStyle }` }>
				{ showImage && image && (
					<div className="media-image">
						<CoverImage
							src={ image }
							alt={ title }
							size={
								displayStyle === 'compact' ? 'small' : 'medium'
							}
						/>
					</div>
				) }

				<div className="media-info">
					<h3 className="media-title">
						{ linkToSource && url ? (
							<a
								href={ url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ title }
							</a>
						) : (
							title
						) }
					</h3>

					{ subtitle && (
						<p className="media-subtitle">{ subtitle }</p>
					) }

					{ year && <span className="media-year">{ year }</span> }

					{ showDescription &&
						description &&
						displayStyle !== 'compact' && (
							<p className="media-description">
								{ description.length > 200
									? `${ description.substring( 0, 200 ) }...`
									: description }
							</p>
						) }

					<div className="media-source">
						<span className="source-label">
							{ __(
								'via',
								'post-kinds-for-indieweb-in-block-themes'
							) }{ ' ' }
							{ getSourceLabel() }
						</span>
					</div>
				</div>

				<Button
					variant="secondary"
					onClick={ clearSelection }
					className="clear-selection"
					isSmall
				>
					{ __(
						'Change',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</Button>
			</div>
		);
	};

	// Show placeholder if no item selected
	if ( ! selectedItem ) {
		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody
						title={ __(
							'Media Type',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					>
						<SelectControl
							label={ __(
								'Type',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ mediaType }
							options={ mediaTypes }
							onChange={ ( value ) =>
								setAttributes( { mediaType: value } )
							}
						/>
					</PanelBody>
				</InspectorControls>

				<BlockPlaceholder
					icon={
						<span className="media-type-icon">
							{ getMediaIcon() }
						</span>
					}
					label={ __(
						'Media Lookup',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					instructions={ __(
						'Search for media to embed information about a book, movie, or music.',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<div className="media-lookup-search">
						<div className="media-type-selector">
							{ mediaTypes.map( ( type ) => (
								<Button
									key={ type.value }
									variant={
										mediaType === type.value
											? 'primary'
											: 'secondary'
									}
									onClick={ () =>
										setAttributes( {
											mediaType: type.value,
										} )
									}
									isSmall
								>
									{ type.label }
								</Button>
							) ) }
						</div>

						<MediaSearch
							type={ mediaType }
							placeholder={ getSearchPlaceholder() }
							onSelect={ handleSelect }
						/>
					</div>
				</BlockPlaceholder>
			</div>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Display Settings',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Display Style',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ displayStyle }
						options={ displayStyles }
						onChange={ ( value ) =>
							setAttributes( { displayStyle: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Image',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showImage }
						onChange={ ( value ) =>
							setAttributes( { showImage: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Description',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showDescription }
						onChange={ ( value ) =>
							setAttributes( { showDescription: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Link to Source',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ linkToSource }
						onChange={ ( value ) =>
							setAttributes( { linkToSource: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Media Info',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<p className="components-base-control__help">
						{ __(
							'Data fetched from',
							'post-kinds-for-indieweb-in-block-themes'
						) }{ ' ' }
						{ getSourceLabel() }
					</p>
					{ selectedItem && (
						<div className="selected-item-details">
							<p>
								<strong>
									{ __(
										'Title:',
										'post-kinds-for-indieweb-in-block-themes'
									) }
								</strong>{ ' ' }
								{ selectedItem.title || selectedItem.name }
							</p>
							{ selectedItem.id && (
								<p>
									<strong>
										{ __(
											'ID:',
											'post-kinds-for-indieweb-in-block-themes'
										) }
									</strong>{ ' ' }
									{ selectedItem.id }
								</p>
							) }
						</div>
					) }
					<Button
						variant="secondary"
						onClick={ clearSelection }
						isDestructive
					>
						{ __(
							'Remove & Search Again',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</Button>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="media-lookup-inner h-cite">
					{ renderSelectedItem() }
				</div>
			</div>
		</>
	);
}
