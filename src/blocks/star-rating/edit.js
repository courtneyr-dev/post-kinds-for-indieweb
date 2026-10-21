/**
 * Star Rating Block - Edit Component
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	ToggleControl,
	RangeControl,
} from '@wordpress/components';
// starIcon available from ../shared/icons if needed

/**
 * Edit component for the Star Rating block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
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

	const blockProps = useBlockProps( {
		className: `star-rating-block size-${ size } style-${ style }`,
	} );

	// Size options
	const sizeOptions = [
		{
			label: __( 'Small', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'small',
		},
		{
			label: __( 'Medium', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'medium',
		},
		{
			label: __( 'Large', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'large',
		},
	];

	// Style options
	const styleOptions = [
		{
			label: __( 'Stars', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'stars',
		},
		{
			label: __( 'Hearts', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'hearts',
		},
		{
			label: __( 'Circles', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'circles',
		},
		{
			label: __( 'Numeric', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'numeric',
		},
	];

	/**
	 * Get the icon for current style
	 *
	 * @param {boolean} filled Whether to return filled icon.
	 * @return {string|null} Icon character or null.
	 */
	const getIcon = ( filled ) => {
		const icons = {
			stars: { filled: '★', empty: '☆' },
			hearts: { filled: '❤️', empty: '🤍' },
			circles: { filled: '●', empty: '○' },
			numeric: { filled: null, empty: null },
		};
		return filled ? icons[ style ]?.filled : icons[ style ]?.empty;
	};

	/**
	 * Handle rating click
	 *
	 * @param {number} newRating New rating value.
	 */
	const handleRatingClick = ( newRating ) => {
		// Toggle off if clicking same rating
		if ( newRating === rating ) {
			setAttributes( { rating: 0 } );
		} else {
			setAttributes( { rating: newRating } );
		}
	};

	/**
	 * Handle keyboard navigation
	 *
	 * @param {KeyboardEvent} e     Keyboard event.
	 * @param {number}        index Star index.
	 */
	const handleKeyDown = ( e, index ) => {
		const newRating = index + 1;
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			handleRatingClick( newRating );
		} else if ( e.key === 'ArrowRight' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			if ( rating < maxRating ) {
				setAttributes( { rating: rating + ( allowHalf ? 0.5 : 1 ) } );
			}
		} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowDown' ) {
			e.preventDefault();
			if ( rating > 0 ) {
				setAttributes( { rating: rating - ( allowHalf ? 0.5 : 1 ) } );
			}
		}
	};

	/**
	 * Render numeric style
	 */
	const renderNumeric = () => (
		<div className="rating-numeric">
			<RangeControl
				value={ rating }
				onChange={ ( value ) => setAttributes( { rating: value } ) }
				min={ 0 }
				max={ maxRating }
				step={ allowHalf ? 0.5 : 1 }
				withInputField
			/>
			<span className="rating-display">
				{ rating } / { maxRating }
			</span>
		</div>
	);

	/**
	 * Render star/icon style
	 */
	const renderIcons = () => (
		<div
			className="rating-icons"
			role="radiogroup"
			aria-label={
				label ||
				__( 'Rating', 'post-kinds-for-indieweb-in-block-themes' )
			}
		>
			{ Array.from( { length: maxRating }, ( _, i ) => {
				const value = i + 1;
				const isFilled = value <= rating;
				const isHalfFilled = allowHalf && value - 0.5 === rating;

				return (
					<button
						key={ i }
						type="button"
						className={ `rating-icon ${
							isFilled ? 'filled' : ''
						} ${ isHalfFilled ? 'half-filled' : '' }` }
						onClick={ () => handleRatingClick( value ) }
						onKeyDown={ ( e ) => handleKeyDown( e, i ) }
						role="radio"
						aria-checked={ isFilled }
						aria-label={ `${ value } ${
							value === 1
								? __(
										'star',
										'post-kinds-for-indieweb-in-block-themes'
								  )
								: __(
										'stars',
										'post-kinds-for-indieweb-in-block-themes'
								  )
						}` }
						tabIndex={ i === 0 ? 0 : -1 }
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
							<span>{ getIcon( isFilled ) }</span>
						) }
					</button>
				);
			} ) }
		</div>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Rating Settings',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<RangeControl
						label={ __(
							'Current Rating',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ rating }
						onChange={ ( value ) =>
							setAttributes( { rating: value } )
						}
						min={ 0 }
						max={ maxRating }
						step={ allowHalf ? 0.5 : 1 }
					/>
					<RangeControl
						label={ __(
							'Maximum Rating',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ maxRating }
						onChange={ ( value ) =>
							setAttributes( { maxRating: value } )
						}
						min={ 3 }
						max={ 10 }
					/>
					<ToggleControl
						label={ __(
							'Allow Half Stars',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ allowHalf }
						onChange={ ( value ) =>
							setAttributes( { allowHalf: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Display Options',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Style',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ style }
						options={ styleOptions }
						onChange={ ( value ) =>
							setAttributes( { style: value } )
						}
					/>
					<SelectControl
						label={ __(
							'Size',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ size }
						options={ sizeOptions }
						onChange={ ( value ) =>
							setAttributes( { size: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Label',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showLabel }
						onChange={ ( value ) =>
							setAttributes( { showLabel: value } )
						}
					/>
					{ showLabel && (
						<TextControl
							label={ __(
								'Label Text',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ label }
							onChange={ ( value ) =>
								setAttributes( { label: value } )
							}
						/>
					) }
					<ToggleControl
						label={ __(
							'Show Value',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showValue }
						onChange={ ( value ) =>
							setAttributes( { showValue: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __(
						'Rated Item',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Item Name',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ itemName || '' }
						onChange={ ( value ) =>
							setAttributes( { itemName: value } )
						}
						help={ __(
							'What are you rating?',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'Item URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ itemUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { itemUrl: value } )
						}
						type="url"
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="star-rating-inner">
					{ showLabel && label && (
						<span className="rating-label">{ label }</span>
					) }

					{ style === 'numeric' ? renderNumeric() : renderIcons() }

					{ showValue && style !== 'numeric' && (
						<span className="rating-value">
							{ rating } / { maxRating }
						</span>
					) }
				</div>
			</div>
		</>
	);
}
