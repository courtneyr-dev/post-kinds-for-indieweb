/**
 * Play card in the editor (issues 232 PL9 and 372).
 *
 * A board game card that isn't selected shows the server's tabletop, so
 * the canvas matches the published single. Lookups and pasted URLs set
 * only the provider ID they name.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import Edit, {
	bggIdFromUrl,
	gameUrlLabel,
	isBoardGame,
} from '../../../src/blocks/play-card/edit';

jest.mock( '@wordpress/server-side-render', () => ( {
	__esModule: true,
	default: ( {
		block,
		attributes,
		urlQueryArgs,
		skipBlockSupportAttributes,
	} ) => (
		<div
			data-testid="server-side-render"
			data-block={ block }
			data-attributes={ JSON.stringify( attributes ) }
			data-url-query-args={ JSON.stringify( urlQueryArgs ) }
			data-skip-block-support-attributes={ String(
				!! skipBlockSupportAttributes
			) }
		/>
	),
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	TextControl: ( { label, value, onChange } ) => (
		<input
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	),
	SelectControl: () => null,
	RangeControl: () => null,
	ExternalLink: ( { children } ) => <span>{ children }</span>,
	Disabled: ( { children } ) => (
		<div data-testid="disabled">{ children }</div>
	),
} ) );

let mockPickedItem = null;

jest.mock( '../../../src/blocks/shared/components', () => ( {
	StarRating: () => null,
	MediaSearch: ( { onSelect } ) => (
		<button type="button" onClick={ () => onSelect( mockPickedItem ) }>
			pick
		</button>
	),
} ) );

const editPost = jest.fn();

beforeEach( () => {
	mockPickedItem = null;
	editPost.mockReset();
	useDispatch.mockImplementation( () => ( { editPost } ) );
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getEditedPostAttribute: ( key ) => ( 'kind' === key ? [ 5 ] : {} ),
			getCurrentPostId: () => 7,
		} ) )
	);
} );

const BOARD = {
	title: 'Forest Paths',
	bggId: '9990001',
	status: 'completed',
	gameUrl: 'https://example.test/games/forest-paths',
};

describe( 'Play card editor preview', () => {
	it( 'shows the server tabletop for a board game card that is not selected', () => {
		render(
			<Edit
				attributes={ BOARD }
				setAttributes={ jest.fn() }
				isSelected={ false }
			/>
		);

		const preview = screen.getByTestId( 'server-side-render' );
		expect( preview.dataset.block ).toBe( 'post-kinds-indieweb/play-card' );
		expect( JSON.parse( preview.dataset.attributes ) ).toEqual( BOARD );
		// The post's ID lets the server read its featured image and tell
		// whether the card title repeats the post title.
		expect( JSON.parse( preview.dataset.urlQueryArgs ) ).toEqual( {
			post_id: 7,
		} );
		expect( preview.dataset.skipBlockSupportAttributes ).toBe( 'true' );
		expect( preview.closest( '[data-testid="disabled"]' ) ).not.toBeNull();
		expect( screen.queryByTitle( 'Search for game' ) ).toBeNull();
	} );

	it( 'shows the edit UI for a selected board game card', () => {
		render(
			<Edit
				attributes={ BOARD }
				setAttributes={ jest.fn() }
				isSelected={ true }
			/>
		);

		expect( screen.queryByTestId( 'server-side-render' ) ).toBeNull();
		expect( screen.getByTitle( 'Search for game' ) ).toBeInTheDocument();
	} );

	it.each( [
		[ 'no provider', { title: 'Backyard Tag' } ],
		[ 'a RAWG ID', { title: 'Starbound Courier', rawgId: '900001' } ],
		[ 'a Steam ID', { title: 'Garden Circuit', steamId: '900002' } ],
		[
			'a RAWG ID beside a BGG ID',
			{ title: 'Clockwork Harbor', rawgId: '900003', bggId: '900103' },
		],
		[ 'a blank BGG ID', { title: 'Copper Kite', bggId: '   ' } ],
	] )(
		'keeps the edit UI for a card with %s that is not selected',
		( label, attributes ) => {
			render(
				<Edit
					attributes={ attributes }
					setAttributes={ jest.fn() }
					isSelected={ false }
				/>
			);

			expect( screen.queryByTestId( 'server-side-render' ) ).toBeNull();
			expect(
				screen.getByTitle( 'Search for game' )
			).toBeInTheDocument();
		}
	);
} );

describe( 'isBoardGame', () => {
	it( 'follows the server rule: a BGG ID and no video ID', () => {
		expect( isBoardGame( { bggId: '13' } ) ).toBe( true );
		expect( isBoardGame( { bggId: '13', rawgId: '1' } ) ).toBe( false );
		expect( isBoardGame( { bggId: '13', steamId: '1' } ) ).toBe( false );
		expect( isBoardGame( { bggId: '  ' } ) ).toBe( false );
		expect( isBoardGame( {} ) ).toBe( false );
	} );
} );

describe( 'Game lookup', () => {
	it( 'clears the RAWG and Steam IDs when a BGG result is chosen', () => {
		const setAttributes = jest.fn();
		mockPickedItem = {
			source: 'bgg',
			id: 13,
			title: 'Catan',
			url: 'https://boardgamegeek.com/boardgame/13/catan',
		};
		render(
			<Edit
				attributes={ {
					title: 'Old pick',
					rawgId: '900001',
					steamId: '900002',
				} }
				setAttributes={ setAttributes }
				isSelected={ true }
			/>
		);

		fireEvent.click( screen.getAllByText( 'pick' )[ 0 ] );

		expect( setAttributes ).toHaveBeenCalledWith(
			expect.objectContaining( {
				bggId: '13',
				rawgId: '',
				steamId: '',
			} )
		);
	} );

	it( 'clears the BGG and Steam IDs when a RAWG result is chosen', () => {
		const setAttributes = jest.fn();
		mockPickedItem = {
			source: 'rawg',
			id: 900001,
			title: 'Starbound Courier',
		};
		render(
			<Edit
				attributes={ { title: 'Old pick', bggId: '13', steamId: '7' } }
				setAttributes={ setAttributes }
				isSelected={ true }
			/>
		);

		fireEvent.click( screen.getAllByText( 'pick' )[ 0 ] );

		expect( setAttributes ).toHaveBeenCalledWith(
			expect.objectContaining( {
				bggId: '',
				rawgId: '900001',
				steamId: '',
			} )
		);
	} );
} );

describe( 'Pasted game URL (issue 372)', () => {
	const paste = ( url ) => {
		const setAttributes = jest.fn();
		render(
			<Edit
				attributes={ { title: '' } }
				setAttributes={ setAttributes }
				isSelected={ true }
			/>
		);
		fireEvent.change( screen.getByLabelText( 'Paste BGG/VGG URL' ), {
			target: { value: url },
		} );
		return setAttributes.mock.calls.reduce(
			( merged, [ updates ] ) => ( { ...merged, ...updates } ),
			{}
		);
	};

	it.each( [
		[ 'https://boardgamegeek.com/boardgame/13/catan', '13', 'Catan' ],
		[
			'https://boardgamegeek.com/boardgameexpansion/461932/wingspan-americas-expansion',
			'461932',
			'Wingspan Americas Expansion',
		],
		[ 'https://www.boardgamegeek.com/boardgame/13', '13', undefined ],
	] )( '%s sets bggId %s', ( url, id, title ) => {
		const updates = paste( url );

		expect( updates.gameUrl ).toBe( url );
		expect( updates.bggId ).toBe( id );
		expect( updates.title ).toBe( title );
	} );

	it.each( [
		[ 'https://videogamegeek.com/videogame/12345/game-name', 'Game Name' ],
		[
			'https://boardgamegeek.com/videogame/69327/the-legend-of-zelda',
			'The Legend Of Zelda',
		],
		[ 'https://boardgamegeek.com/rpgitem/5000/core-rules', 'Core Rules' ],
	] )( '%s keeps the URL and title but sets no bggId', ( url, title ) => {
		const updates = paste( url );

		expect( updates.gameUrl ).toBe( url );
		expect( updates.title ).toBe( title );
		expect( updates ).not.toHaveProperty( 'bggId' );
	} );
} );

describe( 'bggIdFromUrl', () => {
	it.each( [
		[ 'https://boardgamegeek.com/boardgame/13/catan', '13' ],
		[ 'http://boardgamegeek.com/boardgameexpansion/7', '7' ],
		[ 'https://BoardGameGeek.com/boardgame/13', '13' ],
		[ 'https://videogamegeek.com/videogame/12345/game-name', '' ],
		[ 'https://boardgamegeek.com/videogame/69327/zelda', '' ],
		[ 'https://boardgamegeek.com/rpgitem/5000/core-rules', '' ],
		[ 'https://boardgamegeek.com/thing/13', '' ],
		[ 'https://boardgamegeek.com.example/boardgame/13', '' ],
		[ 'https://example.test/boardgamegeek.com/boardgame/13', '' ],
		[ 'ftp://boardgamegeek.com/boardgame/13', '' ],
		[ 'not a url', '' ],
		[ '', '' ],
	] )( '%s gives %p', ( url, id ) => {
		expect( bggIdFromUrl( url ) ).toBe( id );
	} );
} );

describe( 'Game link label', () => {
	it.each( [
		[ 'https://boardgamegeek.com/boardgame/13/catan', 'View on BGG' ],
		[ 'https://rawg.io/games/starbound-courier', 'View on RAWG' ],
		[ 'https://store.steampowered.com/app/900002', 'View on Steam' ],
		[ 'https://www.games.example/backyard-tag', 'games.example' ],
		[ 'https://example.test/games/forest-paths', 'example.test' ],
	] )( '%s reads %s, as the server prints it', ( url, label ) => {
		expect( gameUrlLabel( url ) ).toBe( label );

		render(
			<Edit
				attributes={ { title: 'Any game', gameUrl: url } }
				setAttributes={ jest.fn() }
				isSelected={ true }
			/>
		);
		expect(
			screen.getByText( label, { selector: 'a' } )
		).toBeInTheDocument();
	} );
} );
