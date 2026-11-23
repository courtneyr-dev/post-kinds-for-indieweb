import { createBlock } from '@wordpress/blocks';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	PluginArea,
	registerPlugin,
	unregisterPlugin,
} from '@wordpress/plugins';
import { expect, userEvent, waitFor, within } from 'storybook/test';
import './blocks.js';
import KindSelectorPanel from '../src/editor/kind-selector';
import { mockApiFetch } from './api-fetch-mock.js';
import { draftPost, EditorContext, EditorFrame } from './editor-context.js';
import {
	KINDS,
	kindId,
	listenCover,
	MUSIC_RESULTS,
	SYNDICATION,
} from './fixtures.js';

const PLUGIN = 'post-kinds-indieweb-kind-selector';

const listenMeta = {
	_pkiw_listen_track: 'Hounds of Love',
	_pkiw_listen_artist: 'Kate Bush',
	_pkiw_listen_album: 'Hounds of Love',
	_pkiw_listen_cover: listenCover,
	_pkiw_listen_rating: 4,
};

export default {
	title: 'Editor/Kind selector panel',
	parameters: {
		layout: 'fullscreen',
		docs: {
			description: {
				component:
					'Source: `src/editor/kind-selector/index.js` (`KindSelectorPanel`), registered by `src/editor/index.js` with `registerPlugin` and rendered in the Post sidebar through `PluginDocumentSettingPanel`. ' +
					'It composes `components/AutoDetectionNotice.js`, `components/KindGrid.js` and `components/KindFields.js`. ' +
					'The editor loads it on every post type in the `kind` taxonomy (`includes/class-plugin.php`, `enqueue_editor_assets`). ' +
					'Kinds come from `Taxonomy::$default_kinds` in `includes/class-taxonomy.php`, served here by a mocked `GET /wp/v2/kind`. ' +
					'Choosing a kind inserts its card block; these stories register only the Listen Card, Star Rating and Media Lookup blocks.',
			},
		},
	},
	args: {
		post: draftPost(),
		blocks: [],
		syndicationServices: {},
		routes: {},
	},
	beforeEach: ( { args } ) => {
		window.pkiwAdminEditor = {
			bookmarkCardActive: false,
			syndicationServices: args.syndicationServices,
		};
		registerPlugin( PLUGIN, { render: KindSelectorPanel, icon: null } );
		const restore = mockApiFetch( {
			'/wp/v2/kind': () => KINDS,
			...args.routes,
		} );
		return () => {
			restore();
			unregisterPlugin( PLUGIN );
			delete window.pkiwAdminEditor;
		};
	},
	render: ( { post, blocks }, { id } ) => (
		<EditorContext key={ id } post={ post }>
			<PluginArea />
			<EditorFrame
				initialBlocks={ blocks }
				sidebar={ <PluginDocumentSettingPanel.Slot /> }
			/>
		</EditorContext>
	),
};

const panel = ( canvasElement ) =>
	within( canvasElement ).findByRole( 'radiogroup', {
		name: 'Select post kind',
	} );

export const NewPostAutoDetectedNote = {
	play: async ( { canvasElement } ) => {
		await panel( canvasElement );
		await within( canvasElement ).findByText( 'Auto-detected as:' );
	},
};

export const AutoDetectedFromListenCard = {
	args: {
		blocks: [ createBlock( 'post-kinds-indieweb/listen-card' ) ],
	},
	play: async ( { canvasElement } ) => {
		const canvas = within( canvasElement );
		await canvas.findByText( 'Listen', { selector: 'strong' } );
	},
};

export const ChooseListenInsertsCard = {
	play: async ( { canvasElement } ) => {
		const canvas = within( canvasElement );
		const grid = await panel( canvasElement );
		await userEvent.click(
			within( grid ).getByRole( 'button', { name: 'Listen' } )
		);
		await waitFor( () =>
			expect(
				within( grid ).getByRole( 'button', { name: 'Listen' } )
			).toHaveAttribute( 'aria-pressed', 'true' )
		);
		await waitFor( () =>
			expect(
				canvasElement.querySelector(
					'[data-type="post-kinds-indieweb/listen-card"]'
				)
			).not.toBeNull()
		);
		expect( canvas.queryByText( 'Auto-detected as:' ) ).toBeNull();
	},
};

export const GridArrowKeysMoveFocus = {
	play: async ( { canvasElement } ) => {
		const grid = await panel( canvasElement );
		const buttons = within( grid ).getAllByRole( 'button' );
		buttons[ 0 ].focus();
		await userEvent.keyboard( '{ArrowRight}' );
		expect( buttons[ 1 ] ).toHaveFocus();
		await userEvent.keyboard( '{ArrowDown}' );
		expect( buttons[ 4 ] ).toHaveFocus();
		await userEvent.keyboard( '{End}' );
		expect( buttons[ buttons.length - 1 ] ).toHaveFocus();
		await userEvent.keyboard( '{ArrowRight}' );
		expect( buttons[ 0 ] ).toHaveFocus();
	},
};

export const ListenWithTrack = {
	args: {
		post: draftPost( { kind: [ kindId( 'listen' ) ], meta: listenMeta } ),
	},
	play: async ( { canvasElement } ) => {
		const canvas = within( canvasElement );
		await waitFor( () =>
			expect(
				canvas.getByRole( 'textbox', { name: 'Artist' } )
			).toHaveValue( 'Kate Bush' )
		);
	},
};

export const ListenMusicSearch = {
	args: {
		post: draftPost( { kind: [ kindId( 'listen' ) ] } ),
		routes: {
			'/post-kinds-indieweb/v1/lookup/music': () => MUSIC_RESULTS,
		},
	},
	play: async ( { canvasElement } ) => {
		const canvas = within( canvasElement );
		await panel( canvasElement );
		const search = await canvas.findByPlaceholderText(
			'Track name or artist…'
		);
		await userEvent.type( search, 'Kate Bush{Enter}' );
		await userEvent.click(
			await canvas.findByText( 'Running Up That Hill' )
		);
		await waitFor( () =>
			expect(
				canvas.getByRole( 'textbox', { name: 'Track' } )
			).toHaveValue( 'Running Up That Hill' )
		);
	},
};

export const ListenSyndicationConnected = {
	args: {
		post: draftPost( { kind: [ kindId( 'listen' ) ], meta: listenMeta } ),
		syndicationServices: SYNDICATION.lastfmConnected,
	},
	play: async ( { canvasElement } ) => {
		await within( canvasElement ).findByRole( 'checkbox', {
			name: 'Last.fm',
		} );
	},
};

export const WatchEmpty = {
	args: { post: draftPost( { kind: [ kindId( 'watch' ) ] } ) },
};

export const ReadWithBook = {
	args: {
		post: draftPost( {
			kind: [ kindId( 'read' ) ],
			meta: {
				_pkiw_read_title: 'The Left Hand of Darkness',
				_pkiw_read_author: 'Ursula K. Le Guin',
				_pkiw_read_status: 'reading',
				_pkiw_read_progress: 40,
			},
		} ),
	},
};

export const RsvpYes = {
	args: {
		post: draftPost( {
			kind: [ kindId( 'rsvp' ) ],
			meta: {
				_pkiw_rsvp_status: 'yes',
				_pkiw_cite_name: 'IndieWebCamp Portland',
				_pkiw_cite_url: 'https://events.indieweb.org/example',
			},
		} ),
	},
};

export const CheckinEmpty = {
	args: { post: draftPost( { kind: [ kindId( 'checkin' ) ] } ) },
};
