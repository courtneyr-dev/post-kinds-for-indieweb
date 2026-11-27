/**
 * E2E: two instances of a synced Reply pattern each keep their own
 * quotation and description, and the editor shows the post's reply target
 * and author, as the front end does, also for each post in a Query Loop.
 */

const { test, expect } = require( '@playwright/test' );

const USER = process.env.WP_USERNAME || 'admin';
const PASS = process.env.WP_PASSWORD || 'password';
const TARGET = 'https://post.example/target';
const AUTHOR = 'Post Author';
const CARD = `<!-- wp:post-kinds-indieweb/reply-card ${ JSON.stringify( {
	title: 'Pattern title',
	url: 'https://pattern.example/source',
	author: 'Pattern Author',
	description: 'Pattern description',
	quotation: 'Pattern quotation',
	metadata: {
		name: 'Reply',
		bindings: { __default: { source: 'core/pattern-overrides' } },
	},
} ) } /-->`;

async function logIn( page ) {
	await page.goto( '/wp-login.php' );
	await expect( page.locator( '#user_login' ) ).toBeFocused();
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );

	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.apiFetch );
}

async function openEditor( page, postId ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false )
	);
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

test( 'synced Reply pattern instances override text and show the post target', async ( {
	page,
} ) => {
	await logIn( page );
	const { postId, patternId, link } = await page.evaluate(
		async ( { target, author, card } ) => {
			const pattern = await window.wp.apiFetch( {
				path: '/wp/v2/blocks',
				method: 'POST',
				data: {
					title: 'Reply pattern',
					status: 'publish',
					content: card,
				},
			} );
			const [ reply ] = await window.wp.apiFetch( {
				path: '/wp/v2/kind?slug=reply',
			} );
			const instance = `<!-- wp:block {"ref":${ pattern.id }} /-->`;
			const post = await window.wp.apiFetch( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: 'Two Reply instances',
					status: 'publish',
					content: `${ instance }\n\n${ instance }`,
					kind: [ reply.id ],
					meta: { _pkiw_cite_url: target, _pkiw_cite_author: author },
				},
			} );
			return { postId: post.id, patternId: pattern.id, link: post.link };
		},
		{ target: TARGET, author: AUTHOR, card: CARD }
	);

	const canvas = await openEditor( page, postId );
	const cards = canvas.locator( '.wp-block-post-kinds-indieweb-reply-card' );
	await expect( cards ).toHaveCount( 2, { timeout: 30000 } );

	const expectPostTarget = async () => {
		for ( const index of [ 0, 1 ] ) {
			await expect
				.soft(
					cards.nth( index ).locator( '.post-kinds-card__input--url' )
				)
				.toHaveValue( TARGET );
			await expect
				.soft(
					cards.nth( index ).locator( '.post-kinds-card__subtitle' )
				)
				.toHaveText( AUTHOR );
		}
	};
	await expectPostTarget();

	const settings = page.getByRole( 'region', { name: 'Editor settings' } );
	const override = async ( index, { quotation, description } ) => {
		await canvas.locator( '.wp-block-block' ).nth( index ).click();
		await settings
			.getByRole( 'button', { name: 'Quotation', exact: true } )
			.click();
		await settings
			.getByRole( 'textbox', { name: 'Quoted excerpt (optional)' } )
			.fill( quotation );
		await settings
			.getByRole( 'button', { name: 'Description', exact: true } )
			.click();
		await settings
			.getByRole( 'textbox', { name: 'Description', exact: true } )
			.fill( description );
	};
	await override( 0, {
		quotation: 'First instance quote',
		description: 'First instance description',
	} );
	await override( 1, {
		quotation: '',
		description: 'Second instance description',
	} );

	const expectInstanceText = async () => {
		await expect( cards.nth( 0 ).locator( 'blockquote' ) ).toHaveText(
			'First instance quote'
		);
		await expect( cards.nth( 1 ).locator( 'blockquote' ) ).toHaveCount( 0 );
		await expect(
			cards.nth( 1 ).locator( '.post-kinds-card__notes' )
		).toHaveText( 'Second instance description' );
	};
	await expectInstanceText();

	await page.keyboard.press( 'ControlOrMeta+s' );
	await expect
		.poll( () =>
			page.evaluate(
				() =>
					! window.wp.data.select( 'core/editor' ).isSavingPost() &&
					! window.wp.data.select( 'core/editor' ).isEditedPostDirty()
			)
		)
		.toBe( true );

	await page.reload();
	await expect( cards ).toHaveCount( 2, { timeout: 30000 } );
	await expectInstanceText();
	await expectPostTarget();

	const pattern = await page.evaluate(
		( id ) =>
			window.wp.apiFetch( {
				path: `/wp/v2/blocks/${ id }?context=edit`,
			} ),
		patternId
	);
	expect( pattern.content.raw ).toContain( 'Pattern quotation' );

	await page.goto( link );
	const articles = page.locator( 'article.k-reply' );
	await expect( articles ).toHaveCount( 2 );
	await expect( articles.nth( 0 ).locator( 'blockquote' ) ).toHaveText(
		'First instance quote'
	);
	await expect( articles.nth( 1 ).locator( 'blockquote' ) ).toHaveCount( 0 );
	await expect( articles.nth( 1 ).locator( '.pk-note' ) ).toHaveText(
		'Second instance description'
	);
	for ( const index of [ 0, 1 ] ) {
		await expect(
			articles.nth( index ).locator( 'a.u-url' )
		).toHaveAttribute( 'href', TARGET );
		await expect(
			articles.nth( index ).locator( '.p-author .p-name' )
		).toHaveText( AUTHOR );
	}
} );

test( "a synced Reply pattern in a Query Loop shows each post's target", async ( {
	page,
} ) => {
	await logIn( page );
	const token = `p17loop${ Date.now() }`;
	const { postId, link } = await page.evaluate(
		async ( { card, search } ) => {
			const pattern = await window.wp.apiFetch( {
				path: '/wp/v2/blocks',
				method: 'POST',
				data: {
					title: 'Loop Reply pattern',
					status: 'publish',
					content: card,
				},
			} );
			const [ reply ] = await window.wp.apiFetch( {
				path: '/wp/v2/kind?slug=reply',
			} );
			const createPost = ( title, letter, status ) =>
				window.wp.apiFetch( {
					path: '/wp/v2/posts',
					method: 'POST',
					data: {
						title,
						status,
						kind: [ reply.id ],
						meta: {
							_pkiw_cite_url: `https://${ letter }.example/target`,
							_pkiw_cite_author: `Author ${ letter }`,
						},
					},
				} );
			await createPost( `${ search } A`, 'a', 'publish' );
			await createPost( `${ search } B`, 'b', 'publish' );
			const container = await createPost(
				'Reply pattern in a Query Loop',
				'container',
				'draft'
			);
			const query = {
				perPage: 2,
				pages: 0,
				offset: 0,
				postType: 'post',
				order: 'asc',
				orderBy: 'title',
				search,
				exclude: [ container.id ],
				inherit: false,
			};
			const post = await window.wp.apiFetch( {
				path: `/wp/v2/posts/${ container.id }`,
				method: 'POST',
				data: {
					status: 'publish',
					content: `<!-- wp:query ${ JSON.stringify( {
						queryId: 1,
						query,
					} ) } --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:block {"ref":${ pattern.id }} /--><!-- /wp:post-template --></div><!-- /wp:query -->`,
				},
			} );
			return { postId: post.id, link: post.link };
		},
		{ card: CARD, search: token }
	);

	const canvas = await openEditor( page, postId );
	const items = canvas.locator( '.wp-block-post-template > li:visible' );
	await expect( items ).toHaveCount( 2, { timeout: 30000 } );
	for ( const [ index, letter ] of [ 'a', 'b' ].entries() ) {
		const item = items.nth( index );
		await expect( item.locator( '.wp-block-post-title' ) ).toHaveText(
			`${ token } ${ letter.toUpperCase() }`
		);
		await expect
			.soft( item.locator( '.post-kinds-card__input--url' ) )
			.toHaveValue( `https://${ letter }.example/target` );
		await expect
			.soft( item.locator( '.post-kinds-card__subtitle' ) )
			.toHaveText( `Author ${ letter }` );
	}

	await page.goto( link );
	const cites = page.locator( '.wp-block-post-content article.k-reply' );
	await expect( cites.locator( '.p-author .p-name' ) ).toHaveText( [
		'Author a',
		'Author b',
	] );
	expect(
		await cites
			.locator( 'a.u-url' )
			.evaluateAll( ( links ) => links.map( ( a ) => a.href ) )
	).toEqual( [ 'https://a.example/target', 'https://b.example/target' ] );
} );
