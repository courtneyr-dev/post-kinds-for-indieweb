import { render, screen } from '@testing-library/react';
import {
	createRegistry,
	createReduxStore,
	RegistryProvider,
} from '@wordpress/data';
import { store as postKindsStore } from '../../../src/editor/stores/post-kinds';
import SyndicationControls from '../../../src/editor/kind-selector/components/SyndicationControls';

jest.mock( '@wordpress/data', () => jest.requireActual( '@wordpress/data' ) );
jest.mock( '@wordpress/editor', () => ( { store: 'core/editor' } ) );
jest.mock( '@wordpress/components', () => ( {
	ToggleControl: ( { label, checked } ) => (
		<input
			type="checkbox"
			aria-label={ label }
			checked={ checked }
			readOnly
		/>
	),
	PanelRow: ( { children } ) => <div>{ children }</div>,
	Flex: ( { children } ) => <div>{ children }</div>,
} ) );

afterEach( () => {
	delete window.pkiwAdminEditor;
} );

describe.each( [
	[ 'lastfm', 'Last.fm', 'listen' ],
	[ 'trakt', 'Trakt', 'watch' ],
	[ 'foursquare', 'Foursquare', 'checkin' ],
] )( '%s', ( service, name, kind ) => {
	it.each( [
		[ false, false ],
		[ true, true ],
		[ undefined, true ],
	] )( 'shows a saved value of %s as checked %s', ( saved, checked ) => {
		window.pkiwAdminEditor = {
			syndicationServices: {
				[ service ]: { name, kind, connected: true },
			},
		};
		const meta =
			saved === undefined
				? {}
				: { [ `_pkiw_syndicate_${ service }` ]: saved };
		const registry = createRegistry();
		registry.register(
			createReduxStore( 'core/editor', {
				reducer: ( state = { meta } ) => state,
				selectors: {
					getEditedPostAttribute: ( state, key ) => state[ key ],
				},
			} )
		);
		registry.register( postKindsStore );

		render(
			<RegistryProvider value={ registry }>
				<SyndicationControls kind={ kind } />
			</RegistryProvider>
		);

		expect( screen.getByRole( 'checkbox', { name } ).checked ).toBe(
			checked
		);
	} );
} );
