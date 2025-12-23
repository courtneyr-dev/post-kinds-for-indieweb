/**
 * Read Card Block - Edit Component
 *
 * @package Reactions_For_IndieWeb
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
    __experimentalNumberControl as NumberControl,
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
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block edit component.
 */
export default function Edit({ attributes, setAttributes }) {
    const {
        bookTitle,
        authorName,
        isbn,
        publisher,
        publishDate,
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

    const [showStartPicker, setShowStartPicker] = useState(false);
    const [showFinishPicker, setShowFinishPicker] = useState(false);
    const [isSearching, setIsSearching] = useState(false);

    const blockProps = useBlockProps({
        className: `read-card layout-${layout} status-${readStatus}`,
    });

    // Calculate progress percentage
    const progressPercent = pageCount && currentPage
        ? Math.min(100, Math.round((currentPage / pageCount) * 100))
        : 0;

    /**
     * Handle book search result selection
     */
    const handleSearchSelect = (item) => {
        setAttributes({
            bookTitle: item.title || '',
            authorName: item.author || item.authors?.join(', ') || '',
            isbn: item.isbn || item.isbn_13?.[0] || item.isbn_10?.[0] || '',
            publisher: item.publisher || item.publishers?.[0] || '',
            publishDate: item.publish_date || item.first_publish_year?.toString() || '',
            pageCount: item.number_of_pages || item.pages || null,
            coverImage: item.cover || item.image || '',
            coverImageAlt: item.title || '',
            openlibraryId: item.key || item.olid || '',
        });
        setIsSearching(false);
    };

    /**
     * Handle cover image selection
     */
    const handleImageSelect = (media) => {
        setAttributes({
            coverImage: media.url,
            coverImageAlt: media.alt || bookTitle,
        });
    };

    // Show placeholder if no book info
    if (!bookTitle && !authorName) {
        return (
            <div {...blockProps}>
                <BlockPlaceholder
                    icon={readIcon}
                    label={__('Read Card', 'reactions-indieweb')}
                    instructions={__('Add a book you\'re reading or have read. Search or enter details manually.', 'reactions-indieweb')}
                >
                    {isSearching ? (
                        <div className="search-mode">
                            <MediaSearch
                                type="book"
                                placeholder={__('Search by title, author, or ISBN...', 'reactions-indieweb')}
                                onSelect={handleSearchSelect}
                            />
                            <Button
                                variant="link"
                                onClick={() => setIsSearching(false)}
                            >
                                {__('Enter manually', 'reactions-indieweb')}
                            </Button>
                        </div>
                    ) : (
                        <div className="placeholder-actions">
                            <Button
                                variant="primary"
                                onClick={() => setIsSearching(true)}
                            >
                                {__('Search Books', 'reactions-indieweb')}
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => setAttributes({ bookTitle: '' })}
                            >
                                {__('Enter Manually', 'reactions-indieweb')}
                            </Button>
                        </div>
                    )}
                </BlockPlaceholder>
            </div>
        );
    }

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Book Details', 'reactions-indieweb')}>
                    <TextControl
                        label={__('Title', 'reactions-indieweb')}
                        value={bookTitle || ''}
                        onChange={(value) => setAttributes({ bookTitle: value })}
                    />
                    <TextControl
                        label={__('Author', 'reactions-indieweb')}
                        value={authorName || ''}
                        onChange={(value) => setAttributes({ authorName: value })}
                    />
                    <TextControl
                        label={__('ISBN', 'reactions-indieweb')}
                        value={isbn || ''}
                        onChange={(value) => setAttributes({ isbn: value })}
                    />
                    <TextControl
                        label={__('Publisher', 'reactions-indieweb')}
                        value={publisher || ''}
                        onChange={(value) => setAttributes({ publisher: value })}
                    />
                    <NumberControl
                        label={__('Total Pages', 'reactions-indieweb')}
                        value={pageCount}
                        onChange={(value) => setAttributes({ pageCount: parseInt(value) || null })}
                        min={1}
                    />
                </PanelBody>

                <PanelBody title={__('Reading Status', 'reactions-indieweb')}>
                    <SelectControl
                        label={__('Status', 'reactions-indieweb')}
                        value={readStatus}
                        options={[
                            { label: __('To Read', 'reactions-indieweb'), value: 'to-read' },
                            { label: __('Currently Reading', 'reactions-indieweb'), value: 'reading' },
                            { label: __('Finished', 'reactions-indieweb'), value: 'finished' },
                            { label: __('Abandoned', 'reactions-indieweb'), value: 'abandoned' },
                        ]}
                        onChange={(value) => setAttributes({ readStatus: value })}
                    />

                    {readStatus === 'reading' && pageCount && (
                        <RangeControl
                            label={__('Current Page', 'reactions-indieweb')}
                            value={currentPage || 0}
                            onChange={(value) => setAttributes({ currentPage: value })}
                            min={0}
                            max={pageCount}
                            help={`${progressPercent}% complete`}
                        />
                    )}

                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('Rating', 'reactions-indieweb')}
                        </label>
                        <StarRating
                            value={rating}
                            onChange={(value) => setAttributes({ rating: value })}
                            max={5}
                        />
                    </div>

                    <div className="components-base-control">
                        <label className="components-base-control__label">
                            {__('Started', 'reactions-indieweb')}
                        </label>
                        <Button
                            variant="secondary"
                            onClick={() => setShowStartPicker(true)}
                        >
                            {startedAt
                                ? new Date(startedAt).toLocaleDateString()
                                : __('Set date', 'reactions-indieweb')
                            }
                        </Button>
                        {showStartPicker && (
                            <Popover onClose={() => setShowStartPicker(false)}>
                                <DateTimePicker
                                    currentDate={startedAt}
                                    onChange={(value) => {
                                        setAttributes({ startedAt: value });
                                        setShowStartPicker(false);
                                    }}
                                />
                            </Popover>
                        )}
                    </div>

                    {(readStatus === 'finished' || readStatus === 'abandoned') && (
                        <div className="components-base-control">
                            <label className="components-base-control__label">
                                {__('Finished', 'reactions-indieweb')}
                            </label>
                            <Button
                                variant="secondary"
                                onClick={() => setShowFinishPicker(true)}
                            >
                                {finishedAt
                                    ? new Date(finishedAt).toLocaleDateString()
                                    : __('Set date', 'reactions-indieweb')
                                }
                            </Button>
                            {showFinishPicker && (
                                <Popover onClose={() => setShowFinishPicker(false)}>
                                    <DateTimePicker
                                        currentDate={finishedAt}
                                        onChange={(value) => {
                                            setAttributes({ finishedAt: value });
                                            setShowFinishPicker(false);
                                        }}
                                    />
                                </Popover>
                            )}
                        </div>
                    )}
                </PanelBody>

                <PanelBody title={__('Layout', 'reactions-indieweb')}>
                    <SelectControl
                        label={__('Layout Style', 'reactions-indieweb')}
                        value={layout}
                        options={[
                            { label: __('Horizontal', 'reactions-indieweb'), value: 'horizontal' },
                            { label: __('Vertical', 'reactions-indieweb'), value: 'vertical' },
                            { label: __('Cover Focus', 'reactions-indieweb'), value: 'cover' },
                            { label: __('Compact', 'reactions-indieweb'), value: 'compact' },
                        ]}
                        onChange={(value) => setAttributes({ layout: value })}
                    />
                </PanelBody>

                <PanelBody title={__('Links', 'reactions-indieweb')} initialOpen={false}>
                    <TextControl
                        label={__('Book URL', 'reactions-indieweb')}
                        value={bookUrl || ''}
                        onChange={(value) => setAttributes({ bookUrl: value })}
                        type="url"
                    />
                    <TextControl
                        label={__('Open Library ID', 'reactions-indieweb')}
                        value={openlibraryId || ''}
                        onChange={(value) => setAttributes({ openlibraryId: value })}
                    />
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div className="read-card-inner h-cite">
                    <div className="cover-image">
                        <MediaUploadCheck>
                            <MediaUpload
                                onSelect={handleImageSelect}
                                allowedTypes={['image']}
                                render={({ open }) => (
                                    <div onClick={open} role="button" tabIndex={0}>
                                        <CoverImage
                                            src={coverImage}
                                            alt={coverImageAlt}
                                            size="large"
                                        />
                                    </div>
                                )}
                            />
                        </MediaUploadCheck>
                    </div>

                    <div className="read-info">
                        <span className={`status-badge status-${readStatus}`}>
                            {readStatus === 'to-read' && __('To Read', 'reactions-indieweb')}
                            {readStatus === 'reading' && __('Reading', 'reactions-indieweb')}
                            {readStatus === 'finished' && __('Finished', 'reactions-indieweb')}
                            {readStatus === 'abandoned' && __('Abandoned', 'reactions-indieweb')}
                        </span>

                        <RichText
                            tagName="h3"
                            className="book-title p-name"
                            value={bookTitle}
                            onChange={(value) => setAttributes({ bookTitle: value })}
                            placeholder={__('Book title', 'reactions-indieweb')}
                        />

                        <RichText
                            tagName="p"
                            className="author-name p-author h-card"
                            value={authorName}
                            onChange={(value) => setAttributes({ authorName: value })}
                            placeholder={__('Author name', 'reactions-indieweb')}
                        />

                        {readStatus === 'reading' && progressPercent > 0 && (
                            <ProgressBar
                                value={progressPercent}
                                label={`${currentPage} of ${pageCount} pages`}
                            />
                        )}

                        {rating > 0 && (
                            <div className="rating-display">
                                <StarRating value={rating} readonly={true} max={5} />
                            </div>
                        )}

                        <RichText
                            tagName="p"
                            className="book-review"
                            value={review}
                            onChange={(value) => setAttributes({ review: value })}
                            placeholder={__('Write a review...', 'reactions-indieweb')}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}
