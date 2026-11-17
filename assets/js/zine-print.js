const target = document.querySelector( '[data-pkiw-zine-print-button]' );

if ( target ) {
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.textContent = 'Print';
	button.addEventListener( 'click', () => window.print() );
	target.append( button );
}

for ( const image of document.querySelectorAll( '.pkiw-zine-page img' ) ) {
	image.addEventListener( 'error', () => {
		image.hidden = true;
	} );
}
