/**
 * AI suggestions panel.
 *
 * Reads readiness from /post-kinds/v1/ai/status and announces it in a polite
 * live region. When a provider is ready it asks for typed citation
 * suggestions and offers each one with its own Apply button. Nothing is
 * written until the author applies a suggestion, and a filled field is never
 * overwritten. A site that hasn't opted in sees no panel.
 *
 * @package
 * @since 1.11.0
 */

import apiFetch from '@wordpress/api-fetch';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	META_PREFIX,
	applyEdit,
	isFilled,
	planSuggestions,
} from './plan-suggestions';

export const STATUS_PATH = '/post-kinds/v1/ai/status';
export const SUGGEST_PATH = '/post-kinds/v1/ai/auto-populate';

/**
 * A message safe to show for a failed suggestion request.
 *
 * Only the plugin's own fixed messages (pkiw_ codes, including the rate
 * limit) are shown; anything else gets a generic sentence, so no provider
 * text reaches the editor.
 *
 * @param {?Object} error Rejected apiFetch value.
 * @return {string} Message.
 */
export const errorMessage = ( error ) =>
	error &&
	typeof error.code === 'string' &&
	error.code.startsWith( 'pkiw_' ) &&
	typeof error.message === 'string'
		? error.message
		: __(
				'Suggestions could not be loaded.',
				'post-kinds-for-indieweb-in-block-themes'
			);

export default function AiSuggestionsPanel() {
	const { postType, meta } = useSelect(
		( select ) => ( {
			postType: select( 'core/editor' ).getCurrentPostType(),
			meta:
				select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
		} ),
		[]
	);
	const { editPost } = useDispatch( 'core/editor' );

	const [ status, setStatus ] = useState( null );
	const [ announcement, setAnnouncement ] = useState( '' );
	const [ plan, setPlan ] = useState( [] );
	const [ applied, setApplied ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const itemRefs = useRef( {} );

	useEffect( () => {
		let live = true;
		apiFetch( { path: STATUS_PATH } )
			.then( ( report ) => {
				if ( live ) {
					setStatus( report );
					setAnnouncement( report?.message || '' );
				}
			} )
			.catch( () => {
				if ( live ) {
					setStatus( { state: 'unknown' } );
					setAnnouncement(
						__(
							'AI readiness could not be checked.',
							'post-kinds-for-indieweb-in-block-themes'
						)
					);
				}
			} );
		return () => {
			live = false;
		};
	}, [] );

	if ( postType !== 'post' || ! status || status.state === 'disabled' ) {
		return null;
	}

	const citeUrl = meta[ META_PREFIX + 'cite_url' ];
	const ready = status.state === 'ready';

	const requestSuggestions = () => {
		setBusy( true );
		setPlan( [] );
		setApplied( {} );
		setAnnouncement(
			__(
				'Asking for suggestions…',
				'post-kinds-for-indieweb-in-block-themes'
			)
		);
		apiFetch( {
			path: SUGGEST_PATH,
			method: 'POST',
			data: { url: citeUrl },
		} )
			.then( ( response ) => {
				const next = planSuggestions( response?.suggestions, meta );
				setPlan( next );
				setAnnouncement(
					next.length
						? sprintf(
								/* translators: %d: number of suggestions. */
								_n(
									'%d suggestion ready. Filled fields keep your entries.',
									'%d suggestions ready. Filled fields keep your entries.',
									next.length,
									'post-kinds-for-indieweb-in-block-themes'
								),
								next.length
							)
						: __(
								'No suggestions came back.',
								'post-kinds-for-indieweb-in-block-themes'
							)
				);
			} )
			.catch( ( error ) => setAnnouncement( errorMessage( error ) ) )
			.finally( () => setBusy( false ) );
	};

	const apply = ( suggestion ) => {
		const edit = applyEdit( suggestion, meta );
		if ( edit ) {
			editPost( { meta: edit } );
		}
		setApplied( ( prior ) => ( {
			...prior,
			[ suggestion.field ]: edit ? 'applied' : 'kept',
		} ) );
		setAnnouncement(
			edit
				? sprintf(
						/* translators: %s: citation field label. */
						__(
							'%s applied.',
							'post-kinds-for-indieweb-in-block-themes'
						),
						suggestion.label
					)
				: sprintf(
						/* translators: %s: citation field label. */
						__(
							'%s kept your entry.',
							'post-kinds-for-indieweb-in-block-themes'
						),
						suggestion.label
					)
		);
		itemRefs.current[ suggestion.field ]?.focus();
	};

	return (
		<PluginDocumentSettingPanel
			name="pkiw-ai-suggestions"
			title={ __(
				'AI suggestions',
				'post-kinds-for-indieweb-in-block-themes'
			) }
		>
			<p
				className="pkiw-ai-suggestions__status"
				role="status"
				aria-live="polite"
				aria-atomic="true"
			>
				{ announcement }
			</p>
			{ status.connectorsUrl ? (
				<p>
					<a href={ status.connectorsUrl }>
						{ __(
							'Connect a provider in Settings > Connectors',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</a>
				</p>
			) : null }
			{ ready ? (
				<>
					<Button
						type="button"
						variant="secondary"
						onClick={ requestSuggestions }
						disabled={ busy || ! isFilled( citeUrl ) }
						accessibleWhenDisabled
						aria-describedby={
							isFilled( citeUrl )
								? undefined
								: 'pkiw-ai-suggestions-needs-url'
						}
					>
						{ __(
							'Suggest citation details',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</Button>
					{ isFilled( citeUrl ) ? null : (
						<p id="pkiw-ai-suggestions-needs-url">
							{ __(
								'Add the cited URL first.',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</p>
					) }
				</>
			) : null }
			{ plan.length ? (
				<ul className="pkiw-ai-suggestions__list">
					{ plan.map( ( suggestion ) => {
						const outcome =
							applied[ suggestion.field ] ||
							( suggestion.keep ? 'kept' : '' );
						return (
							<li
								key={ suggestion.field }
								tabIndex={ -1 }
								ref={ ( node ) => {
									itemRefs.current[ suggestion.field ] = node;
								} }
							>
								<strong>{ suggestion.label }</strong>{ ' ' }
								<span>{ suggestion.value }</span>{ ' ' }
								{ outcome === 'applied' ? (
									<span>
										{ __(
											'Applied.',
											'post-kinds-for-indieweb-in-block-themes'
										) }
									</span>
								) : null }
								{ outcome === 'kept' ? (
									<span>
										{ __(
											'Kept your entry.',
											'post-kinds-for-indieweb-in-block-themes'
										) }
									</span>
								) : null }
								{ outcome === '' ? (
									<Button
										type="button"
										variant="link"
										onClick={ () => apply( suggestion ) }
										aria-label={ sprintf(
											/* translators: %s: citation field label. */
											__(
												'Apply suggested %s',
												'post-kinds-for-indieweb-in-block-themes'
											),
											suggestion.label
										) }
									>
										{ __(
											'Apply',
											'post-kinds-for-indieweb-in-block-themes'
										) }
									</Button>
								) : null }
							</li>
						);
					} ) }
				</ul>
			) : null }
		</PluginDocumentSettingPanel>
	);
}
