/**
 * The exercise card stores no markup (issue 245).
 *
 * Distance, duration, splits and the personal best are opt-in details. If
 * save() printed them, post_content would hold them as static HTML, and
 * ActivityPub, search and the feeds read post_content before render.php and
 * the health rule run. save() returns null, so the saved post holds one block
 * comment and nothing else.
 */

// Same stubs as tests/js/blocks/acquisition-card-save.test.js: the real data
// store for registration, and a block-editor stub for the edit imports.
jest.mock( '@wordpress/data', () => jest.requireActual( '@wordpress/data' ) );
jest.mock( '@wordpress/block-editor', () => {
	const useBlockProps = () => ( {} );
	useBlockProps.save = ( props = {} ) => props;
	return {
		useBlockProps,
		InspectorControls: ( { children } ) => children,
	};
} );
jest.mock( '@wordpress/server-side-render', () => () => null );

import {
	createBlock,
	getBlockContent,
	getCategories,
	parse,
	serialize,
	setCategories,
} from '@wordpress/blocks';
import metadata from '../../../src/blocks/exercise-card/block.json';
import Save from '../../../src/blocks/exercise-card/save';

setCategories( [
	{
		slug: 'post-kinds-indieweb',
		title: 'Post Kinds for IndieWeb in Block Themes',
	},
	...getCategories(),
] );

require( '../../../src/blocks/exercise-card' );

const NAME = 'post-kinds-indieweb/exercise-card';

describe( 'Exercise card save', () => {
	it( 'returns null', () => {
		expect( Save() ).toBeNull();
	} );

	it( 'serializes to one comment with no text node', () => {
		const markup = serialize(
			createBlock( NAME, {
				activity: 'Run',
				distance: 7.77,
				duration: 'PT29M12S',
				showDetailsPublicly: true,
			} )
		);

		expect( markup ).toBe(
			'<!-- wp:post-kinds-indieweb/exercise-card {"activity":"Run","distance":7.77,"duration":"PT29M12S","showDetailsPublicly":true} /-->'
		);
		expect(
			getBlockContent(
				createBlock( NAME, { distance: 7.77, activity: 'Run' } )
			)
		).toBe( '' );
	} );

	it( 'drops attributes that equal their defaults from the comment', () => {
		const markup = serialize(
			createBlock( NAME, {
				distanceUnit: 'km',
				personalBest: false,
				showDetailsPublicly: false,
				origin: '',
			} )
		);

		expect( markup ).toBe(
			'<!-- wp:post-kinds-indieweb/exercise-card /-->'
		);
	} );

	it( 'parses a card comment back to its attributes, valid', () => {
		const [ block ] = parse(
			'<!-- wp:post-kinds-indieweb/exercise-card {"activity":"Run","splits":[{"i":1,"t":"PT5M3S"},{"i":2,"t":"PT5M1S"}],"personalBest":true,"pbCategory":"5k"} /-->'
		);

		expect( block.isValid ).toBe( true );
		expect( block.attributes ).toMatchObject( {
			activity: 'Run',
			distanceUnit: 'km',
			personalBest: true,
			pbCategory: '5k',
			showDetailsPublicly: false,
			origin: '',
		} );
		expect( block.attributes.splits ).toEqual( [
			{ i: 1, t: 'PT5M3S' },
			{ i: 2, t: 'PT5M1S' },
		] );
	} );

	it( 'declares the attributes the render test and Card_Meta_Sync use, with no health extras', () => {
		expect( Object.keys( metadata.attributes ) ).toEqual( [
			'activity',
			'distance',
			'distanceUnit',
			'duration',
			'splits',
			'personalBest',
			'pbCategory',
			'showDetailsPublicly',
			'origin',
		] );
		expect( metadata.attributes.distanceUnit ).toEqual( {
			type: 'string',
			default: 'km',
			enum: [ 'km', 'mi' ],
		} );
		expect( metadata.attributes.showDetailsPublicly.default ).toBe( false );
		expect( metadata.usesContext ).toEqual( [ 'postId' ] );
		expect( metadata.render ).toBe( 'file:./render.php' );
	} );
} );
