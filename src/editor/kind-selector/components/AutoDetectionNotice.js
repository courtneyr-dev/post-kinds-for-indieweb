/**
 * Reactions for IndieWeb - Auto Detection Notice Component
 *
 * Displays a notice when a post kind has been auto-detected from content.
 *
 * @package ReactionsForIndieWeb
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
 * @param {Object}   props             Component props.
 * @param {string}   props.detectedKind Detected kind slug.
 * @param {string}   props.kindLabel    Human-readable kind label.
 * @param {Function} props.onAccept     Callback to accept the detected kind.
 * @param {Function} props.onDismiss    Callback to dismiss the notice.
 * @return {JSX.Element} The notice component.
 */
export default function AutoDetectionNotice( {
	detectedKind,
	kindLabel,
	onAccept,
	onDismiss,
} ) {
	return (
		<Notice
			status="info"
			isDismissible={ false }
			className="reactions-indieweb-auto-detect-notice"
		>
			<p>
				{ __(
					'Auto-detected as:',
					'reactions-indieweb'
				) }{ ' ' }
				<strong>{ kindLabel }</strong>
			</p>
			<div className="reactions-indieweb-auto-detect-actions">
				<Button
					variant="primary"
					size="small"
					icon={ check }
					onClick={ onAccept }
				>
					{ __( 'Use this', 'reactions-indieweb' ) }
				</Button>
				<Button
					variant="secondary"
					size="small"
					icon={ closeSmall }
					onClick={ onDismiss }
				>
					{ __( 'Choose different', 'reactions-indieweb' ) }
				</Button>
			</div>

			<style>{ `
				.reactions-indieweb-auto-detect-notice {
					margin-bottom: 16px;
				}

				.reactions-indieweb-auto-detect-notice p {
					margin: 0 0 8px 0;
				}

				.reactions-indieweb-auto-detect-actions {
					display: flex;
					gap: 8px;
				}

				.reactions-indieweb-auto-detect-actions .components-button {
					justify-content: center;
				}
			` }</style>
		</Notice>
	);
}
