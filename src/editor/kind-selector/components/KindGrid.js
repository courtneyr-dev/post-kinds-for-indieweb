/**
 * Reactions for IndieWeb - Kind Grid Component
 *
 * A visual grid for selecting post kinds with icons and keyboard navigation.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Tooltip, VisuallyHidden } from '@wordpress/components';
import { useRef, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { kindIcons } from '../icons';

/**
 * Kind Grid Component
 *
 * Displays a grid of kind icons for selection with full keyboard support.
 *
 * @param {Object}   props              Component props.
 * @param {Array}    props.kinds        Available kind terms.
 * @param {string}   props.selectedKind Currently selected kind slug.
 * @param {Function} props.onSelect     Callback when kind is selected.
 * @return {JSX.Element} The grid component.
 */
export default function KindGrid( { kinds, selectedKind, onSelect } ) {
	const gridRef = useRef( null );

	/**
	 * Handle keyboard navigation within the grid.
	 *
	 * @param {KeyboardEvent} event Keyboard event.
	 * @param {number}        index Current button index.
	 */
	const handleKeyDown = useCallback( ( event, index ) => {
		const buttons = gridRef.current?.querySelectorAll( 'button' );
		if ( ! buttons || buttons.length === 0 ) {
			return;
		}

		const columns = 3; // Grid columns.
		let newIndex = index;

		switch ( event.key ) {
			case 'ArrowRight':
				newIndex = index + 1;
				break;
			case 'ArrowLeft':
				newIndex = index - 1;
				break;
			case 'ArrowDown':
				newIndex = index + columns;
				break;
			case 'ArrowUp':
				newIndex = index - columns;
				break;
			case 'Home':
				newIndex = 0;
				break;
			case 'End':
				newIndex = buttons.length - 1;
				break;
			default:
				return;
		}

		// Wrap around.
		if ( newIndex < 0 ) {
			newIndex = buttons.length - 1;
		} else if ( newIndex >= buttons.length ) {
			newIndex = 0;
		}

		event.preventDefault();
		buttons[ newIndex ]?.focus();
	}, [] );

	// Sort kinds to show common ones first.
	const sortedKinds = [ ...kinds ].sort( ( a, b ) => {
		const order = [
			'note', 'article', 'reply', 'like', 'repost', 'bookmark',
			'photo', 'video', 'rsvp', 'checkin', 'listen', 'watch',
			'read', 'event', 'review',
		];
		const aIndex = order.indexOf( a.slug );
		const bIndex = order.indexOf( b.slug );
		return ( aIndex === -1 ? 999 : aIndex ) - ( bIndex === -1 ? 999 : bIndex );
	} );

	return (
		<div
			ref={ gridRef }
			className="reactions-indieweb-kind-grid"
			role="radiogroup"
			aria-label={ __( 'Select post kind', 'reactions-indieweb' ) }
		>
			{ sortedKinds.map( ( kind, index ) => {
				const Icon = kindIcons[ kind.slug ] || kindIcons.note;
				const isSelected = kind.slug === selectedKind;

				return (
					<Tooltip
						key={ kind.slug }
						text={ kind.description || kind.name }
						position="bottom center"
					>
						<Button
							className={ `reactions-indieweb-kind-button ${
								isSelected ? 'is-selected' : ''
							}` }
							onClick={ () => onSelect( kind.slug ) }
							onKeyDown={ ( e ) => handleKeyDown( e, index ) }
							aria-pressed={ isSelected }
							aria-label={ kind.name }
						>
							<Icon />
							<span className="reactions-indieweb-kind-label">
								{ kind.name }
							</span>
							{ isSelected && (
								<VisuallyHidden>
									{ __( '(selected)', 'reactions-indieweb' ) }
								</VisuallyHidden>
							) }
						</Button>
					</Tooltip>
				);
			} ) }

			<style>{ `
				.reactions-indieweb-kind-grid {
					display: grid;
					grid-template-columns: repeat(3, 1fr);
					gap: 8px;
					margin-bottom: 16px;
				}

				.reactions-indieweb-kind-button {
					display: flex;
					flex-direction: column;
					align-items: center;
					justify-content: center;
					padding: 12px 8px;
					border: 1px solid #ddd;
					border-radius: 4px;
					background: #fff;
					cursor: pointer;
					transition: all 0.15s ease;
					min-height: 70px;
				}

				.reactions-indieweb-kind-button:hover {
					border-color: #007cba;
					background: #f0f7fc;
				}

				.reactions-indieweb-kind-button:focus {
					outline: 2px solid #007cba;
					outline-offset: 2px;
				}

				.reactions-indieweb-kind-button.is-selected {
					border-color: #007cba;
					background: #007cba;
					color: #fff;
				}

				.reactions-indieweb-kind-button.is-selected:hover {
					background: #005a8c;
				}

				.reactions-indieweb-kind-button svg {
					width: 24px;
					height: 24px;
					margin-bottom: 4px;
				}

				.reactions-indieweb-kind-button.is-selected svg {
					fill: #fff;
				}

				.reactions-indieweb-kind-label {
					font-size: 11px;
					text-align: center;
					line-height: 1.2;
				}
			` }</style>
		</div>
	);
}
