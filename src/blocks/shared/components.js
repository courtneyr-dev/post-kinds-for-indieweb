/**
 * Shared Block Components
 *
 * Reusable components for reaction blocks.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import {
	TextControl,
	Button,
	Spinner,
	Placeholder,
	ExternalLink,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import {
	starIcon,
	starOutlineIcon,
	externalLinkIcon,
	imageIcon,
} from './icons';

/**
 * Star Rating Component
 *
 * @param {Object}   props          Component props.
 * @param {number}   props.value    Current rating value.
 * @param {Function} props.onChange Rating change handler.
 * @param {number}   props.max      Maximum rating (default 5).
 * @param {boolean}  props.readOnly Whether rating is read-only.
 */
export function StarRating( {
	value = 0,
	onChange,
	max = 5,
	readOnly = false,
} ) {
	const [ hoverValue, setHoverValue ] = useState( 0 );

	const handleClick = ( rating ) => {
		if ( ! readOnly && onChange ) {
			onChange( rating === value ? 0 : rating );
		}
	};

	const handleMouseEnter = ( rating ) => {
		if ( ! readOnly ) {
			setHoverValue( rating );
		}
	};

	const handleMouseLeave = () => {
		setHoverValue( 0 );
	};

	const displayValue = hoverValue || value;

	return (
		<div
			className="post-kinds-star-rating"
			onMouseLeave={ handleMouseLeave }
			role="group"
			aria-label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
		>
			{ Array.from( { length: max }, ( _, i ) => i + 1 ).map(
				( rating ) => (
					<button
						key={ rating }
						type="button"
						className={ `star ${
							rating <= displayValue ? 'filled' : ''
						}` }
						onClick={ () => handleClick( rating ) }
						onMouseEnter={ () => handleMouseEnter( rating ) }
						disabled={ readOnly }
						aria-label={ `${ rating } ${
							rating === 1
								? __( 'star', 'post-kinds-for-indieweb' )
								: __( 'stars', 'post-kinds-for-indieweb' )
						}` }
						aria-pressed={ rating <= value }
					>
						{ rating <= displayValue ? starIcon : starOutlineIcon }
					</button>
				)
			) }
			{ value > 0 && (
				<span className="rating-text" aria-hidden="true">
					{ value }/{ max }
				</span>
			) }
		</div>
	);
}

/**
 * Cover Image Component
 *
 * @param {Object} props           Component props.
 * @param {string} props.src       Image source URL.
 * @param {string} props.alt       Alt text.
 * @param {string} props.size      Size variant (small, medium, large).
 * @param {string} props.className Additional CSS classes.
 */
export function CoverImage( {
	src,
	alt = '',
	size = 'medium',
	className = '',
} ) {
	const [ hasError, setHasError ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );

	if ( ! src || hasError ) {
		return (
			<div
				className={ `post-kinds-cover-placeholder ${ size } ${ className }` }
			>
				{ imageIcon }
			</div>
		);
	}

	return (
		<div className={ `post-kinds-cover-image ${ size } ${ className }` }>
			{ isLoading && (
				<div className="cover-loading">
					<Spinner />
				</div>
			) }
			<img
				src={ src }
				alt={ alt }
				onLoad={ () => setIsLoading( false ) }
				onError={ () => {
					setHasError( true );
					setIsLoading( false );
				} }
				style={ { opacity: isLoading ? 0 : 1 } }
			/>
		</div>
	);
}

/**
 * Media Search Component
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.type        Search type (music, movie, tv, book, podcast, venue).
 * @param {string}   props.placeholder Search placeholder text.
 * @param {Function} props.onSelect    Selection handler.
 */
export function MediaSearch( { type, placeholder, onSelect } ) {
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ error, setError ] = useState( '' );

	/**
	 * Get the correct API endpoint for the search type.
	 */
	const getEndpoint = () => {
		switch ( type ) {
			case 'movie':
			case 'tv':
				return '/post-kinds-indieweb/v1/lookup/video';
			case 'music':
				return '/post-kinds-indieweb/v1/lookup/music';
			case 'book':
				return '/post-kinds-indieweb/v1/lookup/book';
			case 'podcast':
				return '/post-kinds-indieweb/v1/lookup/podcast';
			case 'venue':
				return '/post-kinds-indieweb/v1/lookup/venue';
			case 'game':
				return '/post-kinds-indieweb/v1/lookup/game';
			default:
				return '/post-kinds-indieweb/v1/lookup/video';
		}
	};

	/**
	 * Build query parameters for the API request.
	 */
	const buildQueryParams = () => {
		const params = new URLSearchParams();
		params.append( 'q', query.trim() );

		// Add type parameter for video searches
		if ( type === 'movie' || type === 'tv' ) {
			params.append( 'type', type );
		}

		return params.toString();
	};

	const doSearch = async () => {
		if ( ! query.trim() ) {
			return;
		}

		setIsSearching( true );
		setError( '' );
		setResults( [] );

		try {
			const endpoint = getEndpoint();
			const queryParams = buildQueryParams();
			const response = await wp.apiFetch( {
				path: `${ endpoint }?${ queryParams }`,
			} );

			// API returns array directly, not wrapped in { results: [] }
			const resultsArray = Array.isArray( response )
				? response
				: response.results || [];

			if ( resultsArray.length > 0 ) {
				setResults( resultsArray );
			} else {
				setError(
					__( 'No results found.', 'post-kinds-for-indieweb' )
				);
			}
		} catch ( err ) {
			// Handle different error types
			let errorMessage = __(
				'Search failed.',
				'post-kinds-for-indieweb'
			);

			if ( err.message ) {
				// If error message contains HTML, show generic error
				if (
					err.message.includes( '<' ) ||
					err.message.includes( 'critical error' )
				) {
					errorMessage = __(
						'Search service unavailable. Please try again.',
						'post-kinds-for-indieweb'
					);
				} else {
					errorMessage = err.message;
				}
			} else if ( err.code ) {
				errorMessage = `${ __(
					'Error',
					'post-kinds-for-indieweb'
				) }: ${ err.code }`;
			}

			setError( errorMessage );
		} finally {
			setIsSearching( false );
		}
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			doSearch();
		}
	};

	const handleSelect = ( item ) => {
		if ( onSelect ) {
			onSelect( item );
		}
		setResults( [] );
		setQuery( '' );
	};

	return (
		<div className="post-kinds-media-search">
			<div className="search-input-group">
				<TextControl
					value={ query }
					onChange={ setQuery }
					onKeyDown={ handleKeyDown }
					placeholder={ placeholder }
					disabled={ isSearching }
				/>
				<Button
					variant="secondary"
					onClick={ doSearch }
					disabled={ isSearching || ! query.trim() }
				>
					{ isSearching ? (
						<Spinner />
					) : (
						__( 'Search', 'post-kinds-for-indieweb' )
					) }
				</Button>
			</div>

			{ error && <p className="search-error">{ error }</p> }

			{ results.length > 0 && (
				<ul className="search-results">
					{ results.map( ( item, index ) => (
						<li key={ index }>
							<button
								type="button"
								className="search-result-item"
								onClick={ () => handleSelect( item ) }
							>
								{ ( item.image || item.cover ) && (
									<img
										src={ item.image || item.cover }
										alt=""
										className="result-image"
									/>
								) }
								<div className="result-info">
									<strong className="result-title">
										{ item.track ||
											item.title ||
											item.name }
									</strong>
									{ item.artist && (
										<span className="result-artist">
											{ item.artist }
										</span>
									) }
									{ item.author && (
										<span className="result-author">
											{ item.author }
										</span>
									) }
									{ item.year && (
										<span className="result-year">
											({ item.year })
										</span>
									) }
								</div>
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Progress Bar Component
 *
 * @param {Object}  props             Component props.
 * @param {number}  props.value       Progress value (0-100).
 * @param {string}  props.label       Optional label.
 * @param {boolean} props.showPercent Whether to show percentage.
 */
export function ProgressBar( { value = 0, label = '', showPercent = true } ) {
	const clampedValue = Math.max( 0, Math.min( 100, value ) );

	return (
		<div
			className="post-kinds-progress-bar"
			role="progressbar"
			aria-valuenow={ clampedValue }
			aria-valuemin="0"
			aria-valuemax="100"
		>
			{ label && <span className="progress-label">{ label }</span> }
			<div className="progress-track">
				<div
					className="progress-fill"
					style={ { width: `${ clampedValue }%` } }
				/>
			</div>
			{ showPercent && (
				<span className="progress-percent">{ clampedValue }%</span>
			) }
		</div>
	);
}

/**
 * External Link with Icon
 *
 * @param {Object} props          Component props.
 * @param {string} props.href     Link URL.
 * @param {string} props.children Link text.
 */
export function ExternalLinkWithIcon( { href, children } ) {
	if ( ! href ) {
		return null;
	}

	return (
		<ExternalLink href={ href } className="post-kinds-external-link">
			{ children }
			{ externalLinkIcon }
		</ExternalLink>
	);
}

/**
 * Microformat Hidden Data
 *
 * Renders hidden microformat data for IndieWeb compatibility.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.data Microformat data.
 */
export function MicroformatData( { data } ) {
	if ( ! data || Object.keys( data ).length === 0 ) {
		return null;
	}

	return (
		<div
			className="post-kinds-microformat-data"
			style={ { display: 'none' } }
		>
			{ Object.entries( data ).map( ( [ key, value ] ) => (
				<data key={ key } className={ key } value={ value }>
					{ value }
				</data>
			) ) }
		</div>
	);
}

/**
 * Block Placeholder Component
 *
 * @param {Object}      props              Component props.
 * @param {JSX.Element} props.icon         Block icon.
 * @param {string}      props.label        Block label.
 * @param {string}      props.instructions Setup instructions.
 * @param {JSX.Element} props.children     Placeholder content.
 */
export function BlockPlaceholder( { icon, label, instructions, children } ) {
	return (
		<Placeholder
			icon={ icon }
			label={ label }
			instructions={ instructions }
			className="post-kinds-block-placeholder"
		>
			{ children }
		</Placeholder>
	);
}

/**
 * Cite Block Component
 *
 * Renders a citation for likes, reposts, replies, bookmarks.
 *
 * @param {Object} props        Component props.
 * @param {string} props.url    Source URL.
 * @param {string} props.name   Content title.
 * @param {string} props.author Author name.
 * @param {string} props.type   Citation type (u-like-of, u-repost-of, etc.).
 */
export function CiteBlock( { url, name, author, type = 'u-cite' } ) {
	if ( ! url ) {
		return null;
	}

	return (
		<div className={ `post-kinds-cite h-cite ${ type }` }>
			<a
				href={ url }
				className="u-url p-name"
				target="_blank"
				rel="noopener noreferrer"
			>
				{ name || url }
			</a>
			{ author && (
				<span className="p-author h-card">
					{ __( 'by', 'post-kinds-for-indieweb' ) }
					<span className="p-name">{ author }</span>
				</span>
			) }
		</div>
	);
}

/**
 * Date Display Component
 *
 * @param {Object} props           Component props.
 * @param {string} props.date      Date string.
 * @param {string} props.format    Display format.
 * @param {string} props.className Additional classes.
 */
export function DateDisplay( { date, format = 'long', className = '' } ) {
	if ( ! date ) {
		return null;
	}

	const dateObj = new Date( date );
	const isoDate = dateObj.toISOString();

	let displayDate;
	switch ( format ) {
		case 'short':
			displayDate = dateObj.toLocaleDateString();
			break;
		case 'time':
			displayDate = dateObj.toLocaleTimeString();
			break;
		case 'datetime':
			displayDate = dateObj.toLocaleString();
			break;
		case 'relative':
			displayDate = getRelativeTime( dateObj );
			break;
		case 'long':
		default:
			displayDate = dateObj.toLocaleDateString( undefined, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			} );
	}

	return (
		<time
			dateTime={ isoDate }
			className={ `post-kinds-date ${ className }` }
		>
			{ displayDate }
		</time>
	);
}

/**
 * Get relative time string
 *
 * @param {Date} date Date object.
 * @return {string} Relative time string.
 */
function getRelativeTime( date ) {
	const now = new Date();
	const diffMs = now - date;
	const diffDays = Math.floor( diffMs / ( 1000 * 60 * 60 * 24 ) );

	if ( diffDays === 0 ) {
		return __( 'Today', 'post-kinds-for-indieweb' );
	} else if ( diffDays === 1 ) {
		return __( 'Yesterday', 'post-kinds-for-indieweb' );
	} else if ( diffDays < 7 ) {
		return `${ diffDays } ${ __( 'days ago', 'post-kinds-for-indieweb' ) }`;
	} else if ( diffDays < 30 ) {
		const weeks = Math.floor( diffDays / 7 );
		return `${ weeks } ${
			weeks === 1
				? __( 'week ago', 'post-kinds-for-indieweb' )
				: __( 'weeks ago', 'post-kinds-for-indieweb' )
		}`;
	}
	return date.toLocaleDateString();
}

/**
 * Location Display Component
 *
 * @param {Object} props           Component props.
 * @param {string} props.name      Venue name.
 * @param {string} props.address   Address.
 * @param {string} props.city      City.
 * @param {string} props.country   Country.
 * @param {number} props.latitude  Latitude.
 * @param {number} props.longitude Longitude.
 */
export function LocationDisplay( {
	name,
	address,
	city,
	country,
	latitude,
	longitude,
} ) {
	const hasCoords = latitude && longitude;
	const mapUrl = hasCoords
		? `https://www.openstreetmap.org/?mlat=${ latitude }&mlon=${ longitude }#map=16/${ latitude }/${ longitude }`
		: null;

	return (
		<div className="post-kinds-location h-adr">
			{ name && <strong className="p-name">{ name }</strong> }
			{ address && <span className="p-street-address">{ address }</span> }
			{ city && <span className="p-locality">{ city }</span> }
			{ country && <span className="p-country-name">{ country }</span> }
			{ hasCoords && (
				<span className="coordinates">
					<data className="p-latitude" value={ latitude } />
					<data className="p-longitude" value={ longitude } />
					{ mapUrl && (
						<a
							href={ mapUrl }
							target="_blank"
							rel="noopener noreferrer"
							className="map-link"
						>
							{ __( 'View on map', 'post-kinds-for-indieweb' ) }
						</a>
					) }
				</span>
			) }
		</div>
	);
}
