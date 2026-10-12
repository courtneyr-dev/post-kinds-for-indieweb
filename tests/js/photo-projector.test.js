/**
 * Photo projector enhancement tests for issue 241.
 */

const SCRIPT = '../../assets/js/photo-projector.js';

function setI18n() {
	window.wp = {
		i18n: {
			__: ( s ) => s,
			sprintf: require( '@wordpress/i18n' ).sprintf,
		},
	};
}

function loadScript() {
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
}

function printProjector( mounts = 3 ) {
	const figures = Array.from( { length: mounts }, ( _, index ) => {
		const number = index + 1;
		const thumb =
			1 === number
				? ' data-pkiw-thumb-src="https://example.org/medium-one.jpg" data-pkiw-thumb-srcset="https://example.org/medium-one.jpg 300w"'
				: '';
		const caption =
			2 === number
				? ''
				: `<figcaption>Caption <a href="https://example.org/${ number }">link ${ number }</a></figcaption>`;
		return `<figure class="pkiw-projector__mount"${ thumb }><img src="https://example.org/full-${ number }.jpg" alt="Image ${ number }">${ caption }</figure>`;
	} ).join( '' );
	document.body.innerHTML = `<div class="pkiw-projector">${ figures }</div>`;
}

function projector() {
	return document.querySelector( '.pkiw-projector' );
}

function buttons() {
	return {
		prev: document.querySelector( '.pkiw-projector__button--prev' ),
		next: document.querySelector( '.pkiw-projector__button--next' ),
		rail: Array.from(
			document.querySelectorAll( '.pkiw-projector__thumb' )
		),
	};
}

beforeEach( () => {
	jest.useFakeTimers();
	document.body.innerHTML = '';
	setI18n();
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.wp;
	jest.useRealTimers();
} );

describe( 'photo projector', () => {
	it( 'builds controls from server-shaped projector markup', () => {
		printProjector( 3 );
		loadScript();

		const root = projector();
		const { prev, next } = buttons();

		expect( root ).toHaveClass( 'pkiw-projector--enhanced' );
		expect(
			Array.from( root.querySelectorAll( '.pkiw-projector__mount' ) ).map(
				( mount ) => mount.hasAttribute( 'hidden' )
			)
		).toEqual( [ false, true, true ] );
		expect(
			root.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '1 of 3' );
		expect( prev ).toBeInstanceOf( window.HTMLButtonElement );
		expect( next ).toBeInstanceOf( window.HTMLButtonElement );
		expect( prev ).toHaveAttribute( 'type', 'button' );
		expect( next ).toHaveAttribute( 'type', 'button' );
		expect( prev ).toHaveTextContent( 'Previous image' );
		expect( next ).toHaveTextContent( 'Next image' );
		expect(
			root.querySelector( '.pkiw-projector__status' )
		).toHaveAttribute( 'aria-live', 'polite' );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'copies current caption HTML into the live region', () => {
		printProjector( 3 );
		loadScript();
		const { next } = buttons();

		expect(
			document.querySelector( '.pkiw-projector__caption' ).innerHTML
		).toContain( '<a href="https://example.org/1">link 1</a>' );

		next.click();
		expect(
			document.querySelector( '.pkiw-projector__caption' ).innerHTML
		).toBe( '' );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'uses aria-disabled without wrapping or disabling buttons', () => {
		printProjector( 3 );
		loadScript();
		const { prev, next } = buttons();

		expect( prev ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( prev ).not.toHaveAttribute( 'disabled' );
		prev.click();
		expect(
			document.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '1 of 3' );

		next.click();
		next.click();
		expect( next ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( next ).not.toHaveAttribute( 'disabled' );
		next.click();
		expect(
			document.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '3 of 3' );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'keeps focus on the pressed button', () => {
		printProjector( 3 );
		loadScript();
		const { next } = buttons();

		next.focus();
		next.click();

		expect( document.activeElement ).toBe( next );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'handles arrow keys only through the projector listener', () => {
		const documentSpy = jest.spyOn( document, 'addEventListener' );
		const windowSpy = jest.spyOn( window, 'addEventListener' );
		printProjector( 3 );
		loadScript();
		const { rail } = buttons();

		rail[ 0 ].dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'ArrowRight',
				bubbles: true,
			} )
		);
		expect(
			document.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '2 of 3' );

		document.body.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'ArrowRight',
				bubbles: true,
			} )
		);
		expect(
			document.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '2 of 3' );

		rail[ 1 ].dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'ArrowRight',
				bubbles: true,
				shiftKey: true,
			} )
		);
		expect(
			document.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '2 of 3' );

		rail[ 1 ].dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'ArrowLeft',
				bubbles: true,
			} )
		);
		expect(
			document.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '1 of 3' );
		expect(
			documentSpy.mock.calls.some( ( call ) => 'keydown' === call[ 0 ] )
		).toBe( false );
		expect(
			windowSpy.mock.calls.some( ( call ) => 'keydown' === call[ 0 ] )
		).toBe( false );
		documentSpy.mockRestore();
		windowSpy.mockRestore();
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'builds a thumbnail rail with current markers', () => {
		printProjector( 3 );
		loadScript();
		const { rail } = buttons();

		expect( rail ).toHaveLength( 3 );
		rail.forEach( ( button, index ) => {
			const number = index + 1;
			const image = button.querySelector( 'img' );
			expect( button ).toHaveAttribute(
				'aria-label',
				`Show image ${ number } of 3`
			);
			expect( image ).toHaveAttribute( 'alt', '' );
			expect( image ).toHaveAttribute( 'sizes', '44px' );
			expect(
				button.querySelector( '.pkiw-projector__ordinal' )
			).toHaveTextContent( String( number ) );
		} );
		expect( rail[ 0 ].querySelector( 'img' ) ).toHaveAttribute(
			'src',
			'https://example.org/medium-one.jpg'
		);
		expect( rail[ 0 ].querySelector( 'img' ) ).toHaveAttribute(
			'srcset',
			'https://example.org/medium-one.jpg 300w'
		);
		expect( rail[ 1 ].querySelector( 'img' ) ).toHaveAttribute(
			'src',
			'https://example.org/full-2.jpg'
		);
		expect( rail[ 0 ] ).toHaveAttribute( 'aria-current', 'true' );
		expect(
			rail[ 0 ].querySelector( '.pkiw-projector__current' )
		).not.toHaveAttribute( 'hidden' );
		expect( rail[ 1 ] ).not.toHaveAttribute( 'aria-current' );
		expect(
			rail[ 1 ].querySelector( '.pkiw-projector__current' )
		).toHaveAttribute( 'hidden' );

		rail[ 2 ].click();
		expect( rail[ 2 ] ).toHaveAttribute( 'aria-current', 'true' );
		expect(
			rail[ 2 ].querySelector( '.pkiw-projector__current' )
		).not.toHaveAttribute( 'hidden' );
		expect(
			document.querySelector( '.pkiw-projector__counter' )
		).toHaveTextContent( '3 of 3' );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'skips single mount projectors and does not duplicate controls', () => {
		printProjector( 1 );
		loadScript();
		expect( projector() ).not.toHaveClass( 'pkiw-projector--enhanced' );
		expect(
			document.querySelector( '.pkiw-projector__controls' )
		).toBeNull();

		printProjector( 3 );
		loadScript();
		loadScript();
		expect(
			document.querySelectorAll( '.pkiw-projector__controls' )
		).toHaveLength( 1 );
		expect(
			document.querySelectorAll( '.pkiw-projector__rail' )
		).toHaveLength( 1 );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'uses no timers during init or interaction', () => {
		printProjector( 3 );
		loadScript();
		expect( jest.getTimerCount() ).toBe( 0 );

		const { next, prev, rail } = buttons();
		next.click();
		prev.click();
		rail[ 2 ].click();

		expect( jest.getTimerCount() ).toBe( 0 );
	} );
} );
