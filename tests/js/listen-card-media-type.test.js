/**
 * Listen card media type and podcast lookup (issue 236).
 *
 * mediaType is an optional attribute with no default, so a card nobody
 * touched never stores a type. Picking Podcast relabels Track as Episode and
 * Album as Show, and points the search box at the podcast lookup. A podcast
 * result is a feed (show) record, so it fills Show, artist, cover and URL and
 * leaves the episode title to the author.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	createBlock,
	getCategories,
	parse,
	serialize,
	setCategories,
} from '@wordpress/blocks';
import Edit from '../../src/blocks/listen-card/edit';
import metadata from '../../src/blocks/listen-card/block.json';
import podcastFixture from '../phpunit/fixtures/podcastindex/search-byterm.json';

// Real registry for block registration and parsing; only the hooks are
// mocked. A Proxy, not a spread, because spreading the real module reads its
// lazy getters before the package has finished loading.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );
	const overrides = {
		useSelect: jest.fn( () => ( {} ) ),
		useDispatch: jest.fn( () => ( {} ) ),
	};
	return new Proxy( actual, {
		get: ( target, key ) =>
			key in overrides ? overrides[ key ] : target[ key ],
	} );
} );

jest.mock( '@wordpress/block-editor', () => {
	const useBlockProps = () => ( {} );
	useBlockProps.save = ( props = {} ) => props;
	return {
		useBlockProps,
		InspectorControls: ( { children } ) => <div>{ children }</div>,
		RichText: () => null,
		MediaUpload: () => null,
		MediaUploadCheck: ( { children } ) => children,
	};
} );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { title, children } ) => (
		<section aria-label={ title }>{ children }</section>
	),
	TextControl: ( { label, value, onChange } ) => (
		<input
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	),
	SelectControl: ( { label, value, options, onChange } ) => (
		<select
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	),
	Button: ( { children, onClick } ) => (
		<button type="button" onClick={ onClick }>
			{ children }
		</button>
	),
	DateTimePicker: () => null,
	Popover: ( { children } ) => children,
	SandBox: () => null,
	Disabled: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/core-data', () => ( { store: 'core' } ) );

let mockPickedItem = null;

jest.mock( '../../src/blocks/shared/components', () => ( {
	StarRating: () => null,
	CoverImage: () => null,
	BlockPlaceholder: ( { label, children } ) => (
		<div aria-label={ label }>{ children }</div>
	),
	MediaSearch: ( { type, onSelect } ) => (
		<button
			type="button"
			data-testid="media-search"
			data-type={ type }
			onClick={ () => onSelect( mockPickedItem ) }
		>
			pick
		</button>
	),
} ) );

setCategories( [
	{
		slug: 'post-kinds-indieweb',
		title: 'Post Kinds for IndieWeb in Block Themes',
	},
	...getCategories(),
] );

require( '../../src/blocks/listen-card' );

const NAME = 'post-kinds-indieweb/listen-card';
const SHOW = podcastFixture.feeds[ 0 ];
const setAttributes = jest.fn();

beforeEach( () => {
	mockPickedItem = null;
	setAttributes.mockReset();
	useDispatch.mockImplementation( () => ( {} ) );
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( { getEmbedPreview: () => null } ) )
	);
} );

const MUSIC_CARD = { trackTitle: 'Bohemian Rhapsody', artistName: 'Queen' };

describe( 'listen-card mediaType attribute', () => {
	it( 'is an optional music or podcast string with no default', () => {
		expect( metadata.attributes.mediaType ).toEqual( {
			type: 'string',
			enum: [ 'music', 'podcast' ],
		} );
	} );

	it( 'leaves a stored card without it valid, and serializes without it', () => {
		const [ block ] = parse(
			`<!-- wp:${ NAME } {"trackTitle":"Song","artistName":"Band"} /-->`
		);

		expect( block.isValid ).toBe( true );
		expect( block.attributes.mediaType ).toBeUndefined();
		expect(
			serialize( createBlock( NAME, { trackTitle: 'Song' } ) )
		).not.toContain( 'mediaType' );
	} );

	it( 'keeps podcast and music, and drops a value outside the enum', () => {
		const [ podcast ] = parse(
			`<!-- wp:${ NAME } {"trackTitle":"Ep","mediaType":"podcast"} /-->`
		);
		const [ music ] = parse(
			`<!-- wp:${ NAME } {"trackTitle":"Song","mediaType":"music"} /-->`
		);
		const [ other ] = parse(
			`<!-- wp:${ NAME } {"trackTitle":"Song","mediaType":"vinyl"} /-->`
		);

		expect( podcast.isValid ).toBe( true );
		expect( podcast.attributes.mediaType ).toBe( 'podcast' );
		expect( music.attributes.mediaType ).toBe( 'music' );
		expect( other.attributes.mediaType ).toBeUndefined();
	} );
} );

describe( 'listen-card media type select', () => {
	it( 'offers Music and Podcast and defaults to Music for an untouched card', () => {
		render(
			<Edit attributes={ MUSIC_CARD } setAttributes={ setAttributes } />
		);

		const select = screen.getByLabelText( 'Media type' );
		expect( select.value ).toBe( 'music' );
		expect(
			Array.from( select.options ).map( ( option ) => [
				option.value,
				option.textContent,
			] )
		).toEqual( [
			[ 'music', 'Music' ],
			[ 'podcast', 'Podcast' ],
		] );
		expect( setAttributes ).not.toHaveBeenCalled();
	} );

	it( 'writes mediaType only when the author picks one', () => {
		render(
			<Edit attributes={ MUSIC_CARD } setAttributes={ setAttributes } />
		);

		fireEvent.change( screen.getByLabelText( 'Media type' ), {
			target: { value: 'podcast' },
		} );

		expect( setAttributes ).toHaveBeenCalledTimes( 1 );
		expect( setAttributes ).toHaveBeenCalledWith( {
			mediaType: 'podcast',
		} );
	} );

	it( 'labels the fields Track and Album and searches music for a music card', () => {
		render(
			<Edit attributes={ MUSIC_CARD } setAttributes={ setAttributes } />
		);

		expect( screen.getByLabelText( 'Track Title' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'Album' ) ).toBeTruthy();
		expect( screen.queryByLabelText( 'Episode' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Show' ) ).toBeNull();
		expect(
			screen.getByTestId( 'media-search' ).getAttribute( 'data-type' )
		).toBe( 'music' );
	} );

	it( 'labels the fields Episode and Show and searches podcasts for a podcast card', () => {
		render(
			<Edit
				attributes={ { ...MUSIC_CARD, mediaType: 'podcast' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByLabelText( 'Episode' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'Show' ) ).toBeTruthy();
		expect( screen.queryByLabelText( 'Track Title' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Album' ) ).toBeNull();
		expect(
			screen.getByTestId( 'media-search' ).getAttribute( 'data-type' )
		).toBe( 'podcast' );
	} );

	it( 'offers the select and the podcast search on an empty card', () => {
		render(
			<Edit
				attributes={ { mediaType: 'podcast' } }
				setAttributes={ setAttributes }
			/>
		);

		expect( screen.getByLabelText( 'Media type' ).value ).toBe( 'podcast' );

		fireEvent.click( screen.getByText( 'Search Podcasts' ) );

		expect(
			screen.getByTestId( 'media-search' ).getAttribute( 'data-type' )
		).toBe( 'podcast' );
	} );
} );

describe( 'listen-card podcast lookup', () => {
	const podcastCard = {
		mediaType: 'podcast',
		trackTitle: 'Episode 12',
		artistName: '',
		albumTitle: '',
		coverImage: '',
		coverImageAlt: '',
		listenUrl: '',
	};

	it( 'fills Show, artist, cover and URL from a PodcastIndex feed', () => {
		mockPickedItem = SHOW;
		render(
			<Edit attributes={ podcastCard } setAttributes={ setAttributes } />
		);

		fireEvent.click( screen.getByTestId( 'media-search' ) );

		expect( setAttributes ).toHaveBeenCalledTimes( 1 );
		expect( setAttributes ).toHaveBeenCalledWith( {
			mediaType: 'podcast',
			albumTitle: 'The Joe Rogan Experience',
			artistName: 'Joe Rogan',
			coverImage: 'https://images.podcastindex.org/75075.jpg',
			coverImageAlt: 'Cover art for The Joe Rogan Experience',
			listenUrl: 'https://www.joerogan.com',
		} );
	} );

	it( 'leaves the episode title for the author', () => {
		mockPickedItem = SHOW;
		render(
			<Edit attributes={ podcastCard } setAttributes={ setAttributes } />
		);

		fireEvent.click( screen.getByTestId( 'media-search' ) );

		expect( setAttributes.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty(
			'trackTitle'
		);
	} );

	it( 'keeps a URL and cover alt text the author already wrote', () => {
		mockPickedItem = SHOW;
		render(
			<Edit
				attributes={ {
					...podcastCard,
					listenUrl: 'https://example.test/episodes/12',
					coverImageAlt: 'Two microphones on a desk',
				} }
				setAttributes={ setAttributes }
			/>
		);

		fireEvent.click( screen.getByTestId( 'media-search' ) );

		const written = setAttributes.mock.calls[ 0 ][ 0 ];
		expect( written ).not.toHaveProperty( 'listenUrl' );
		expect( written ).not.toHaveProperty( 'coverImageAlt' );
		expect( written.albumTitle ).toBe( 'The Joe Rogan Experience' );
	} );

	it( 'falls back to the artwork field when a feed has no image', () => {
		mockPickedItem = {
			title: 'Small Show',
			author: 'A Host',
			artwork: 'https://images.podcastindex.org/1-artwork.jpg',
		};
		render(
			<Edit attributes={ podcastCard } setAttributes={ setAttributes } />
		);

		fireEvent.click( screen.getByTestId( 'media-search' ) );

		const written = setAttributes.mock.calls[ 0 ][ 0 ];
		expect( written.coverImage ).toBe(
			'https://images.podcastindex.org/1-artwork.jpg'
		);
		// No link in the feed record: the URL field stays as it was.
		expect( written ).not.toHaveProperty( 'listenUrl' );
	} );

	it( 'does not invent text for a feed with no author or image', () => {
		mockPickedItem = { title: 'Bare Show' };
		render(
			<Edit attributes={ podcastCard } setAttributes={ setAttributes } />
		);

		fireEvent.click( screen.getByTestId( 'media-search' ) );

		const written = setAttributes.mock.calls[ 0 ][ 0 ];
		expect( written.albumTitle ).toBe( 'Bare Show' );
		expect( written.artistName ).toBe( '' );
		expect( written.coverImage ).toBe( '' );
		expect( written ).not.toHaveProperty( 'coverImageAlt' );
	} );

	it( 'still fills a music card from a music result', () => {
		mockPickedItem = {
			track: 'Song',
			artist: 'Band',
			album: 'LP',
			cover: 'https://example.test/c.jpg',
			mbid: 'abc',
		};
		render(
			<Edit attributes={ MUSIC_CARD } setAttributes={ setAttributes } />
		);

		fireEvent.click( screen.getByTestId( 'media-search' ) );

		expect( setAttributes ).toHaveBeenCalledWith(
			expect.objectContaining( {
				trackTitle: 'Song',
				artistName: 'Band',
				albumTitle: 'LP',
				musicbrainzId: 'abc',
			} )
		);
		expect( setAttributes.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty(
			'mediaType'
		);
	} );
} );
