/**
 * Post Kinds for IndieWeb in Block Themes - Auto Detection Notice Component
 *
 * Displays a notice when a post kind has been auto-detected from content.
 *
 * @package
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Notice, Button } from '@wordpress/components';
import { check, closeSmall } from '@wordpress/icons';

/**
 * Auto Detection Notice Component
 *
 * Shows a dismissible notice with the auto-detected kind and actions.
 *
 * @param {Object}   props           Component props.
 * @param {string}   props.kindLabel Human-readable kind label.
 * @param {Function} props.onAccept  Callback to accept the detected kind.
 * @param {Function} props.onDismiss Callback to dismiss the notice.
 * @return {JSX.Element} The notice component.
 */
export default function AutoDetectionNotice( {
	kindLabel,
	onAccept,
	onDismiss,
} ) {
	return (
		<Notice
			status="info"
			isDismissible={ false }
			className="post-kinds-indieweb-auto-detect-notice"
		>
			<p>
				{ __(
					'Auto-detected as:',
					'post-kinds-for-indieweb-in-block-themes'
				) }{ ' ' }
				<strong>{ kindLabel }</strong>
			</p>
			<div className="post-kinds-indieweb-auto-detect-actions">
				<Button
					variant="primary"
					size="small"
					icon={ check }
					onClick={ onAccept }
				>
					{ __(
						'Use this',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</Button>
				<Button
					variant="secondary"
					size="small"
					icon={ closeSmall }
					onClick={ onDismiss }
				>
					{ __(
						'Choose different',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</Button>
			</div>

			<style>{ `
				.post-kinds-indieweb-auto-detect-notice {
					margin-bottom: 16px;
				}

				.post-kinds-indieweb-auto-detect-notice p {
					margin: 0 0 8px 0;
				}

				.post-kinds-indieweb-auto-detect-actions {
					display: flex;
					gap: 8px;
				}

				.post-kinds-indieweb-auto-detect-actions .components-button {
					justify-content: center;
				}
			` }</style>
		</Notice>
	);
}
