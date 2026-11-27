/**
 * AI suggestions panel.
 *
 * Reads readiness from /post-kinds/v1/ai/status and announces it in a polite
 * live region. React always renders the region empty; an effect writes the
 * message into it. A newly mounted region element (the panel opens, or its
 * Slot mounts again) gets its first message REGION_SETTLE_MS later, in a
 * separate task, so assistive technology already tracks the region when the
 * text arrives (WCAG 2.2 SC 4.1.3).
 * When a provider is ready it asks for typed citation suggestions and offers
 * each one with its own Apply button. Suggestions belong to the post and
 * cited URL they were asked for; a change to either hides them and drops a
 * late response. Nothing is written until the author applies a suggestion,
 * and a filled field is never overwritten. A site that hasn't opted in sees
 * no panel.
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
export const REGION_SETTLE_MS = 100;
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

/**
 * Key naming the post and cited URL a suggestion request belongs to.
 *
 * @param {number|string} postId  Post ID.
 * @param {?string}       citeUrl Cited URL meta value.
 * @return {string} Identity key.
 */
const identityOf = ( postId, citeUrl ) =>
	JSON.stringify( [ postId ?? null, isFilled( citeUrl ) ? citeUrl : '' ] );

export default function AiSuggestionsPanel() {
	const { postType, postId, meta } = useSelect(
		( select ) => ( {
			postType: select( 'core/editor' ).getCurrentPostType(),
			postId: select( 'core/editor' ).getCurrentPostId(),
			meta:
				select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
		} ),
		[]
	);
	const { editPost } = useDispatch( 'core/editor' );

	const [ status, setStatus ] = useState( null );
	const [ announcement, setAnnouncement ] = useState( '' );
	const [ regionNode, setRegionNode ] = useState( null );
	const filledRegion = useRef( null );
	const [ plan, setPlan ] = useState( { identity: '', items: [] } );
	const [ applied, setApplied ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const itemRefs = useRef( {} );

	const citeUrl = meta[ META_PREFIX + 'cite_url' ];
	const identity = identityOf( postId, citeUrl );
	const identityRef = useRef( identity );
	identityRef.current = identity;

	const visible =
		postType === 'post' && !! status && status.state !== 'disabled';

	// A region element mounts empty; its first message waits for a later task.
	useEffect( () => {
		if ( ! regionNode ) {
			return;
		}
		const fill = () => {
			regionNode.textContent = announcement;
			filledRegion.current = regionNode;
		};
		if ( filledRegion.current === regionNode ) {
			fill();
			return;
		}
		const timer = setTimeout( fill, REGION_SETTLE_MS );
		return () => clearTimeout( timer );
	}, [ regionNode, announcement ] );

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

	if ( ! visible ) {
		return null;
	}

	const ready = status.state === 'ready';
	const items = plan.identity === identity ? plan.items : [];

	const changedMessage = __(
		'The cited URL changed. Ask for suggestions again.',
		'post-kinds-for-indieweb-in-block-themes'
	);

	const requestSuggestions = () => {
		const asked = identity;
		setBusy( true );
		setPlan( { identity: '', items: [] } );
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
				if ( identityRef.current !== asked ) {
					setAnnouncement( changedMessage );
					return;
				}
				const next = planSuggestions( response?.suggestions, meta );
				setPlan( { identity: asked, items: next } );
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
			.catch( ( error ) =>
				setAnnouncement(
					identityRef.current === asked
						? errorMessage( error )
						: changedMessage
				)
			)
			.finally( () => setBusy( false ) );
	};

	const apply = ( suggestion ) => {
		if ( plan.identity !== identityRef.current ) {
			setAnnouncement( changedMessage );
			return;
		}
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
				ref={ setRegionNode }
				className="pkiw-ai-suggestions__status"
				role="status"
				aria-live="polite"
				aria-atomic="true"
			/>
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
			{ items.length ? (
				<ul className="pkiw-ai-suggestions__list">
					{ items.map( ( suggestion ) => {
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
