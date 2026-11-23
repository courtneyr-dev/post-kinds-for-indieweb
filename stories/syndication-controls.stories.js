import { useSelect } from '@wordpress/data';
import { expect, userEvent, within } from 'storybook/test';
import SyndicationControls from '../src/editor/kind-selector/components/SyndicationControls';
import { draftPost, EditorContext } from './editor-context.js';
import { SYNDICATION } from './fixtures.js';

function StoredValue() {
	const value = useSelect(
		( select ) =>
			select( 'core/editor' ).getEditedPostAttribute( 'meta' )
				._pkiw_syndicate_lastfm,
		[]
	);
	return (
		<p>
			Stored <code>_pkiw_syndicate_lastfm</code>:{ ' ' }
			<output>{ JSON.stringify( value ) ?? 'unset' }</output>
		</p>
	);
}

export default {
	title: 'Editor/Syndication controls',
	component: SyndicationControls,
	parameters: {
		docs: {
			description: {
				component:
					'Source: `src/editor/kind-selector/components/SyndicationControls.js`, rendered by `KindFields` for listen, watch and check-in posts. ' +
					'Services come from `window.pkiwAdminEditor.syndicationServices`, which `includes/class-plugin.php` fills from `get_available_syndication_services()`. ' +
					'A connected service shows a toggle that writes `_pkiw_syndicate_<service>` post meta and defaults to on. A service without a token shows an authorization notice. ' +
					"The toggle cannot show off: `getKindMeta` in `src/editor/stores/post-kinds.js` returns `meta[ key ] || ''`, so a stored `false` reads as an empty string, and the component treats every value except `false` as on. " +
					'The Turn off and Stored off stories record that current behavior.',
			},
		},
	},
	args: { kind: 'listen', services: SYNDICATION.lastfmConnected, meta: {} },
	beforeEach: ( { args } ) => {
		window.pkiwAdminEditor = { syndicationServices: args.services };
		return () => delete window.pkiwAdminEditor;
	},
	render: ( { kind, meta }, { id } ) => (
		<EditorContext key={ id } post={ draftPost( { meta } ) }>
			<div style={ { width: '280px' } }>
				<SyndicationControls kind={ kind } />
				<StoredValue />
			</div>
		</EditorContext>
	),
};

const lastfmToggle = ( canvasElement ) =>
	within( canvasElement ).getByRole( 'checkbox', { name: 'Last.fm' } );

export const ConnectedOn = {
	play: async ( { canvasElement } ) => {
		expect( lastfmToggle( canvasElement ) ).toBeChecked();
		within( canvasElement ).getByText( 'Will sync on publish' );
	},
};

export const TurnOffDoesNotStick = {
	play: async ( { canvasElement } ) => {
		const toggle = lastfmToggle( canvasElement );
		await userEvent.click( toggle );
		await within( canvasElement ).findByText( 'false', {
			selector: 'output',
		} );
		expect( toggle ).toBeChecked();
	},
};

export const StoredOffShowsOn = {
	args: { meta: { _pkiw_syndicate_lastfm: false } },
	play: async ( { canvasElement } ) => {
		expect( lastfmToggle( canvasElement ) ).toBeChecked();
	},
};

export const NeedsAuthorization = {
	args: { services: SYNDICATION.lastfmNeedsAuth },
};
