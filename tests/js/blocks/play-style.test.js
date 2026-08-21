/**
 * Tests for the play-card style suggestion helper.
 */
import {
	suggestPlayStyle,
	applySuggestedStyle,
} from '../../../src/blocks/shared/play-style';

describe( 'suggestPlayStyle', () => {
	it( 'maps tabletop platform labels to their styles', () => {
		expect( suggestPlayStyle( { platform: 'Board Game' } ) ).toBe(
			'board-game'
		);
		expect( suggestPlayStyle( { platform: 'Miniatures' } ) ).toBe(
			'board-game'
		);
		expect( suggestPlayStyle( { platform: 'Card Game' } ) ).toBe(
			'card-game'
		);
		expect( suggestPlayStyle( { platform: 'Tabletop RPG' } ) ).toBe(
			'ttrpg'
		);
		expect( suggestPlayStyle( { platform: 'Dice Game' } ) ).toBe(
			'dice-game'
		);
	} );

	it( 'maps console platforms to console-game', () => {
		expect( suggestPlayStyle( { platform: 'Nintendo Switch' } ) ).toBe(
			'console-game'
		);
		expect( suggestPlayStyle( { platform: 'PlayStation 5' } ) ).toBe(
			'console-game'
		);
		expect( suggestPlayStyle( { platform: 'Xbox Series X/S' } ) ).toBe(
			'console-game'
		);
	} );

	it( 'maps computer platforms to computer-game', () => {
		expect( suggestPlayStyle( { platform: 'Windows' } ) ).toBe(
			'computer-game'
		);
		expect( suggestPlayStyle( { platform: 'Steam Deck' } ) ).toBe(
			'computer-game'
		);
		expect( suggestPlayStyle( { platform: 'Linux' } ) ).toBe(
			'computer-game'
		);
	} );

	it( 'platform outranks title keywords and source', () => {
		expect(
			suggestPlayStyle( {
				platform: 'Board Game',
				source: 'rawg',
				title: 'Dungeons & Dragons Online',
			} )
		).toBe( 'board-game' );
	} );

	it( 'falls back to title keywords when platform is empty', () => {
		expect( suggestPlayStyle( { title: 'Pathfinder 2e session' } ) ).toBe(
			'ttrpg'
		);
		expect( suggestPlayStyle( { title: 'Yahtzee night' } ) ).toBe(
			'dice-game'
		);
		expect(
			suggestPlayStyle( { title: 'Magic: The Gathering draft' } )
		).toBe( 'card-game' );
	} );

	it( 'falls back to lookup source last', () => {
		expect( suggestPlayStyle( { source: 'bgg' } ) ).toBe( 'board-game' );
		expect( suggestPlayStyle( { source: 'steam' } ) ).toBe(
			'computer-game'
		);
		expect( suggestPlayStyle( { source: 'rawg' } ) ).toBe( 'console-game' );
	} );

	it( 'returns null with no signal', () => {
		expect( suggestPlayStyle( {} ) ).toBeNull();
		expect( suggestPlayStyle( { title: 'Wingspan' } ) ).toBeNull();
	} );
} );

describe( 'applySuggestedStyle', () => {
	it( 'appends the style class to an empty className', () => {
		expect( applySuggestedStyle( '', 'board-game' ) ).toBe(
			'is-style-board-game'
		);
		expect( applySuggestedStyle( undefined, 'ttrpg' ) ).toBe(
			'is-style-ttrpg'
		);
	} );

	it( 'preserves unrelated classes', () => {
		expect( applySuggestedStyle( 'my-extra', 'dice-game' ) ).toBe(
			'my-extra is-style-dice-game'
		);
	} );

	it( 'never overrides a user-picked style', () => {
		expect(
			applySuggestedStyle( 'is-style-card-game', 'board-game' )
		).toBeNull();
		expect(
			applySuggestedStyle( 'foo is-style-ttrpg bar', 'dice-game' )
		).toBeNull();
	} );

	it( 'returns null for a null suggestion', () => {
		expect( applySuggestedStyle( 'foo', null ) ).toBeNull();
	} );
} );
