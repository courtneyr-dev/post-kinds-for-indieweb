/**
 * Star Rating Block - Deprecations
 *
 * v1 (pre-1.8.6): the accessible name lived in an `aria-label` on a plain
 * `<div>` with no ARIA role — aria-label only has a defined mapping on an
 * element with a role that supports naming, so it was unreliable at best.
 * 1.8.6 removes it and adds a visually-hidden text alternative instead.
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

/**
 * v1 save output — matches the block as it shipped before 1.8.6.
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @return {JSX.Element} Block save component.
 */
function saveV1( { attributes } ) {
	const {
		rating,
		maxRating,
		showLabel,
		label,
		showValue,
		size,
		style,
		allowHalf,
		itemUrl,
		itemName,
	} = attributes;

	const blockProps = useBlockProps.save( {
		className: `star-rating-block size-${ size } style-${ style }`,
	} );

	const getIcon = ( filled ) => {
		const icons = {
			stars: { filled: '★', empty: '☆' },
			hearts: { filled: '❤️', empty: '🤍' },
			circles: { filled: '●', empty: '○' },
			numeric: { filled: null, empty: null },
		};
		return filled ? icons[ style ]?.filled : icons[ style ]?.empty;
	};

	const renderIcons = () => {
		const icons = [];

		for ( let i = 0; i < maxRating; i++ ) {
			const value = i + 1;
			const isFilled = value <= rating;
			const isHalfFilled = allowHalf && value - 0.5 === rating;

			icons.push(
				<span
					key={ i }
					className={ `rating-icon ${ isFilled ? 'filled' : '' } ${
						isHalfFilled ? 'half-filled' : ''
					}` }
					aria-hidden="true"
				>
					{ isHalfFilled ? (
						<span className="half-star">
							<span className="half-filled-part">
								{ getIcon( true ) }
							</span>
							<span className="half-empty-part">
								{ getIcon( false ) }
							</span>
						</span>
					) : (
						getIcon( isFilled )
					) }
				</span>
			);
		}

		return icons;
	};

	const renderNumeric = () => (
		<span className="rating-numeric-display">
			{ rating } / { maxRating }
		</span>
	);

	return (
		<div { ...blockProps }>
			<div
				className="star-rating-inner h-review"
				aria-label={ `Rating: ${ rating } out of ${ maxRating }` }
			>
				{ showLabel && label && (
					<span className="rating-label">{ label }</span>
				) }

				<div className="rating-display">
					{ style === 'numeric' ? renderNumeric() : renderIcons() }
				</div>

				{ showValue && style !== 'numeric' && (
					<span className="rating-value">
						{ rating } / { maxRating }
					</span>
				) }

				<data className="p-rating" value={ rating }>
					<data className="p-best" value={ maxRating } hidden />
					<data className="p-worst" value="0" hidden />
				</data>

				{ itemName && (
					<span className="p-item h-product" hidden>
						{ itemUrl ? (
							<a href={ itemUrl } className="p-name u-url">
								{ itemName }
							</a>
						) : (
							<span className="p-name">{ itemName }</span>
						) }
					</span>
				) }

				<span
					itemProp="reviewRating"
					itemScope
					itemType="https://schema.org/Rating"
					hidden
				>
					<meta itemProp="worstRating" content="0" />
					<meta
						itemProp="ratingValue"
						content={ rating.toString() }
					/>
					<meta
						itemProp="bestRating"
						content={ maxRating.toString() }
					/>
				</span>
			</div>
		</div>
	);
}

const deprecated = [
	{
		attributes: {
			rating: { type: 'number', default: 0 },
			maxRating: { type: 'number', default: 5 },
			showLabel: { type: 'boolean', default: true },
			label: { type: 'string', default: 'Rating' },
			showValue: { type: 'boolean', default: true },
			size: { type: 'string', default: 'medium' },
			style: { type: 'string', default: 'stars' },
			allowHalf: { type: 'boolean', default: false },
			itemUrl: { type: 'string' },
			itemName: { type: 'string' },
		},
		save: saveV1,
	},
];

export default deprecated;
