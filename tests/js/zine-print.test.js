/** Printable zine module behavior. */

afterEach( () => {
	document.body.innerHTML = '';
	delete window.print;
} );

it( 'creates the Print button and calls window.print', () => {
	document.body.innerHTML = '<div data-pkiw-zine-print-button></div>';
	window.print = jest.fn();
	jest.isolateModules( () => {
		require( '../../assets/js/zine-print.js' );
	} );

	const button = document.querySelector( 'button' );
	expect( button.textContent ).toBe( 'Print' );
	button.click();
	expect( window.print ).toHaveBeenCalledTimes( 1 );
} );

it( 'hides a failed image without hiding its figcaption', () => {
	document.body.innerHTML =
		'<section class="pkiw-zine-page"><figure><img src="missing.jpg" alt="Fictional cover"><figcaption>Fictional caption</figcaption></figure></section>';
	jest.isolateModules( () => {
		require( '../../assets/js/zine-print.js' );
	} );

	const image = document.querySelector( 'img' );
	image.dispatchEvent( new Event( 'error' ) );
	expect( image.hidden ).toBe( true );
	expect( document.querySelector( 'figcaption' ).hidden ).toBe( false );
} );
