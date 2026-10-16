/**
 * Mood card intensity in the editor (issue 248).
 *
 * The card used to write _pkiw_mood_rating = 3 on mount whenever meta was 0,
 * which stored an intensity nobody picked. Now meta is written only on a pick
 * or a reset, a rating in meta moves the attribute, and a rating cleared in
 * the Kind Fields sidebar (it writes 0) unsets the attribute so nothing
 * serializes.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	createBlock,
	getCategories,
	serialize,
	setCategories,
} from '@wordpress/blocks';
import Edit from '../../../src/blocks/mood-card/edit';

// The real store, so the block registers, with the two hooks the edit view
// calls swapped for mocks.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );
	const hooks = { useSelect: jest.fn(), useDispatch: jest.fn() };
	return new Proxy( actual, {
		get: ( target, key ) => ( key in hooks ? hooks[ key ] : target[ key ] ),
	} );
} );

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: ( props = {} ) => props,
	InspectorControls: ( { children } ) => <div>{ children }</div>,
	RichText: () => null,
} ) );

jest.mock( '../../../src/blocks/shared/mood-vocabulary', () => ( {
	useMoodVocabulary: () => [],
	moodDisplayLabel: ( mood ) => mood || '',
	moodKeyForLabel: () => '',
	MoodSuggestions: () => null,
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => <div>{ children }</div>,
	TextControl: () => null,
	SelectControl: () => null,
	RangeControl: ( { value, onChange, allowReset } ) => (
		<div>
			<span data-testid="range-value">{ String( value ) }</span>
			<button type="button" onClick={ () => onChange( 3 ) }>
				range 3
			</button>
			{ allowReset && (
				<button type="button" onClick={ () => onChange( undefined ) }>
					reset
				</button>
			) }
		</div>
	),
} ) );

const editPost = jest.fn();
const setAttributes = jest.fn();
let mockMeta = {};

beforeEach( () => {
	jest.clearAllMocks();
	mockMeta = {};
	useDispatch.mockImplementation( () => ( { editPost } ) );
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getEditedPostAttribute: ( name ) =>
				'kind' === name ? [ 5 ] : mockMeta,
		} ) )
	);
} );

/**
 * Meta writes that touch the rating.
 *
 * @return {Array} editPost calls that name _pkiw_mood_rating.
 */
const ratingWrites = () =>
	editPost.mock.calls.filter(
		( [ update ] ) => update?.meta && '_pkiw_mood_rating' in update.meta
	);

/**
 * setAttributes calls that name the intensity.
 *
 * @return {Array} Calls.
 */
const intensityAttributeWrites = () =>
	setAttributes.mock.calls.filter(
		( [ update ] ) => update && 'intensity' in update
	);

const filledDots = ( container ) =>
	container.querySelectorAll( '.post-kinds-card__intensity-dot.filled' )
		.length;

const mount = ( attributes ) =>
	render(
		<Edit attributes={ attributes } setAttributes={ setAttributes } />
	);

describe( 'Mood card intensity', () => {
	it( 'writes nothing when mounted with meta 0 and the default attribute', () => {
		const { container } = mount( { emoji: '😎', intensity: 3 } );

		expect( ratingWrites() ).toEqual( [] );
		expect( intensityAttributeWrites() ).toEqual( [] );
		expect( filledDots( container ) ).toBe( 0 );
	} );

	it( 'writes nothing when mounted with meta 0 and a serialized attribute of 2', () => {
		const { container } = mount( { emoji: '😎', intensity: 2 } );

		expect( ratingWrites() ).toEqual( [] );
		expect( intensityAttributeWrites() ).toEqual( [] );
		expect( filledDots( container ) ).toBe( 2 );
		expect( screen.getByTestId( 'range-value' ).textContent ).toBe( '2' );
	} );

	it( 'writes meta 3 when 3 is picked, which the comment never holds', () => {
		const { container } = mount( { intensity: 3 } );

		fireEvent.click(
			container.querySelectorAll( '.post-kinds-card__intensity-dot' )[ 2 ]
		);

		expect( ratingWrites() ).toEqual( [
			[ { meta: { _pkiw_mood_rating: 3 } } ],
		] );
		expect( intensityAttributeWrites() ).toEqual( [
			[ { intensity: 3 } ],
		] );
	} );

	it( 'writes the picked value from the range control too', () => {
		mount( { intensity: 3 } );

		fireEvent.click( screen.getByText( 'range 3' ) );

		expect( ratingWrites() ).toEqual( [
			[ { meta: { _pkiw_mood_rating: 3 } } ],
		] );
	} );

	it( 'writes meta 0 and unsets the attribute on reset', () => {
		mockMeta = { _pkiw_mood_rating: 4 };
		mount( { intensity: 4 } );
		setAttributes.mockClear();

		fireEvent.click( screen.getByText( 'reset' ) );

		expect( ratingWrites() ).toEqual( [
			[ { meta: { _pkiw_mood_rating: 0 } } ],
		] );
		expect( intensityAttributeWrites() ).toEqual( [
			[ { intensity: undefined } ],
		] );
	} );

	it( 'sets the attribute to 4 when meta holds 4', () => {
		mockMeta = { _pkiw_mood_rating: 4 };
		const { container } = mount( { intensity: 3 } );

		expect( intensityAttributeWrites() ).toEqual( [
			[ { intensity: 4 } ],
		] );
		expect( filledDots( container ) ).toBe( 4 );
		expect( ratingWrites() ).toEqual( [] );
	} );

	it( 'shows a meta pick of 3 as 3 when the attribute is the default', () => {
		mockMeta = { _pkiw_mood_rating: 3 };
		const { container } = mount( { intensity: 3 } );

		expect( filledDots( container ) ).toBe( 3 );
		expect( intensityAttributeWrites() ).toEqual( [] );
	} );

	it( 'unsets the attribute when the sidebar clears a rating picked on the card', () => {
		setCategories( [
			{
				slug: 'post-kinds-indieweb',
				title: 'Post Kinds for IndieWeb in Block Themes',
			},
			...getCategories(),
		] );
		require( '../../../src/blocks/mood-card' );

		// Pick 4 on the card: the attribute serializes and meta holds 4.
		const { container, rerender } = mount( { emoji: '😎', intensity: 3 } );
		fireEvent.click(
			container.querySelectorAll( '.post-kinds-card__intensity-dot' )[ 3 ]
		);
		expect( intensityAttributeWrites() ).toEqual( [
			[ { intensity: 4 } ],
		] );
		expect(
			serialize(
				createBlock( 'post-kinds-indieweb/mood-card', {
					emoji: '😎',
					intensity: 4,
				} )
			)
		).toContain( '"intensity":4' );

		// The editor applies both writes.
		mockMeta = { _pkiw_mood_rating: 4 };
		rerender(
			<Edit
				attributes={ { emoji: '😎', intensity: 4 } }
				setAttributes={ setAttributes }
			/>
		);
		setAttributes.mockClear();

		// The Kind Fields sidebar's 'Select level' writes 0 into the store.
		mockMeta = { _pkiw_mood_rating: 0 };
		rerender(
			<Edit
				attributes={ { emoji: '😎', intensity: 4 } }
				setAttributes={ setAttributes }
			/>
		);

		expect( setAttributes ).toHaveBeenCalledWith( {
			intensity: undefined,
		} );

		// With the attribute unset the comment holds no intensity.
		expect(
			serialize(
				createBlock( 'post-kinds-indieweb/mood-card', {
					emoji: '😎',
					intensity: undefined,
				} )
			)
		).not.toContain( 'intensity' );
	} );

	it( 'does not unset an attribute the post was mounted with when meta was never a pick', () => {
		const { rerender } = mount( { emoji: '😎', intensity: 2 } );

		mockMeta = { _pkiw_mood_rating: 0 };
		rerender(
			<Edit
				attributes={ { emoji: '😎', intensity: 2 } }
				setAttributes={ setAttributes }
			/>
		);

		expect( intensityAttributeWrites() ).toEqual( [] );
	} );
} );
