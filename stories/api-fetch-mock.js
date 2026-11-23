import apiFetch from '@wordpress/api-fetch';

// Core routes every editor has. @wordpress/editor's block hooks read them for
// blocks with color or border supports.
const CORE_ROUTES = {
	'/wp/v2/settings': () => ( {} ),
	'/wp/v2/themes': () => [ { stylesheet: 'storybook', theme_supports: {} } ],
};

// Route table keyed by REST pathname. Each handler receives the query
// parameters and returns the response body, or a promise of it.
let routes = CORE_ROUTES;

// Replaces the network: every request resolves from `routes` or fails loudly,
// so a story that reaches an unmocked route errors instead of fetching.
apiFetch.setFetchHandler( async ( { path = '', url = '' } ) => {
	const target = new URL( path || url, 'http://rest.invalid' );
	const respond = routes[ target.pathname ];
	if ( ! respond ) {
		// eslint-disable-next-line no-console
		console.error( `Unmocked apiFetch request: ${ target.pathname }` );
		throw { code: 'unmocked', message: `No mock for ${ target.pathname }` };
	}
	return respond( Object.fromEntries( target.searchParams ) );
} );

// MediaSearch in src/blocks/shared/components.js calls the global wp.apiFetch.
window.wp = { ...window.wp, apiFetch };

export function mockApiFetch( next ) {
	routes = { ...CORE_ROUTES, ...next };
	return () => {
		routes = CORE_ROUTES;
	};
}

export const never = () => new Promise( () => {} );
