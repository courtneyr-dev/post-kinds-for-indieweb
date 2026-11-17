/**
 * E2E: Check-in Dashboard Interactivity API store.
 */

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const RUN = Date.now().toString( 36 );
const TILE = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
	'base64'
);

let createdPosts = [];
let createdPages = [];
let dashboardUrl = '';

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

async function kindId( page, nonce, slug ) {
	const response = await page.request.get(
		`${ BASE }/?rest_route=/wp/v2/kind&slug=${ slug }`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);
	expect( response.ok() ).toBeTruthy();
	const terms = await response.json();
	expect( terms ).toHaveLength( 1 );
	return terms[ 0 ].id;
}

function checkinBlock( venue, address, latitude, longitude ) {
	return `<!-- wp:post-kinds-indieweb/checkin-card {"venueName":"${ venue }","address":"${ address }","latitude":${ latitude },"longitude":${ longitude },"locationPrivacy":"public"} /-->`;
}

async function createCheckin( page, nonce, kinds, number, options = {} ) {
	const venue = options.venue || `N5K4 Venue ${ number } ${ RUN }`;
	const address = options.address || `${ number } N5K4 Way ${ RUN }`;
	const latitude = options.latitude || `40.0${ number }`;
	const longitude = options.longitude || `-75.0${ number }`;
	const response = await page.request.post(
		`${ BASE }/?rest_route=/wp/v2/posts`,
		{
			headers: { 'X-WP-Nonce': nonce },
			data: {
				title: options.title || `N5K4 check-in ${ number } ${ RUN }`,
				content: checkinBlock( venue, address, latitude, longitude ),
				status: 'publish',
				password: options.password || '',
				kind: kinds,
				meta: {
					_pkiw_checkin_name: venue,
					_pkiw_checkin_address: address,
					_pkiw_geo_latitude: latitude,
					_pkiw_geo_longitude: longitude,
					_pkiw_geo_privacy: 'public',
				},
			},
		}
	);
	expect( response.ok() ).toBeTruthy();
	const post = await response.json();
	createdPosts.push( post.id );
	return post;
}

async function createDashboardPage( page, nonce ) {
	const content =
		'<!-- wp:post-kinds-indieweb/checkin-dashboard {"showFilters":true} /-->' +
		'<!-- wp:post-kinds-indieweb/checkin-dashboard {"layout":"map","showFilters":true} /-->' +
		'<!-- wp:post-kinds-indieweb/checkin-dashboard {"layout":"timeline","showMap":false,"showFilters":true} /-->';
	const response = await page.request.post(
		`${ BASE }/?rest_route=/wp/v2/pages`,
		{
			headers: { 'X-WP-Nonce': nonce },
			data: {
				title: `N5K4 dashboard ${ RUN }`,
				content,
				status: 'publish',
			},
		}
	);
	expect( response.ok() ).toBeTruthy();
	const dashboard = await response.json();
	createdPages.push( dashboard.id );
	return dashboard;
}

async function stubTiles( page ) {
	await page.route( '**/tile.openstreetmap.org/**', ( route ) =>
		route.fulfill( {
			status: 200,
			contentType: 'image/png',
			body: TILE,
		} )
	);
}

async function trash( page, nonce, type, id ) {
	const response = await page.request.delete(
		`${ BASE }/?rest_route=/wp/v2/${ type }/${ id }`,
		{ headers: { 'X-WP-Nonce': nonce } }
	);
	expect( response.ok() ).toBeTruthy();
}

test.describe( 'Check-in Dashboard interactivity', () => {
	test.describe.configure( { mode: 'serial' } );

	test.beforeAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		const checkin = await kindId( page, nonce, 'checkin' );
		const follow = await kindId( page, nonce, 'follow' );

		for ( let number = 1; number <= 3; number++ ) {
			await createCheckin( page, nonce, [ checkin ], number );
		}
		await createCheckin( page, nonce, [ checkin ], 'a', {
			title: `N5K4 sentinel a ${ RUN }`,
			venue: 'Sentinel Hidden Venue Qx7',
			address: '9 Sentinel Way Qx7',
			latitude: '12.987654',
			longitude: '-45.123456',
			password: 'n5k4-a',
		} );
		await createCheckin( page, nonce, [ checkin, follow ], 'b', {
			title: `N5K4 sentinel b ${ RUN }`,
			venue: 'Sentinel Hidden Venue Qx7',
			address: '9 Sentinel Way Qx7',
			latitude: '12.987654',
			longitude: '-45.123456',
			password: 'n5k4-b',
		} );
		const dashboard = await createDashboardPage( page, nonce );
		dashboardUrl = dashboard.link;
		await context.close();
	} );

	test.afterAll( async ( { browser } ) => {
		const { context, page, nonce } = await adminRequest( browser );
		for ( const id of createdPages ) {
			await trash( page, nonce, 'pages', id );
		}
		for ( const id of createdPosts ) {
			await trash( page, nonce, 'posts', id );
		}
		createdPages = [];
		createdPosts = [];
		await context.close();
	} );

	test( 'switches views by keyboard and keeps focus on the pressed button', async ( {
		page,
	} ) => {
		await stubTiles( page );
		await page.goto( dashboardUrl );
		const dashboard = page
			.locator( '.checkin-dashboard-frontend' )
			.nth( 0 );
		const grid = dashboard.getByRole( 'button', { name: 'Grid' } );
		const timeline = dashboard.getByRole( 'button', { name: 'Timeline' } );

		await grid.focus();
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Tab' );
		await expect( timeline ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( timeline ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect(
			dashboard.locator( '.checkin-view-timeline' )
		).not.toHaveAttribute( 'hidden', '' );
		await expect(
			dashboard.locator( '.checkin-view-grid' )
		).toHaveAttribute( 'hidden', '' );
		await expect(
			dashboard.locator( '.checkin-view-map' )
		).toHaveAttribute( 'hidden', '' );
		await expect( timeline ).toBeFocused();

		await page.keyboard.press( 'Shift+Tab' );
		await page.keyboard.press( 'Shift+Tab' );
		await expect( grid ).toBeFocused();
		await page.keyboard.press( 'Space' );
		await expect( grid ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect(
			dashboard.locator( '.checkin-view-grid' )
		).not.toHaveAttribute( 'hidden', '' );
		await expect(
			dashboard.locator( '.checkin-view-map' )
		).toHaveAttribute( 'hidden', '' );
		await expect(
			dashboard.locator( '.checkin-view-timeline' )
		).toHaveAttribute( 'hidden', '' );
		await expect( grid ).toBeFocused();
	} );

	test( 'draws each dashboard map once and keeps instances independent', async ( {
		page,
	} ) => {
		const errors = [];
		page.on( 'console', ( message ) => {
			if ( 'error' === message.type() ) {
				errors.push( message.text() );
			}
		} );
		await stubTiles( page );
		await page.goto( dashboardUrl );
		const dashboards = page.locator( '.checkin-dashboard-frontend' );
		const first = dashboards.nth( 0 );
		const second = dashboards.nth( 1 );
		const firstMap = first.locator( '.checkin-map' );
		const secondMap = second.locator( '.checkin-map' );

		await expect( secondMap ).toHaveClass( /leaflet-container/ );
		await first.getByRole( 'button', { name: 'Map' } ).click();
		await expect( firstMap ).toHaveClass( /leaflet-container/ );
		await expect(
			firstMap.locator( '.leaflet-marker-icon' ).first()
		).toBeVisible();
		const size = await firstMap.evaluate( ( element ) => ( {
			width: element.clientWidth,
			height: element.clientHeight,
		} ) );
		expect( size.width ).toBeGreaterThan( 0 );
		expect( size.height ).toBeGreaterThan( 0 );
		expect( await firstMap.getAttribute( 'id' ) ).not.toBe(
			await secondMap.getAttribute( 'id' )
		);

		await first.getByRole( 'button', { name: 'Grid' } ).click();
		await first.getByRole( 'button', { name: 'Map' } ).click();
		await expect(
			first.locator( '.checkin-map.leaflet-container' )
		).toHaveCount( 1 );
		await expect(
			second.locator( '.checkin-map.leaflet-container' )
		).toHaveCount( 1 );

		const pageHtml = await page.content();
		expect( pageHtml ).not.toContain( 'Sentinel Hidden Venue Qx7' );
		expect( pageHtml ).not.toContain( '9 Sentinel Way Qx7' );
		expect( pageHtml ).not.toContain( '12.987654' );
		expect( pageHtml ).not.toContain( '-45.123456' );
		expect( errors ).toEqual( [] );
	} );

	test( 'omits the map control and panel when showMap is false', async ( {
		page,
	} ) => {
		await stubTiles( page );
		await page.goto( dashboardUrl );
		const dashboard = page
			.locator( '.checkin-dashboard-frontend' )
			.nth( 2 );

		await expect(
			dashboard.getByRole( 'button', { name: 'Grid' } )
		).toBeVisible();
		await expect(
			dashboard.getByRole( 'button', { name: 'Timeline' } )
		).toBeVisible();
		await expect(
			dashboard.locator( 'button[data-view="map"]' )
		).toHaveCount( 0 );
		await expect( dashboard.locator( '.checkin-view-map' ) ).toHaveCount(
			0
		);
	} );

	test( 'has no dashboard axe violations before and after drawing a map', async ( {
		page,
	} ) => {
		await stubTiles( page );
		await page.goto( dashboardUrl );
		const before = await new AxeBuilder( { page } )
			.include( '.checkin-dashboard-frontend' )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] )
			.analyze();
		expect( before.violations ).toEqual( [] );

		await page
			.locator( '.checkin-dashboard-frontend' )
			.nth( 0 )
			.getByRole( 'button', { name: 'Map' } )
			.click();
		await expect(
			page
				.locator( '.checkin-dashboard-frontend' )
				.nth( 0 )
				.locator( '.checkin-map' )
		).toHaveClass( /leaflet-container/ );
		const after = await new AxeBuilder( { page } )
			.include( '.checkin-dashboard-frontend' )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] )
			.analyze();
		expect( after.violations ).toEqual( [] );
	} );

	test( 'keeps the server lists and accepted map gap without JavaScript', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		await page.goto( dashboardUrl );
		const dashboards = page.locator( '.checkin-dashboard-frontend' );
		const first = dashboards.nth( 0 );
		const second = dashboards.nth( 1 );
		const third = dashboards.nth( 2 );

		await expect(
			first.locator( '.checkin-view-grid a' ).first()
		).toBeVisible();
		let reachedGridLink = false;
		for ( let index = 0; index < 100; index++ ) {
			await page.keyboard.press( 'Tab' );
			reachedGridLink = await page
				.locator( ':focus' )
				.evaluate(
					( element ) =>
						element.closest( '.checkin-view-grid' ) !== null
				);
			if ( reachedGridLink ) {
				break;
			}
		}
		expect( reachedGridLink ).toBeTruthy();

		await expect(
			third.locator( '.timeline-month' ).first()
		).toBeVisible();
		await expect(
			third.locator( '.timeline-venue' ).first()
		).toBeVisible();
		await expect(
			second.locator( '.checkin-dashboard-stats' )
		).toBeVisible();
		await expect( second.locator( '.checkin-map' ) ).toHaveAttribute(
			'role',
			'region'
		);
		await expect( second.locator( '.checkin-map' ) ).toHaveAttribute(
			'aria-label',
			'Check-in map'
		);
		await expect( second.locator( '.checkin-view-grid' ) ).toHaveAttribute(
			'hidden',
			''
		);
		await expect(
			second.locator( '.checkin-view-timeline' )
		).toHaveAttribute( 'hidden', '' );

		await context.close();
	} );
} );
