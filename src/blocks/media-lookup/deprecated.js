/**
 * Media Lookup Block - Deprecations
 *
 * v1 (pre-1.8.6): the "opens in a new tab" link lacked a visually-hidden
 * hint, and the year's `dt-published` `<time>` had no `dateTime` attribute.
 * Both changed in 1.8.6 for WCAG 2.2 AA; this deprecation keeps existing
 * saved content validating against the block's markup.
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';

/**
 * v1 save output — matches the block as it shipped before 1.8.6.
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @return {JSX.Element|null} Block save component.
 */
function saveV1( { attributes } ) {
	const {
		mediaType,
		selectedItem,
		displayStyle,
		showImage,
		showDescription,
		linkToSource,
	} = attributes;

	if ( ! selectedItem ) {
		return null;
	}

	const blockProps = useBlockProps.save( {
		className: `media-lookup-block type-${ mediaType } style-${ displayStyle }`,
	} );

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
		selectedItem.cover || selectedItem.image || selectedItem.poster || '';
	const description = selectedItem.description || selectedItem.overview || '';
	const url = selectedItem.url || selectedItem.link || '';
	const id = selectedItem.id || selectedItem.key || '';

	const getSourceUrl = () => {
		if ( url ) {
			return url;
		}

		switch ( mediaType ) {
			case 'book':
				if ( selectedItem.key ) {
					return `https://openlibrary.org${ selectedItem.key }`;
				}
				if ( selectedItem.isbn ) {
					return `https://openlibrary.org/isbn/${ selectedItem.isbn }`;
				}
				break;
			case 'movie':
				if ( selectedItem.tmdb_id ) {
					const type =
						selectedItem.media_type === 'tv' ? 'tv' : 'movie';
					return `https://www.themoviedb.org/${ type }/${ selectedItem.tmdb_id }`;
				}
				if ( selectedItem.imdb_id ) {
					return `https://www.imdb.com/title/${ selectedItem.imdb_id }`;
				}
				break;
			case 'music':
				if ( selectedItem.musicbrainz_id ) {
					return `https://musicbrainz.org/recording/${ selectedItem.musicbrainz_id }`;
				}
				break;
		}
		return null;
	};

	const sourceUrl = getSourceUrl();

	const getMicroformatClass = () => {
		switch ( mediaType ) {
			case 'book':
				return 'h-cite p-read-of';
			case 'movie':
				return 'h-cite p-watch-of';
			case 'music':
				return 'h-cite p-listen-of';
			default:
				return 'h-cite';
		}
	};

	return (
		<div { ...blockProps }>
			<div className={ `media-lookup-inner ${ getMicroformatClass() }` }>
				{ showImage && image && (
					<div className="media-image">
						<img
							src={ image }
							alt={ title }
							className="u-photo"
							loading="lazy"
						/>
					</div>
				) }

				<div className="media-info">
					<h3 className="media-title p-name">
						{ linkToSource && sourceUrl ? (
							<a
								href={ sourceUrl }
								className="u-url"
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
						<p className="media-subtitle p-author h-card">
							<span className="p-name">{ subtitle }</span>
						</p>
					) }

					{ year && (
						<span className="media-year">
							<time className="dt-published">{ year }</time>
						</span>
					) }

					{ showDescription &&
						description &&
						displayStyle !== 'compact' && (
							<p className="media-description p-summary">
								{ description.length > 200
									? `${ description.substring( 0, 200 ) }...`
									: description }
							</p>
						) }

					<div className="media-meta">
						{ mediaType === 'book' && selectedItem.isbn && (
							<data
								className="p-isbn"
								value={ selectedItem.isbn }
								hidden
							/>
						) }
						{ mediaType === 'book' && selectedItem.publisher && (
							<span className="p-publisher">
								{ selectedItem.publisher }
							</span>
						) }
						{ mediaType === 'movie' && selectedItem.runtime && (
							<span className="runtime">
								{ selectedItem.runtime } min
							</span>
						) }
						{ mediaType === 'music' && selectedItem.album && (
							<span className="album">
								{ selectedItem.album }
							</span>
						) }
					</div>
				</div>

				{ sourceUrl && (
					<data className="u-uid" value={ sourceUrl } hidden />
				) }
				{ id && <data className="p-uid" value={ id } hidden /> }
			</div>
		</div>
	);
}

const deprecated = [
	{
		attributes: metadata.attributes,
		save: saveV1,
	},
];

export default deprecated;
