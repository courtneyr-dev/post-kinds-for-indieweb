/**
 * Kind selector card insertion (issue 249).
 *
 * Picking a kind that has a card inserts the card at the top of the post once.
 * Craft joins the 13 kinds that already do, card only: the steps and the
 * Materials and Tools lists come from the Craft project pattern.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import KindSelectorPanel from '../../../src/editor/kind-selector';

jest.mock( '@wordpress/editor', () => ( {
	PluginDocumentSettingPanel: ( { children } ) => <div>{ children }</div>,
	store: 'core/editor',
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	createBlock: ( name ) => ( { name, innerBlocks: [] } ),
} ) );

jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds',
} ) );

jest.mock( '../../../src/editor/kind-selector/components/KindGrid', () => ( {
	__esModule: true,
	default: ( { kinds, onSelect } ) => (
		<div>
			{ kinds.map( ( kind ) => (
				<button
					key={ kind.slug }
					type="button"
					onClick={ () => onSelect( kind.slug ) }
				>
					{ kind.slug }
				</button>
			) ) }
		</div>
	),
} ) );
jest.mock( '../../../src/editor/kind-selector/components/KindFields', () => ( {
	__esModule: true,
	default: () => null,
} ) );
jest.mock(
	'../../../src/editor/kind-selector/components/AutoDetectionNotice',
	() => ( {
		__esModule: true,
		default: () => null,
	} )
);
jest.mock( '../../../src/editor/kind-selector/icons', () => ( {
	getKindIcon: () => () => null,
} ) );

const insertBlocks = jest.fn();
const replaceBlock = jest.fn();
const updatePostKind = jest.fn();
let mockBlocks = [];

const KINDS = [ 'note', 'craft', 'mood' ].map( ( slug ) => ( { slug } ) );

beforeEach( () => {
	jest.clearAllMocks();
	mockBlocks = [];
	useDispatch.mockImplementation( ( store ) =>
		'core/block-editor' === store
			? { insertBlocks, replaceBlock }
			: {
					initialize: jest.fn(),
					setAutoDetectedKind: jest.fn(),
					updatePostKind,
					disableAutoDetection: jest.fn(),
					updateKindMeta: jest.fn(),
				}
	);
	useSelect.mockImplementation( ( callback ) =>
		callback( ( store ) => {
			if ( 'post-kinds' === store ) {
				return {
					getSelectedKind: () => null,
					getAutoDetectedKind: () => null,
					isAutoDetectionEnabled: () => false,
					getAvailableKinds: () => KINDS,
					isInitialized: () => true,
					getKindMeta: () => '',
				};
			}
			if ( 'core/editor' === store ) {
				return { getEditedPostAttribute: () => '' };
			}
			return { getBlocks: () => mockBlocks };
		} )
	);
} );

describe( 'Kind selector', () => {
	it( 'inserts one craft card at the top when Craft is selected on an empty post', () => {
		render( <KindSelectorPanel /> );

		fireEvent.click( screen.getByText( 'craft' ) );

		expect( updatePostKind ).toHaveBeenCalledWith( 'craft' );
		expect( insertBlocks ).toHaveBeenCalledTimes( 1 );
		expect( insertBlocks ).toHaveBeenCalledWith(
			{ name: 'post-kinds-indieweb/craft-card', innerBlocks: [] },
			0
		);
	} );

	it( 'inserts none when Craft is selected again', () => {
		const { rerender } = render( <KindSelectorPanel /> );
		fireEvent.click( screen.getByText( 'craft' ) );
		expect( insertBlocks ).toHaveBeenCalledTimes( 1 );

		// The editor now holds the card.
		mockBlocks = [
			{ name: 'post-kinds-indieweb/craft-card', innerBlocks: [] },
		];
		rerender( <KindSelectorPanel /> );
		fireEvent.click( screen.getByText( 'craft' ) );

		expect( insertBlocks ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'finds a craft card nested in another block', () => {
		mockBlocks = [
			{
				name: 'core/group',
				innerBlocks: [
					{
						name: 'post-kinds-indieweb/craft-card',
						innerBlocks: [],
					},
				],
			},
		];
		render( <KindSelectorPanel /> );

		fireEvent.click( screen.getByText( 'craft' ) );

		expect( insertBlocks ).not.toHaveBeenCalled();
	} );

	it( 'still inserts the card of a kind that had one, and none for a kind without', () => {
		render( <KindSelectorPanel /> );

		fireEvent.click( screen.getByText( 'mood' ) );
		expect( insertBlocks ).toHaveBeenCalledWith(
			{ name: 'post-kinds-indieweb/mood-card', innerBlocks: [] },
			0
		);

		insertBlocks.mockClear();
		fireEvent.click( screen.getByText( 'note' ) );
		expect( insertBlocks ).not.toHaveBeenCalled();
	} );
} );
