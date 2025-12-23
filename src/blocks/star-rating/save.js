/**
 * Star Rating Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

/**
 * Save component for the Star Rating block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block save component.
 */
export default function Save({ attributes }) {
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

    const blockProps = useBlockProps.save({
        className: `star-rating-block size-${size} style-${style}`,
    });

    /**
     * Get the icon for current style
     */
    const getIcon = (filled) => {
        const icons = {
            stars: { filled: '★', empty: '☆' },
            hearts: { filled: '❤️', empty: '🤍' },
            circles: { filled: '●', empty: '○' },
            numeric: { filled: null, empty: null },
        };
        return filled ? icons[style]?.filled : icons[style]?.empty;
    };

    /**
     * Render icons for visual rating
     */
    const renderIcons = () => {
        const icons = [];

        for (let i = 0; i < maxRating; i++) {
            const value = i + 1;
            const isFilled = value <= rating;
            const isHalfFilled = allowHalf && value - 0.5 === rating;

            icons.push(
                <span
                    key={i}
                    className={`rating-icon ${isFilled ? 'filled' : ''} ${isHalfFilled ? 'half-filled' : ''}`}
                    aria-hidden="true"
                >
                    {isHalfFilled ? (
                        <span className="half-star">
                            <span className="half-filled-part">{getIcon(true)}</span>
                            <span className="half-empty-part">{getIcon(false)}</span>
                        </span>
                    ) : (
                        getIcon(isFilled)
                    )}
                </span>
            );
        }

        return icons;
    };

    /**
     * Render numeric display
     */
    const renderNumeric = () => (
        <span className="rating-numeric-display">
            {rating} / {maxRating}
        </span>
    );

    // Calculate percentage for microformat
    const ratingPercent = Math.round((rating / maxRating) * 100);

    return (
        <div {...blockProps}>
            <div className="star-rating-inner h-review" aria-label={`Rating: ${rating} out of ${maxRating}`}>
                {/* Label */}
                {showLabel && label && (
                    <span className="rating-label">{label}</span>
                )}

                {/* Visual rating */}
                <div className="rating-display">
                    {style === 'numeric' ? renderNumeric() : renderIcons()}
                </div>

                {/* Text value */}
                {showValue && style !== 'numeric' && (
                    <span className="rating-value">
                        {rating} / {maxRating}
                    </span>
                )}

                {/* Microformat data */}
                <data className="p-rating" value={rating}>
                    <data className="p-best" value={maxRating} hidden />
                    <data className="p-worst" value="0" hidden />
                </data>

                {/* Item being rated */}
                {itemName && (
                    <span className="p-item h-product" hidden>
                        {itemUrl ? (
                            <a href={itemUrl} className="p-name u-url">{itemName}</a>
                        ) : (
                            <span className="p-name">{itemName}</span>
                        )}
                    </span>
                )}

                {/* Schema.org compatible rating */}
                <span itemProp="reviewRating" itemScope itemType="https://schema.org/Rating" hidden>
                    <meta itemProp="worstRating" content="0" />
                    <meta itemProp="ratingValue" content={rating.toString()} />
                    <meta itemProp="bestRating" content={maxRating.toString()} />
                </span>
            </div>
        </div>
    );
}
