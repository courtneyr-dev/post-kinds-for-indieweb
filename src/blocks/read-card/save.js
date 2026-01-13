/**
 * Read Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';

/**
 * Save component for the Read Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block save component.
 */
export default function Save({ attributes }) {
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

    const blockProps = useBlockProps.save({
        className: `read-card layout-${layout} status-${readStatus}`,
    });

    // Calculate progress percentage
    const progressPercent = pageCount && currentPage
        ? Math.min(100, Math.round((currentPage / pageCount) * 100))
        : 0;

    // Generate stars for rating
    const renderStars = () => {
        if (!rating || rating <= 0) {
            return null;
        }

        return (
            <div className="reactions-card__rating p-rating" aria-label={`Rating: ${rating} out of 5 stars`}>
                {Array.from({ length: 5 }, (_, i) => (
                    <span
                        key={i}
                        className={`star ${i < rating ? 'filled' : ''}`}
                        aria-hidden="true"
                    >
                        ★
                    </span>
                ))}
                <span className="reactions-card__rating-value">{rating}/5</span>
            </div>
        );
    };

    // Get status label
    const getStatusLabel = () => {
        switch (readStatus) {
            case 'to-read':
                return 'To Read';
            case 'reading':
                return 'Currently Reading';
            case 'finished':
                return 'Finished';
            case 'abandoned':
                return 'Abandoned';
            default:
                return '';
        }
    };

    return (
        <div {...blockProps}>
            <div className="reactions-card h-cite">
                {/* Cover image */}
                {coverImage && (
                    <div className="reactions-card__media reactions-card__media--portrait">
                        <img
                            src={coverImage}
                            alt={coverImageAlt || `Cover of ${bookTitle}`}
                            className="reactions-card__image u-photo"
                            loading="lazy"
                        />
                    </div>
                )}

                <div className="reactions-card__content">
                    {/* Status badge */}
                    <span className={`reactions-card__badge reactions-card__badge--${readStatus}`}>
                        {getStatusLabel()}
                    </span>

                    {/* Book title */}
                    {bookTitle && (
                        <h3 className="reactions-card__title p-name">
                            {bookUrl ? (
                                <a href={bookUrl} className="u-url" target="_blank" rel="noopener noreferrer">
                                    {bookTitle}
                                </a>
                            ) : (
                                bookTitle
                            )}
                        </h3>
                    )}

                    {/* Author */}
                    {authorName && (
                        <p className="reactions-card__subtitle">
                            <span className="p-author h-card">
                                <span className="p-name">{authorName}</span>
                            </span>
                        </p>
                    )}

                    {/* Publisher and date */}
                    {(publisher || publishDate) && (
                        <p className="reactions-card__meta">
                            {publisher && <span>{publisher}</span>}
                            {publisher && publishDate && ', '}
                            {publishDate && <span>{publishDate}</span>}
                        </p>
                    )}

                    {/* Reading progress */}
                    {readStatus === 'reading' && progressPercent > 0 && (
                        <div className="reactions-card__progress">
                            <div className="reactions-card__progress-bar">
                                <div
                                    className="reactions-card__progress-fill"
                                    style={{ width: `${progressPercent}%` }}
                                    role="progressbar"
                                    aria-valuenow={progressPercent}
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                />
                            </div>
                            <span className="reactions-card__progress-text">
                                {currentPage} of {pageCount} pages ({progressPercent}%)
                            </span>
                        </div>
                    )}

                    {/* Rating */}
                    {renderStars()}

                    {/* Review */}
                    {review && (
                        <div className="reactions-card__notes p-content">
                            <RichText.Content tagName="p" value={review} />
                        </div>
                    )}

                    {/* Reading dates */}
                    {(startedAt || finishedAt) && (
                        <div className="reactions-card__dates">
                            {startedAt && (
                                <time className="reactions-card__timestamp" dateTime={new Date(startedAt).toISOString()}>
                                    Started: {new Date(startedAt).toLocaleDateString()}
                                </time>
                            )}
                            {finishedAt && (readStatus === 'finished' || readStatus === 'abandoned') && (
                                <time className="reactions-card__timestamp dt-published" dateTime={new Date(finishedAt).toISOString()}>
                                    Finished: {new Date(finishedAt).toLocaleDateString()}
                                </time>
                            )}
                        </div>
                    )}
                </div>

                {/* Hidden microformat data */}
                <data className="u-read-of" value={bookUrl || ''} hidden />
                {isbn && <data className="p-isbn" value={isbn} hidden />}
                {openlibraryId && (
                    <data
                        className="u-uid"
                        value={`https://openlibrary.org${openlibraryId}`}
                        hidden
                    />
                )}
            </div>
        </div>
    );
}
