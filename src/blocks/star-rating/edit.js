/**
 * Star Rating Block - Edit Component
 *
 * @package Reactions_For_IndieWeb
 */

import { __ } from '@wordpress/i18n';
import {
    useBlockProps,
    InspectorControls,
} from '@wordpress/block-editor';
import {
    PanelBody,
    TextControl,
    SelectControl,
    ToggleControl,
    RangeControl,
} from '@wordpress/components';
import { starIcon } from '../shared/icons';

/**
 * Edit component for the Star Rating block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block edit component.
 */
export default function Edit({ attributes, setAttributes }) {
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

    const blockProps = useBlockProps({
        className: `star-rating-block size-${size} style-${style}`,
    });

    // Size options
    const sizeOptions = [
        { label: __('Small', 'reactions-indieweb'), value: 'small' },
        { label: __('Medium', 'reactions-indieweb'), value: 'medium' },
        { label: __('Large', 'reactions-indieweb'), value: 'large' },
    ];

    // Style options
    const styleOptions = [
        { label: __('Stars', 'reactions-indieweb'), value: 'stars' },
        { label: __('Hearts', 'reactions-indieweb'), value: 'hearts' },
        { label: __('Circles', 'reactions-indieweb'), value: 'circles' },
        { label: __('Numeric', 'reactions-indieweb'), value: 'numeric' },
    ];

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
     * Handle rating click
     */
    const handleRatingClick = (newRating) => {
        // Toggle off if clicking same rating
        if (newRating === rating) {
            setAttributes({ rating: 0 });
        } else {
            setAttributes({ rating: newRating });
        }
    };

    /**
     * Handle keyboard navigation
     */
    const handleKeyDown = (e, index) => {
        const newRating = index + 1;
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handleRatingClick(newRating);
        } else if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (rating < maxRating) {
                setAttributes({ rating: rating + (allowHalf ? 0.5 : 1) });
            }
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
            e.preventDefault();
            if (rating > 0) {
                setAttributes({ rating: rating - (allowHalf ? 0.5 : 1) });
            }
        }
    };

    /**
     * Render numeric style
     */
    const renderNumeric = () => (
        <div className="rating-numeric">
            <RangeControl
                value={rating}
                onChange={(value) => setAttributes({ rating: value })}
                min={0}
                max={maxRating}
                step={allowHalf ? 0.5 : 1}
                withInputField
            />
            <span className="rating-display">{rating} / {maxRating}</span>
        </div>
    );

    /**
     * Render star/icon style
     */
    const renderIcons = () => (
        <div
            className="rating-icons"
            role="radiogroup"
            aria-label={label || __('Rating', 'reactions-indieweb')}
        >
            {Array.from({ length: maxRating }, (_, i) => {
                const value = i + 1;
                const isFilled = value <= rating;
                const isHalfFilled = allowHalf && value - 0.5 === rating;

                return (
                    <button
                        key={i}
                        type="button"
                        className={`rating-icon ${isFilled ? 'filled' : ''} ${isHalfFilled ? 'half-filled' : ''}`}
                        onClick={() => handleRatingClick(value)}
                        onKeyDown={(e) => handleKeyDown(e, i)}
                        role="radio"
                        aria-checked={isFilled}
                        aria-label={`${value} ${value === 1 ? __('star', 'reactions-indieweb') : __('stars', 'reactions-indieweb')}`}
                        tabIndex={i === 0 ? 0 : -1}
                    >
                        {isHalfFilled ? (
                            <span className="half-star">
                                <span className="half-filled-part">{getIcon(true)}</span>
                                <span className="half-empty-part">{getIcon(false)}</span>
                            </span>
                        ) : (
                            <span>{getIcon(isFilled)}</span>
                        )}
                    </button>
                );
            })}
        </div>
    );

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Rating Settings', 'reactions-indieweb')}>
                    <RangeControl
                        label={__('Current Rating', 'reactions-indieweb')}
                        value={rating}
                        onChange={(value) => setAttributes({ rating: value })}
                        min={0}
                        max={maxRating}
                        step={allowHalf ? 0.5 : 1}
                    />
                    <RangeControl
                        label={__('Maximum Rating', 'reactions-indieweb')}
                        value={maxRating}
                        onChange={(value) => setAttributes({ maxRating: value })}
                        min={3}
                        max={10}
                    />
                    <ToggleControl
                        label={__('Allow Half Stars', 'reactions-indieweb')}
                        checked={allowHalf}
                        onChange={(value) => setAttributes({ allowHalf: value })}
                    />
                </PanelBody>

                <PanelBody title={__('Display Options', 'reactions-indieweb')}>
                    <SelectControl
                        label={__('Style', 'reactions-indieweb')}
                        value={style}
                        options={styleOptions}
                        onChange={(value) => setAttributes({ style: value })}
                    />
                    <SelectControl
                        label={__('Size', 'reactions-indieweb')}
                        value={size}
                        options={sizeOptions}
                        onChange={(value) => setAttributes({ size: value })}
                    />
                    <ToggleControl
                        label={__('Show Label', 'reactions-indieweb')}
                        checked={showLabel}
                        onChange={(value) => setAttributes({ showLabel: value })}
                    />
                    {showLabel && (
                        <TextControl
                            label={__('Label Text', 'reactions-indieweb')}
                            value={label}
                            onChange={(value) => setAttributes({ label: value })}
                        />
                    )}
                    <ToggleControl
                        label={__('Show Value', 'reactions-indieweb')}
                        checked={showValue}
                        onChange={(value) => setAttributes({ showValue: value })}
                    />
                </PanelBody>

                <PanelBody title={__('Rated Item', 'reactions-indieweb')} initialOpen={false}>
                    <TextControl
                        label={__('Item Name', 'reactions-indieweb')}
                        value={itemName || ''}
                        onChange={(value) => setAttributes({ itemName: value })}
                        help={__('What are you rating?', 'reactions-indieweb')}
                    />
                    <TextControl
                        label={__('Item URL', 'reactions-indieweb')}
                        value={itemUrl || ''}
                        onChange={(value) => setAttributes({ itemUrl: value })}
                        type="url"
                    />
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div className="star-rating-inner">
                    {showLabel && label && (
                        <span className="rating-label">{label}</span>
                    )}

                    {style === 'numeric' ? renderNumeric() : renderIcons()}

                    {showValue && style !== 'numeric' && (
                        <span className="rating-value">
                            {rating} / {maxRating}
                        </span>
                    )}
                </div>
            </div>
        </>
    );
}
