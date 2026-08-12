/**
 * Standard.site inspector panel.
 *
 * Shown on card blocks that cite an external URL. Checks whether the cited
 * page publishes a standard.site document record and, if so, shows the
 * author's own metadata.
 *
 * Most of the web is not on AT Protocol, so this stays quiet by default: it
 * renders a single button until asked, and says so plainly when there is
 * nothing there. It never fetches on its own.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	PanelBody,
	Button,
	Notice,
	ExternalLink,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Panel body showing the standard.site record behind a cited URL.
 *
 * @param {Object} props     Component props.
 * @param {string} props.url The cited URL, or empty.
 * @return {Element|null} The panel, or null when there is no URL to check.
 */
export default function StandardSitePanel( { url } ) {
	const [ status, setStatus ] = useState( 'idle' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );

	// A new URL invalidates whatever was found for the previous one.
	useEffect( () => {
		setStatus( 'idle' );
		setResult( null );
		setError( '' );
	}, [ url ] );

	if ( ! url ) {
		return null;
	}

	const check = () => {
		setStatus( 'loading' );
		setError( '' );

		apiFetch( {
			path:
				'/post-kinds-indieweb/v1/resolve/standard-site?url=' +
				encodeURIComponent( url ),
		} )
			.then( ( response ) => {
				setResult( response );
				setStatus( 'done' );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Lookup failed.',
							'post-kinds-for-indieweb-in-block-themes'
						)
				);
				setStatus( 'error' );
			} );
	};

	return (
		<PanelBody
			title={ __(
				'Standard.site record',
				'post-kinds-for-indieweb-in-block-themes'
			) }
			initialOpen={ false }
		>
			{ 'idle' === status && (
				<>
					<p>
						{ __(
							'Check whether this page publishes its metadata to AT Protocol.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</p>
					<Button variant="secondary" onClick={ check }>
						{ __(
							'Check this URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</Button>
				</>
			) }

			{ 'loading' === status && (
				<p>
					<Spinner />{ ' ' }
					{ __(
						'Checking…',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</p>
			) }

			{ 'error' === status && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ 'done' === status && result && ! result.found && (
				<p>
					{ __(
						'No standard.site record. Most sites do not publish one — nothing is wrong.',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</p>
			) }

			{ 'done' === status && result && result.found && (
				<>
					{ ! result.verified && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This page claims a record that does not point back at it. Treat the metadata as unverified.',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</Notice>
					) }

					{ result.title && (
						<p>
							<strong>{ result.title }</strong>
						</p>
					) }

					{ result.publication?.name && (
						<p>
							{ sprintf(
								/* translators: %s: publication name. */
								__(
									'From %s',
									'post-kinds-for-indieweb-in-block-themes'
								),
								result.publication.name
							) }
						</p>
					) }

					{ result.description && <p>{ result.description }</p> }

					{ result.tags?.length > 0 && (
						<p>{ result.tags.join( ', ' ) }</p>
					) }

					<p>
						<ExternalLink
							href={ `https://pdsls.dev/${ result.uri }` }
						>
							{ __(
								'View the record',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</ExternalLink>
					</p>
				</>
			) }
		</PanelBody>
	);
}
