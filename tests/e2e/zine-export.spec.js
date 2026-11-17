/** E2E coverage for the printable zine export. */
const { test, expect } = require( '@playwright/test' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const RUN = Date.now().toString( 36 );
const created = { posts: [], pages: [] };

async function adminRequest( browser ) {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
	const nonce = await page.evaluate( () =>
		fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ).then(
			( response ) => response.text()
		)
	);
	return { context, page, nonce };
}

async function create( page, nonce, type, data ) {
	const response = await page.request.post(
		`${ BASE }/?rest_route=/wp/v2/${ type }`,
		{
			headers: { 'X-WP-Nonce': nonce },
			data,
		}
	);
	expect( response.ok() ).toBeTruthy();
	return response.json();
}

async function kindId( page, nonce, slug ) {
	const response = await page.request.get(
		`${ BASE }/?rest_route=/wp/v2/kind&slug=${ slug }`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);
	expect( response.ok() ).toBeTruthy();
	const terms = await response.json();
	return terms[ 0 ].id;
}

function countPdfPages( buffer ) {
	return (
		buffer.toString( 'latin1' ).match( /\/Type\s*\/Page(?![s\w])/g ) || []
	).length;
}

test.describe( 'printable zine export', () => {
	test.describe.configure( { mode: 'serial' } );
	let sourceUrl;

	test.beforeAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		const recipe = await kindId( page, nonce, 'recipe' );
		const follow = await kindId( page, nonce, 'follow' );
		for ( let index = 1; index <= 15; index++ ) {
			const post = await create( page, nonce, 'posts', {
				title: `Zine ${ RUN } ${ String( index ).padStart( 2, '0' ) }`,
				content: `<!-- wp:paragraph --><p>Entry ${ index }</p><!-- /wp:paragraph -->`,
				status: 'publish',
				date: `2025-03-${ String( index ).padStart( 2, '0' ) }T12:00:00`,
				kind: [ 2 === index ? follow : recipe ],
			} );
			created.posts.push( post.id );
		}
		const content =
			'<!-- wp:post-kinds-indieweb/zine-export {"source":"stream"} /-->\n\n<!-- wp:query {"queryId":1,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} --><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- /wp:post-template --><!-- /wp:query -->';
		const source = await create( page, nonce, 'pages', {
			title: `Zine source ${ RUN }`,
			slug: `zine-source-${ RUN }`,
			content,
			status: 'publish',
		} );
		created.pages.push( source.id );
		sourceUrl = source.link;
		await context.close();
	} );

	test.afterAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		for ( const [ type, ids ] of Object.entries( created ) ) {
			for ( const id of ids ) {
				await page.request.post(
					`${ BASE }/?rest_route=/wp/v2/${ type }/${ id }`,
					{
						headers: { 'X-WP-Nonce': nonce },
						data: { status: 'trash' },
					}
				);
			}
		}
		await context.close();
	} );

	test( 'native form works without JavaScript and keeps its tab order', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( sourceUrl );
		const details = page.locator( 'details.pkiw-zine-export' );
		await expect( details ).toHaveCount( 1 );
		await details.locator( 'summary' ).click();
		await expect( details.locator( 'input[type="radio"]' ) ).toHaveCount(
			2
		);
		await expect( details.locator( 'input[type="date"]' ) ).toHaveCount(
			2
		);
		await expect(
			details.getByRole( 'button', { name: 'Preview zine' } )
		).toHaveCount( 1 );
		await context.close();
	} );

	test( 'invalid dates stay on the source and identify From', async ( {
		page,
	} ) => {
		await page.goto(
			`${ sourceUrl }?pkiw_zine_pages=8&pkiw_zine_from=banana`
		);
		await expect( page.locator( 'body' ) ).not.toHaveClass(
			/pkiw-zine-print/
		);
		await expect( page.locator( '#pkiw-zine-from' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		await expect( page.locator( '#pkiw-zine-error-from' ) ).toHaveText(
			'Enter a valid From date, like 2026-03-09.'
		);
	} );

	test( '8 and 16 page views produce one and two sheet sides', async ( {
		page,
	} ) => {
		const control = await page.context().newPage();
		await control.setContent(
			'<style>section{break-after:page}</style><section>1</section><section>2</section><section>3</section>'
		);
		expect(
			countPdfPages( await control.pdf( { format: 'Letter' } ) )
		).toBe( 3 );
		await control.close();

		for ( const format of [ 'Letter', 'A4' ] ) {
			for ( const [ pages, expected ] of [
				[ 8, 1 ],
				[ 16, 2 ],
			] ) {
				await page.goto( `${ sourceUrl }?pkiw_zine_pages=${ pages }` );
				await expect( page.locator( 'body' ) ).toHaveClass(
					/pkiw-zine-print/
				);
				await expect(
					page.getByText( `Zine ${ RUN } 02` )
				).toHaveCount( 0 );
				await expect(
					page.locator( '.pkiw-zine-toolbar__count' )
				).toHaveText( 8 === pages ? '6 of 14 posts' : '14 posts' );
				expect(
					countPdfPages(
						await page.pdf( {
							format,
							landscape: true,
							preferCSSPageSize: true,
						} )
					)
				).toBe( expected );
			}
		}
	} );

	test( 'print rects match every Letter and A4 slot and top rows rotate', async ( {
		page,
	} ) => {
		for ( const sheet of [
			{ width: 1056, height: 816 },
			{ width: 1123, height: 794 },
		] ) {
			await page.setViewportSize( sheet );
			await page.goto( `${ sourceUrl }?pkiw_zine_pages=16` );
			await page.emulateMedia( { media: 'print' } );
			const mismatches = await page
				.locator( '[data-pkiw-zine-page]' )
				.evaluateAll(
					( nodes, dimensions ) =>
						nodes.filter( ( node ) => {
							const rect = node.getBoundingClientRect();
							const side = Number( node.dataset.side );
							const col = Number( node.dataset.col );
							const top = 'top' === node.dataset.row;
							const expected = {
								x: ( ( col - 1 ) * dimensions.width ) / 4,
								y:
									( ( ( side - 1 ) * 2 + ( top ? 0 : 1 ) ) *
										dimensions.height ) /
									2,
								width: dimensions.width / 4,
								height: dimensions.height / 2,
							};
							const rotated = node.ownerDocument.defaultView
								.getComputedStyle( node )
								.transform.replace( /\s/g, '' )
								.startsWith( 'matrix(-1,' );
							return (
								Math.abs( rect.x - expected.x ) > 1 ||
								Math.abs( rect.y - expected.y ) > 1 ||
								Math.abs( rect.width - expected.width ) > 1 ||
								Math.abs( rect.height - expected.height ) > 1 ||
								rotated !== top
							);
						} ).length,
					sheet
				);
			expect( mismatches ).toBe( 0 );
		}
	} );
} );
