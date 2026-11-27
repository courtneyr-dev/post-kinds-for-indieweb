import { fireEvent, render, screen } from '@testing-library/react';
import { useSelect } from '@wordpress/data';
import Edit from '../../../src/blocks/reply-card/edit';

jest.mock( '../../../src/components/standard-site-panel', () => () => null );

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children, group = 'default' } ) => (
		<section aria-label={ `${ group } controls` }>{ children }</section>
	),
	useBlockProps: ( props ) => props,
	RichText: ( {
		tagName: Tag,
		className,
		value,
		readOnly,
		placeholder,
		'aria-labelledby': labelledBy,
	} ) => (
		<Tag
			role="textbox"
			aria-label={ placeholder }
			aria-labelledby={ labelledBy }
			className={ className }
			contentEditable={ ! readOnly }
			suppressContentEditableWarning
		>
			{ value }
		</Tag>
	),
	MediaUpload: () => null,
	MediaUploadCheck: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/components', () => {
	const control =
		( Tag ) =>
		( { label, value, onChange } ) => {
			const id = `reply-${ label.toLowerCase().replace( /\W+/g, '-' ) }`;
			return (
				<>
					<label htmlFor={ id }>{ label }</label>
					<Tag
						id={ id }
						value={ value }
						onChange={ ( event ) => onChange( event.target.value ) }
					/>
				</>
			);
		};
	return {
		PanelBody: ( { children } ) => children,
		TextControl: control( 'input' ),
		TextareaControl: control( 'textarea' ),
	};
} );

const POST_META = {
	_pkiw_cite_url: 'https://post.example/target',
	_pkiw_cite_author: 'Post Author',
};

const LOOP_POSTS = {
	'post:41': {
		meta: {
			_pkiw_cite_url: 'https://loop.example/41',
			_pkiw_cite_author: 'Loop Author 41',
		},
	},
	'post:42': {
		meta: {
			_pkiw_cite_url: 'https://loop.example/42',
			_pkiw_cite_author: 'Loop Author 42',
		},
	},
};

const PATTERN_CARD = {
	title: 'Pattern title',
	url: 'https://pattern.example/source',
	author: 'Pattern Author',
	description: 'Pattern description',
	quotation: 'Pattern quotation',
	metadata: {
		name: 'Reply',
		bindings: { __default: { source: 'core/pattern-overrides' } },
	},
};

function renderCard( { attributes, context } ) {
	const setAttributes = jest.fn();
	render(
		<Edit
			attributes={ attributes }
			setAttributes={ setAttributes }
			context={ context }
			clientId="reply-card"
		/>
	);
	return setAttributes;
}

const QUOTATION = 'Quoted excerpt (optional)';

function field( name ) {
	return screen.getByRole( 'textbox', { name } );
}

function groupOf( name ) {
	return field( name ).closest( 'section' ).getAttribute( 'aria-label' );
}

beforeEach( () => {
	const editedPost = { kind: [ 7 ], meta: POST_META };
	const stores = {
		'core/editor': {
			getEditedPostAttribute: ( key ) => editedPost[ key ],
		},
		'core/block-editor': { getBlockAttributes: () => PATTERN_CARD },
		core: {
			getEditedEntityRecord: ( kind, name, id ) =>
				LOOP_POSTS[ `${ name }:${ id }` ],
		},
	};
	useSelect.mockImplementation( ( selector ) =>
		selector( ( store ) => stores[ store ] )
	);
} );

describe( 'reply card in a synced pattern instance', () => {
	it( 'offers quotation and description in the content controls, and clears the quotation', () => {
		const setAttributes = renderCard( {
			attributes: PATTERN_CARD,
			context: { 'pattern/overrides': {} },
		} );
		expect( groupOf( QUOTATION ) ).toBe( 'content controls' );
		expect( groupOf( 'Description' ) ).toBe( 'content controls' );

		fireEvent.change( field( QUOTATION ), { target: { value: '' } } );
		fireEvent.change( field( 'Description' ), {
			target: { value: 'Instance description' },
		} );

		expect( setAttributes ).toHaveBeenNthCalledWith( 1, { quotation: '' } );
		expect( setAttributes ).toHaveBeenNthCalledWith( 2, {
			description: 'Instance description',
		} );
	} );

	it( "shows the post's reply target and author, read-only", () => {
		renderCard( {
			attributes: PATTERN_CARD,
			context: { 'pattern/overrides': {} },
		} );

		const target = screen.getByPlaceholderText( 'https://example.com/…' );
		expect( target ).toHaveValue( 'https://post.example/target' );
		expect( target ).toHaveAttribute( 'readonly' );
		expect( screen.getByText( 'Post Author' ) ).toHaveAttribute(
			'contenteditable',
			'false'
		);
		expect( screen.queryByText( 'Pattern Author' ) ).toBeNull();
	} );
} );

describe.each( [
	[ 'a standalone card', { ...PATTERN_CARD, metadata: undefined }, {} ],
	[ 'the card in the pattern itself', PATTERN_CARD, {} ],
] )( 'reply card as %s', ( label, attributes, context ) => {
	it( 'keeps quotation and description in the default controls and its own target', () => {
		renderCard( { attributes, context } );

		expect( groupOf( QUOTATION ) ).toBe( 'default controls' );
		expect( groupOf( 'Description' ) ).toBe( 'default controls' );
		expect( field( QUOTATION ) ).toHaveValue( 'Pattern quotation' );

		const target = screen.getByPlaceholderText( 'https://example.com/…' );
		expect( target ).toHaveValue( 'https://pattern.example/source' );
		expect( target ).not.toHaveAttribute( 'readonly' );
		expect( screen.getByText( 'Pattern Author' ) ).toHaveAttribute(
			'contenteditable',
			'true'
		);
	} );
} );

describe( 'reply card with malformed bound text', () => {
	it( "falls back to the block's own text for a non-string override", () => {
		renderCard( {
			attributes: {
				...PATTERN_CARD,
				title: { text: 'Bound title' },
				description: 42,
				quotation: { text: 'Bound quotation' },
			},
			context: { 'pattern/overrides': {} },
		} );

		expect(
			screen.getByText( 'Pattern title', { selector: 'h3' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Pattern quotation', { selector: 'blockquote' } )
		).toBeInTheDocument();
		expect( field( 'Description' ) ).toHaveValue( 'Pattern description' );
	} );

	it( 'keeps an explicitly cleared quotation cleared', () => {
		renderCard( {
			attributes: { ...PATTERN_CARD, quotation: undefined },
			context: { 'pattern/overrides': {} },
		} );

		expect( screen.queryByText( 'Pattern quotation' ) ).toBeNull();
		expect( field( QUOTATION ) ).toHaveValue( '' );
		expect(
			screen.getByText( 'Pattern description', { selector: 'p' } )
		).toBeInTheDocument();
	} );
} );

describe( 'reply card in a synced pattern inside a Query Loop', () => {
	it( "shows each loop post's reply target and author", () => {
		render(
			<>
				{ [ 41, 42 ].map( ( postId ) => (
					<Edit
						key={ postId }
						attributes={ PATTERN_CARD }
						setAttributes={ jest.fn() }
						clientId={ `reply-card-${ postId }` }
						context={ {
							'pattern/overrides': {},
							postId,
							postType: 'post',
						} }
					/>
				) ) }
			</>
		);

		expect(
			screen
				.getAllByPlaceholderText( 'https://example.com/…' )
				.map( ( input ) => input.value )
		).toEqual( [ 'https://loop.example/41', 'https://loop.example/42' ] );
		expect( screen.getByText( 'Loop Author 41' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Loop Author 42' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Post Author' ) ).toBeNull();
	} );
} );

describe.each( [
	[
		'a standalone card',
		{ ...PATTERN_CARD, metadata: undefined },
		{},
		[ 'https://pattern.example/source', 'Pattern Author' ],
	],
	[
		'a synced pattern instance',
		PATTERN_CARD,
		{ 'pattern/overrides': {} },
		[ 'https://post.example/target', 'Post Author' ],
	],
] )( 'reply card fields in %s', ( label, attributes, context, values ) => {
	it( 'names the target URL and author by their visible labels', () => {
		renderCard( { attributes, context } );

		expect( screen.getByText( 'Reply target URL' ) ).toBeVisible();
		expect( screen.getByText( 'Original author' ) ).toBeVisible();
		expect(
			screen.getByRole( 'textbox', { name: 'Reply target URL' } )
		).toHaveValue( values[ 0 ] );
		expect(
			screen.getByRole( 'textbox', { name: 'Original author' } )
		).toHaveTextContent( values[ 1 ] );
	} );
} );
