/**
 * Travel card meta-only editor.
 */

const SCRIPT = '../../assets/js/travel-card-editor.js';

function findAll( node, type, found = [] ) {
	if ( ! node || 'object' !== typeof node ) {
		return found;
	}
	if ( Array.isArray( node ) ) {
		node.forEach( ( child ) => findAll( child, type, found ) );
		return found;
	}
	if ( node.type === type ) {
		found.push( node );
	}
	( node.children || [] ).forEach( ( child ) =>
		findAll( child, type, found )
	);
	return found;
}

function load( meta = {}, siteOffset = '-04:00' ) {
	const registered = {};
	const setMeta = jest.fn();
	const useEntityProp = jest.fn( () => [ meta, setMeta ] );
	window.pkiwTravelCard = { siteOffset };
	window.confirm = jest.fn( () => true );
	window.wp = {
		blocks: {
			registerBlockType: ( name, settings ) => {
				registered[ name ] = settings;
			},
		},
		element: {
			createElement: ( type, props, ...children ) => ( {
				type,
				props: props || {},
				children,
			} ),
		},
		i18n: { __: ( text ) => text },
		blockEditor: { useBlockProps: ( props ) => props || {} },
		coreData: { useEntityProp },
		components: {
			Button: 'Button',
			SelectControl: 'SelectControl',
			TextControl: 'TextControl',
		},
	};
	jest.isolateModules( () => require( SCRIPT ) );
	return { registered, setMeta, useEntityProp };
}

function edit( name, meta = {}, siteOffset = '-04:00' ) {
	const loaded = load( meta, siteOffset );
	return {
		...loaded,
		tree: loaded.registered[ name ].edit( {
			context: { postType: 'post' },
		} ),
	};
}

describe( 'travel card editor', () => {
	afterEach( () => {
		delete window.wp;
		delete window.pkiwTravelCard;
		jest.resetModules();
	} );

	it( 'registers two dynamic blocks with no saved attributes', () => {
		const { registered } = load();
		expect( Object.keys( registered ) ).toEqual( [
			'post-kinds-indieweb/trip-card',
			'post-kinds-indieweb/itinerary-card',
		] );
		Object.values( registered ).forEach( ( settings ) => {
			expect( settings.apiVersion ).toBe( 3 );
			expect( settings.attributes ).toEqual( {} );
			expect( settings.supports.multiple ).toBe( false );
			expect( settings.save() ).toBeNull();
		} );
	} );

	it( 'adds, moves, and removes stops by writing only meta', () => {
		const { tree, setMeta } = edit( 'post-kinds-indieweb/trip-card' );
		findAll( tree, 'Button' )
			.find( ( button ) => button.children.includes( 'Add stop' ) )
			.props.onClick();
		const first = JSON.parse(
			setMeta.mock.calls[ 0 ][ 0 ]._pkiw_trip_stops
		);
		expect( first[ 0 ].privacy ).toBe( 'approximate' );
		expect( setMeta.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty(
			'attributes'
		);

		const saved = {
			_pkiw_trip_stops: JSON.stringify( [
				{ name: 'A', privacy: 'approximate' },
				{ name: 'B', privacy: 'public' },
			] ),
		};
		const opened = edit( 'post-kinds-indieweb/trip-card', saved );
		findAll( opened.tree, 'Button' )
			.find( ( button ) => button.children.includes( 'Move down' ) )
			.props.onClick();
		expect(
			JSON.parse(
				opened.setMeta.mock.calls[ 0 ][ 0 ]._pkiw_trip_stops
			).map( ( item ) => item.name )
		).toEqual( [ 'B', 'A' ] );
		findAll( opened.tree, 'Button' )
			.find( ( button ) => button.children.includes( 'Remove' ) )
			.props.onClick();
		expect(
			JSON.parse( opened.setMeta.mock.calls[ 1 ][ 0 ]._pkiw_trip_stops )
		).toHaveLength( 1 );
	} );

	it( 'uses approximate item privacy, stable child keys, and a quarter-hour site offset', () => {
		const { tree } = edit(
			'post-kinds-indieweb/itinerary-card',
			{
				_pkiw_itinerary_legs: JSON.stringify( [
					{ from: {}, to: {}, privacy: 'approximate' },
				] ),
			},
			'+05:45'
		);
		const selects = findAll( tree, 'SelectControl' );
		expect(
			selects.filter(
				( control ) => control.props.label === 'Location shown'
			)
		).toHaveLength( 2 );
		expect(
			selects.filter(
				( control ) => control.props.label === 'Location shown'
			)[ 1 ].props.value
		).toBe( 'approximate' );
		expect(
			selects
				.filter( ( control ) => control.props.label === 'UTC offset' )
				.every( ( control ) => control.props.value === '+05:45' )
		).toBe( true );
		expect(
			selects
				.find( ( control ) => control.props.label === 'UTC offset' )
				.props.options.map( ( option ) => option.value )
		).toContain( '+05:45' );
		expect(
			findAll( tree, 'fieldset' ).map(
				( fieldset ) => fieldset.props.key
			)
		).toEqual( [ 'From', 'To' ] );
		expect(
			findAll( tree, 'div' )
				.filter(
					( div ) =>
						div.props.className === 'pkiw-travel-editor__datetime'
				)
				.map( ( div ) => div.props.key )
		).toEqual( [ 'Departure', 'Arrival' ] );
	} );

	it( 'keeps post privacy unset until changed and confirms before public', () => {
		const first = edit( 'post-kinds-indieweb/trip-card', {} );
		const control = findAll( first.tree, 'SelectControl' ).find(
			( item ) => item.props.label === 'Location shown'
		);
		expect( control.props.value ).toBe( 'approximate' );
		expect( first.setMeta ).not.toHaveBeenCalled();
		window.confirm.mockReturnValueOnce( false );
		control.props.onChange( 'public' );
		expect( window.confirm ).toHaveBeenCalledWith(
			'You are about to make your precise location public. Are you sure?'
		);
		expect( first.setMeta ).not.toHaveBeenCalled();
		control.props.onChange( 'private' );
		expect( first.setMeta ).toHaveBeenCalledWith( {
			_pkiw_geo_privacy: 'private',
		} );
	} );

	it( 'writes Public only after the author accepts the confirmation', () => {
		const opened = edit( 'post-kinds-indieweb/trip-card', {} );
		const control = findAll( opened.tree, 'SelectControl' ).find(
			( item ) => item.props.label === 'Location shown'
		);
		window.confirm.mockReturnValueOnce( true );

		control.props.onChange( 'public' );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect( opened.setMeta ).toHaveBeenCalledWith( {
			_pkiw_geo_privacy: 'public',
		} );
	} );

	it( 'both cards read and write the same post-level privacy key', () => {
		for ( const name of [
			'post-kinds-indieweb/trip-card',
			'post-kinds-indieweb/itinerary-card',
		] ) {
			const opened = edit( name, { _pkiw_geo_privacy: 'public' } );
			const control = findAll( opened.tree, 'SelectControl' ).find(
				( item ) => item.props.label === 'Location shown'
			);
			expect( control.props.value ).toBe( 'public' );
			control.props.onChange( 'approximate' );
			expect( opened.setMeta ).toHaveBeenCalledWith( {
				_pkiw_geo_privacy: 'approximate',
			} );
		}
	} );
} );
