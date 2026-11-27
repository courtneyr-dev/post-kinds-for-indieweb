/**
 * AI suggestions panel: readiness announcements and typed suggestions that
 * never overwrite the author's citation fields.
 */

import {
	act,
	render,
	screen,
	fireEvent,
	waitFor,
} from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { useSelect, useDispatch } from '@wordpress/data';
import AiSuggestionsPanel, {
	STATUS_PATH,
	SUGGEST_PATH,
	errorMessage,
} from '../../../src/editor/ai-suggestions/AiSuggestionsPanel';
import {
	applyEdit,
	planSuggestions,
} from '../../../src/editor/ai-suggestions/plan-suggestions';

let mockPanelOpen = true;

jest.mock( '@wordpress/editor', () => ( {
	// Like a collapsed PanelBody or a Slot that mounts later, the panel can
	// hide its children while the component itself stays mounted.
	PluginDocumentSettingPanel: ( { title, children } ) => (
		<section aria-label={ title }>
			{ mockPanelOpen ? children : null }
		</section>
	),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, disabled, type, ...rest } ) => (
		<button
			type={ type }
			onClick={ onClick }
			disabled={ disabled }
			aria-label={ rest[ 'aria-label' ] }
			aria-describedby={ rest[ 'aria-describedby' ] }
		>
			{ children }
		</button>
	),
} ) );

const SUGGESTIONS = [
	{ field: 'cite_name', value: 'Suggested title', source: 'ai' },
	{ field: 'cite_author', value: 'Ada Example', source: 'ai' },
	{ field: 'cite_summary', value: 'A short summary.', source: 'ai' },
];

const editPost = jest.fn();
let mockMeta;
let mockPostType;
let mockPostId;

const status = ( state, extra = {} ) => ( {
	state,
	message: `Message for ${ state }`,
	connectorsUrl: '',
	...extra,
} );

/**
 * Route apiFetch calls to canned answers.
 *
 * @param {Object}       readiness Status response.
 * @param {Object|Error} suggest   Suggestion response, or a rejection.
 * @param {boolean}      reject    Whether the suggestion call rejects.
 */
const routes = ( readiness, suggest = {}, reject = false ) => {
	apiFetch.mockImplementation( ( { path } ) => {
		if ( path === STATUS_PATH ) {
			return Promise.resolve( readiness );
		}
		if ( path === SUGGEST_PATH ) {
			return reject
				? Promise.reject( suggest )
				: Promise.resolve( suggest );
		}
		return Promise.reject( new Error( `Unexpected ${ path }` ) );
	} );
};

beforeEach( () => {
	jest.clearAllMocks();
	mockPanelOpen = true;
	mockPostType = 'post';
	mockPostId = 12;
	mockMeta = {
		_pkiw_cite_url: 'https://example.org/article',
		_pkiw_cite_name: 'Title I corrected by hand',
		_pkiw_cite_author: '',
		_pkiw_cite_summary: '',
	};
	useDispatch.mockImplementation( () => ( { editPost } ) );
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getCurrentPostType: () => mockPostType,
			getCurrentPostId: () => mockPostId,
			getEditedPostAttribute: () => mockMeta,
		} ) )
	);
} );

describe( 'planSuggestions', () => {
	it( 'keeps filled fields and offers empty ones, in server order', () => {
		expect( planSuggestions( SUGGESTIONS, mockMeta ) ).toEqual( [
			{
				field: 'cite_name',
				label: 'Title',
				value: 'Suggested title',
				keep: true,
			},
			{
				field: 'cite_author',
				label: 'Author',
				value: 'Ada Example',
				keep: false,
			},
			{
				field: 'cite_summary',
				label: 'Summary',
				value: 'A short summary.',
				keep: false,
			},
		] );
	} );

	it( 'drops anything that is not a typed suggestion for a citation field', () => {
		expect(
			planSuggestions(
				[
					{
						field: 'cite_url',
						value: 'https://evil.example',
						source: 'ai',
					},
					{ field: 'cite_name', value: 'No source' },
					{ field: 'cite_author', value: [ 'list' ], source: 'ai' },
					{ field: 'cite_summary', value: '   ', source: 'ai' },
					null,
				],
				{}
			)
		).toEqual( [] );
		expect( planSuggestions( 'not a list', {} ) ).toEqual( [] );
	} );

	it( 'refuses an apply when the author typed into the field meanwhile', () => {
		expect(
			applyEdit( { field: 'cite_author', value: 'Ada' }, mockMeta )
		).toEqual( {
			_pkiw_cite_author: 'Ada',
		} );
		expect(
			applyEdit(
				{ field: 'cite_author', value: 'Ada' },
				{ _pkiw_cite_author: 'Mine' }
			)
		).toBeNull();
	} );
} );

describe( 'AiSuggestionsPanel readiness', () => {
	it( 'renders nothing while AI is disabled for the site', async () => {
		routes( status( 'disabled' ) );
		const { container } = render( <AiSuggestionsPanel /> );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders nothing outside posts', async () => {
		mockPostType = 'page';
		routes( status( 'ready' ) );
		const { container } = render( <AiSuggestionsPanel /> );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	it.each( [ 'missing', 'blocked' ] )(
		'announces %s in a polite live region with no suggest button',
		async ( state ) => {
			routes( status( state ) );
			render( <AiSuggestionsPanel /> );

			const region = await screen.findByRole( 'status' );
			expect( region ).toHaveAttribute( 'aria-live', 'polite' );
			await waitFor( () =>
				expect( region ).toHaveTextContent( `Message for ${ state }` )
			);
			expect(
				screen.queryByRole( 'button', {
					name: 'Suggest citation details',
				} )
			).not.toBeInTheDocument();
		}
	);

	it( 'links admins to Core Connectors when no provider is connected', async () => {
		routes(
			status( 'missing', {
				connectorsUrl:
					'http://example.org/wp-admin/options-connectors.php',
			} )
		);
		render( <AiSuggestionsPanel /> );

		const link = await screen.findByRole( 'link', {
			name: 'Connect a provider in Settings > Connectors',
		} );
		expect( link ).toHaveAttribute(
			'href',
			'http://example.org/wp-admin/options-connectors.php'
		);
	} );

	it( 'explains the disabled suggest button when there is no cited URL', async () => {
		mockMeta = { ...mockMeta, _pkiw_cite_url: '' };
		routes( status( 'ready' ) );
		render( <AiSuggestionsPanel /> );

		const button = await screen.findByRole( 'button', {
			name: 'Suggest citation details',
		} );
		expect( button ).toBeDisabled();
		expect( button ).toHaveAttribute(
			'aria-describedby',
			'pkiw-ai-suggestions-needs-url'
		);
		expect(
			screen.getByText( 'Add the cited URL first.' )
		).toBeInTheDocument();
	} );
} );

describe( 'AiSuggestionsPanel suggestions', () => {
	it( 'offers typed suggestions and applies only what the author picks', async () => {
		routes( status( 'ready' ), {
			state: 'ready',
			suggestions: SUGGESTIONS,
		} );
		render( <AiSuggestionsPanel /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Suggest citation details',
			} )
		);

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: SUGGEST_PATH,
			method: 'POST',
			data: { url: 'https://example.org/article' },
		} );
		await waitFor( () =>
			expect( screen.getByRole( 'status' ) ).toHaveTextContent(
				'3 suggestions ready. Filled fields keep your entries.'
			)
		);
		expect( editPost ).not.toHaveBeenCalled();
		expect( screen.getByText( 'Kept your entry.' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Apply suggested Title' } )
		).not.toBeInTheDocument();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Apply suggested Author' } )
		);

		expect( editPost ).toHaveBeenCalledTimes( 1 );
		expect( editPost ).toHaveBeenCalledWith( {
			meta: { _pkiw_cite_author: 'Ada Example' },
		} );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Author applied.'
		);
		expect( document.activeElement.tagName ).toBe( 'LI' );
		expect( document.activeElement ).toHaveTextContent( 'Applied.' );
	} );

	it( 'keeps a field the author filled after the suggestion arrived', async () => {
		routes( status( 'ready' ), {
			state: 'ready',
			suggestions: SUGGESTIONS,
		} );
		const { rerender } = render( <AiSuggestionsPanel /> );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Suggest citation details',
			} )
		);
		await screen.findByRole( 'button', {
			name: 'Apply suggested Summary',
		} );

		mockMeta = { ...mockMeta, _pkiw_cite_summary: 'I wrote this myself.' };
		rerender( <AiSuggestionsPanel /> );

		expect(
			screen.queryByRole( 'button', { name: 'Apply suggested Summary' } )
		).not.toBeInTheDocument();
		expect( screen.getAllByText( 'Kept your entry.' ) ).toHaveLength( 2 );
		expect( editPost ).not.toHaveBeenCalled();
	} );

	it( 'offers Apply for a field the author cleared while the request was pending', async () => {
		let resolve;
		apiFetch.mockImplementation( ( { path } ) =>
			path === STATUS_PATH
				? Promise.resolve( status( 'ready' ) )
				: new Promise( ( done ) => {
						resolve = done;
					} )
		);
		const { rerender } = render( <AiSuggestionsPanel /> );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Suggest citation details',
			} )
		);

		mockMeta = { ...mockMeta, _pkiw_cite_name: '' };
		rerender( <AiSuggestionsPanel /> );
		await act( async () => {
			resolve( { state: 'ready', suggestions: SUGGESTIONS } );
		} );

		expect(
			screen.queryByText( 'Kept your entry.' )
		).not.toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Apply suggested Title' } )
		);
		expect( editPost ).toHaveBeenCalledWith( {
			meta: { _pkiw_cite_name: 'Suggested title' },
		} );
	} );

	it.each( [
		[
			'malformed',
			{
				code: 'pkiw_ai_malformed',
				message: 'Wrong shape, nothing suggested.',
			},
			'Wrong shape, nothing suggested.',
		],
		[
			'provider text',
			{ code: 'prompt_client_error', message: 'Bad key sk-secret' },
			'Suggestions could not be loaded.',
		],
	] )(
		'announces a %s failure and writes nothing',
		async ( label, error, shown ) => {
			routes( status( 'ready' ), error, true );
			render( <AiSuggestionsPanel /> );

			fireEvent.click(
				await screen.findByRole( 'button', {
					name: 'Suggest citation details',
				} )
			);

			await waitFor( () =>
				expect( screen.getByRole( 'status' ) ).toHaveTextContent(
					shown
				)
			);
			expect( screen.getByRole( 'status' ) ).not.toHaveTextContent(
				'sk-secret'
			);
			expect( editPost ).not.toHaveBeenCalled();
			expect( screen.queryByRole( 'list' ) ).not.toBeInTheDocument();
		}
	);

	it( "shows only the plugin's own error messages", () => {
		expect(
			errorMessage( { code: 'pkiw_ai_blocked', message: 'Blocked.' } )
		).toBe( 'Blocked.' );
		expect(
			errorMessage( { code: 'http_request_failed', message: 'x' } )
		).toBe( 'Suggestions could not be loaded.' );
		expect(
			errorMessage( { code: 'pkiw_rate_limited', message: 'Wait.' } )
		).toBe( 'Wait.' );
		expect( errorMessage( undefined ) ).toBe(
			'Suggestions could not be loaded.'
		);
	} );

	it( 'uses non-submitting buttons so it can sit inside any form', async () => {
		routes( status( 'ready' ), {
			state: 'ready',
			suggestions: SUGGESTIONS,
		} );
		const onSubmit = jest.fn( ( event ) => event.preventDefault() );
		render(
			<form onSubmit={ onSubmit }>
				<AiSuggestionsPanel />
			</form>
		);

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Suggest citation details',
			} )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply suggested Author',
			} )
		);

		expect( onSubmit ).not.toHaveBeenCalled();
		screen
			.getAllByRole( 'button' )
			.forEach( ( button ) =>
				expect( button ).toHaveAttribute( 'type', 'button' )
			);
	} );
} );

/**
 * Record DOM mutations and report whether the status region was inserted
 * empty and filled by a later mutation.
 *
 * @param {() => Promise<void>} open Callback that renders or opens the panel.
 * @param {string}              text Message the region must end up announcing.
 * @return {Promise<boolean>} True when the message arrived after the region.
 */
const filledAfterMount = async ( open, text ) => {
	const records = [];
	const observer = new window.MutationObserver( ( list ) =>
		records.push( ...list )
	);
	observer.observe( document.body, {
		childList: true,
		subtree: true,
		characterData: true,
	} );
	await open();
	const region = await screen.findByRole( 'status' );
	await waitFor( () => expect( region ).toHaveTextContent( text ) );
	records.push( ...observer.takeRecords() );
	observer.disconnect();

	const inserted = records.findIndex(
		( record ) =>
			record.type === 'childList' &&
			[ ...record.addedNodes ].some(
				( node ) => node === region || node.contains?.( region )
			)
	);
	return (
		inserted >= 0 &&
		records.some(
			( record, index ) =>
				index > inserted &&
				( record.target === region ||
					region.contains( record.target ) ) &&
				( record.type === 'characterData' ||
					record.addedNodes.length > 0 )
		)
	);
};

describe( 'AiSuggestionsPanel live region', () => {
	it( 'mounts the region empty when the panel opens after readiness arrived', async () => {
		routes( status( 'missing' ) );
		mockPanelOpen = false;
		const { rerender } = render( <AiSuggestionsPanel /> );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		await act( async () => {} );

		expect(
			await filledAfterMount( async () => {
				mockPanelOpen = true;
				rerender( <AiSuggestionsPanel /> );
			}, 'Message for missing' )
		).toBe( true );
	} );

	it( 'mounts the polite region empty and adds the first message after mount', async () => {
		routes( status( 'missing' ) );
		const records = [];
		const observer = new window.MutationObserver( ( list ) =>
			records.push( ...list )
		);
		observer.observe( document.body, {
			childList: true,
			subtree: true,
			characterData: true,
		} );

		render( <AiSuggestionsPanel /> );
		const region = await screen.findByRole( 'status' );
		await waitFor( () =>
			expect( region ).toHaveTextContent( 'Message for missing' )
		);
		records.push( ...observer.takeRecords() );
		observer.disconnect();

		const inserted = records.find(
			( record ) =>
				record.type === 'childList' &&
				[ ...record.addedNodes ].some(
					( node ) => node === region || node.contains?.( region )
				)
		);
		expect( inserted ).toBeDefined();
		const filledLater = records.some(
			( record ) =>
				records.indexOf( record ) > records.indexOf( inserted ) &&
				( record.target === region ||
					region.contains( record.target ) ) &&
				( record.type === 'characterData' ||
					record.addedNodes.length > 0 )
		);
		expect( filledLater ).toBe( true );
	} );
} );

describe( 'AiSuggestionsPanel citation identity', () => {
	it( 'stops offering suggestions for URL A once the URL changes to B', async () => {
		routes( status( 'ready' ), {
			state: 'ready',
			suggestions: SUGGESTIONS,
		} );
		const { rerender } = render( <AiSuggestionsPanel /> );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Suggest citation details',
			} )
		);
		await screen.findByRole( 'button', { name: 'Apply suggested Author' } );

		mockMeta = { ...mockMeta, _pkiw_cite_url: 'https://example.org/other' };
		rerender( <AiSuggestionsPanel /> );

		expect(
			screen.queryByRole( 'button', { name: 'Apply suggested Author' } )
		).not.toBeInTheDocument();
		expect( screen.queryByText( 'Ada Example' ) ).not.toBeInTheDocument();
		expect( editPost ).not.toHaveBeenCalled();
	} );

	it( 'discards a response that arrives after the URL changed', async () => {
		let resolve;
		apiFetch.mockImplementation( ( { path } ) =>
			path === STATUS_PATH
				? Promise.resolve( status( 'ready' ) )
				: new Promise( ( done ) => {
						resolve = done;
					} )
		);
		const { rerender } = render( <AiSuggestionsPanel /> );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Suggest citation details',
			} )
		);

		mockMeta = { ...mockMeta, _pkiw_cite_url: 'https://example.org/other' };
		rerender( <AiSuggestionsPanel /> );
		await act( async () => {
			resolve( { state: 'ready', suggestions: SUGGESTIONS } );
		} );

		expect(
			screen.queryByRole( 'button', { name: 'Apply suggested Author' } )
		).not.toBeInTheDocument();
		await waitFor( () =>
			expect( screen.getByRole( 'status' ) ).toHaveTextContent(
				'The cited URL changed. Ask for suggestions again.'
			)
		);
		expect( editPost ).not.toHaveBeenCalled();
	} );

	it( 'does not carry suggestions to another post with the same URL', async () => {
		routes( status( 'ready' ), {
			state: 'ready',
			suggestions: SUGGESTIONS,
		} );
		const { rerender } = render( <AiSuggestionsPanel /> );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Suggest citation details',
			} )
		);
		await screen.findByRole( 'button', { name: 'Apply suggested Author' } );

		mockPostId = 13;
		rerender( <AiSuggestionsPanel /> );

		expect(
			screen.queryByRole( 'button', { name: 'Apply suggested Author' } )
		).not.toBeInTheDocument();
	} );
} );
