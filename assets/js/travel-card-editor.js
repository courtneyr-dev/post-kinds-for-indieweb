/**
 * Trip and itinerary card editors.
 *
 * Plain script on purpose: travel data stays in registered post meta and the
 * dynamic block saves no attributes.
 *
 * @param {Object} wp WordPress global.
 */
( function ( wp ) {
	if (
		! wp ||
		! wp.blocks ||
		! wp.element ||
		! wp.components ||
		! wp.blockEditor ||
		! wp.coreData
	) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const useEntityProp = wp.coreData.useEntityProp;
	const Button = wp.components.Button;
	const SelectControl = wp.components.SelectControl;
	const TextControl = wp.components.TextControl;
	const localized = window.pkiwTravelCard || {};
	const siteOffset = localized.siteOffset || '+00:00';
	const privacyOptions = [
		{
			label: __( 'Private', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'private',
		},
		{
			label: __(
				'Approximate',
				'post-kinds-for-indieweb-in-block-themes'
			),
			value: 'approximate',
		},
		{
			label: __( 'Public', 'post-kinds-for-indieweb-in-block-themes' ),
			value: 'public',
		},
	];

	function offsets() {
		const values = [];
		for ( let minutes = -12 * 60; minutes <= 14 * 60; minutes += 15 ) {
			const sign = minutes < 0 ? '-' : '+';
			const absolute = Math.abs( minutes );
			const value =
				sign +
				String( Math.floor( absolute / 60 ) ).padStart( 2, '0' ) +
				':' +
				String( absolute % 60 ).padStart( 2, '0' );
			values.push( { label: value, value } );
		}
		return values;
	}

	const offsetOptions = offsets();

	function parseList( value ) {
		try {
			const parsed = JSON.parse( value || '[]' );
			return Array.isArray( parsed ) ? parsed : [];
		} catch {
			return [];
		}
	}

	function emptyPlace() {
		return {
			name: '',
			locality: '',
			region: '',
			country: '',
			street: '',
			postal_code: '',
			url: '',
			osm_id: '',
			venue_id: '',
			lat: null,
			lng: null,
		};
	}

	function writeMeta( meta, setMeta, key, value ) {
		setMeta( Object.assign( {}, meta, { [ key ]: value } ) );
	}

	function PostPrivacy( { meta, setMeta } ) {
		const value = meta._pkiw_geo_privacy || 'approximate';
		return el( SelectControl, {
			label: __(
				'Location shown',
				'post-kinds-for-indieweb-in-block-themes'
			),
			help: __(
				'The most any stop can show. Each stop can show less.',
				'post-kinds-for-indieweb-in-block-themes'
			),
			value,
			options: privacyOptions,
			onChange( next ) {
				if ( 'public' === next && 'public' !== value ) {
					// eslint-disable-next-line no-alert
					const confirmed = window.confirm(
						__(
							'You are about to make your precise location public. Are you sure?',
							'post-kinds-for-indieweb-in-block-themes'
						)
					);
					if ( ! confirmed ) {
						return;
					}
				}
				writeMeta( meta, setMeta, '_pkiw_geo_privacy', next );
			},
		} );
	}

	function PlaceControls( { place, label, update } ) {
		const fields = [
			[ 'name', __( 'Name', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[
				'street',
				__( 'Street', 'post-kinds-for-indieweb-in-block-themes' ),
			],
			[
				'locality',
				__( 'Locality', 'post-kinds-for-indieweb-in-block-themes' ),
			],
			[
				'region',
				__( 'Region', 'post-kinds-for-indieweb-in-block-themes' ),
			],
			[
				'country',
				__( 'Country', 'post-kinds-for-indieweb-in-block-themes' ),
			],
			[
				'postal_code',
				__( 'Postal code', 'post-kinds-for-indieweb-in-block-themes' ),
			],
			[
				'lat',
				__( 'Latitude', 'post-kinds-for-indieweb-in-block-themes' ),
			],
			[
				'lng',
				__( 'Longitude', 'post-kinds-for-indieweb-in-block-themes' ),
			],
			[ 'url', __( 'URL', 'post-kinds-for-indieweb-in-block-themes' ) ],
		];
		return el(
			'fieldset',
			{ className: 'pkiw-travel-editor__place', key: label },
			el( 'legend', null, label ),
			fields.map( ( [ key, fieldLabel ] ) =>
				el( TextControl, {
					key,
					label: fieldLabel,
					type: [ 'lat', 'lng' ].includes( key ) ? 'number' : 'text',
					value:
						null === place[ key ] || undefined === place[ key ]
							? ''
							: place[ key ],
					onChange( value ) {
						update(
							Object.assign( {}, place, { [ key ]: value } )
						);
					},
				} )
			)
		);
	}

	function ListButtons( { index, length, move, remove } ) {
		return el(
			'div',
			{ className: 'pkiw-travel-editor__actions' },
			el(
				Button,
				{
					disabled: 0 === index,
					onClick: () => move( index, index - 1 ),
				},
				__( 'Move up', 'post-kinds-for-indieweb-in-block-themes' )
			),
			el(
				Button,
				{
					disabled: index === length - 1,
					onClick: () => move( index, index + 1 ),
				},
				__( 'Move down', 'post-kinds-for-indieweb-in-block-themes' )
			),
			el(
				Button,
				{ isDestructive: true, onClick: () => remove( index ) },
				__( 'Remove', 'post-kinds-for-indieweb-in-block-themes' )
			)
		);
	}

	function datetimeParts( value ) {
		const match = String( value || '' ).match(
			/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?::\d{2})?([+-]\d{2}:\d{2}|Z)?$/
		);
		return match
			? {
					local: match[ 1 ],
					offset:
						'Z' === match[ 2 ]
							? '+00:00'
							: match[ 2 ] || siteOffset,
				}
			: { local: '', offset: siteOffset };
	}

	function DateTimeControls( { label, value, update } ) {
		const parts = datetimeParts( value );
		return el(
			'div',
			{ className: 'pkiw-travel-editor__datetime', key: label },
			el( TextControl, {
				label,
				type: 'datetime-local',
				value: parts.local,
				onChange( local ) {
					update( local ? local + ':00' + parts.offset : '' );
				},
			} ),
			el( SelectControl, {
				label: __(
					'UTC offset',
					'post-kinds-for-indieweb-in-block-themes'
				),
				value: parts.offset,
				options: offsetOptions,
				onChange( offset ) {
					update( parts.local ? parts.local + ':00' + offset : '' );
				},
			} )
		);
	}

	function TravelEdit( { kind, metaKey, context } ) {
		const postType =
			context && context.postType ? context.postType : 'post';
		const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
		const currentMeta = meta || {};
		const items = parseList( currentMeta[ metaKey ] );
		const writeItems = ( next ) =>
			writeMeta( currentMeta, setMeta, metaKey, JSON.stringify( next ) );
		const update = ( index, next ) =>
			writeItems(
				items.map( ( item, itemIndex ) =>
					itemIndex === index ? next : item
				)
			);
		const move = ( from, to ) => {
			if ( to < 0 || to >= items.length ) {
				return;
			}
			const next = items.slice();
			const [ item ] = next.splice( from, 1 );
			next.splice( to, 0, item );
			writeItems( next );
		};
		const remove = ( index ) =>
			writeItems(
				items.filter( ( item, itemIndex ) => itemIndex !== index )
			);
		const isTrip = 'trip' === kind;

		return el(
			'div',
			useBlockProps( {
				className: 'pkiw-travel-editor pkiw-travel-editor--' + kind,
			} ),
			PostPrivacy( { meta: currentMeta, setMeta } ),
			items.map( ( item, index ) =>
				el(
					'section',
					{ className: 'pkiw-travel-editor__item', key: index },
					el(
						'h3',
						null,
						( isTrip
							? __(
									'Stop',
									'post-kinds-for-indieweb-in-block-themes'
								)
							: __(
									'Leg',
									'post-kinds-for-indieweb-in-block-themes'
								) ) +
							' ' +
							( index + 1 )
					),
					isTrip
						? PlaceControls( {
								place: item,
								label: __(
									'Place',
									'post-kinds-for-indieweb-in-block-themes'
								),
								update: ( place ) =>
									update(
										index,
										Object.assign( {}, item, place )
									),
							} )
						: [
								PlaceControls( {
									place: item.from || emptyPlace(),
									label: __(
										'From',
										'post-kinds-for-indieweb-in-block-themes'
									),
									update: ( place ) =>
										update(
											index,
											Object.assign( {}, item, {
												from: place,
											} )
										),
								} ),
								PlaceControls( {
									place: item.to || emptyPlace(),
									label: __(
										'To',
										'post-kinds-for-indieweb-in-block-themes'
									),
									update: ( place ) =>
										update(
											index,
											Object.assign( {}, item, {
												to: place,
											} )
										),
								} ),
							],
					isTrip
						? el( TextControl, {
								label: __(
									'Date',
									'post-kinds-for-indieweb-in-block-themes'
								),
								type: 'date',
								value: item.date || '',
								onChange: ( date ) =>
									update(
										index,
										Object.assign( {}, item, { date } )
									),
							} )
						: [
								DateTimeControls( {
									label: __(
										'Departure',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: item.departure || '',
									update: ( departure ) =>
										update(
											index,
											Object.assign( {}, item, {
												departure,
											} )
										),
								} ),
								DateTimeControls( {
									label: __(
										'Arrival',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: item.arrival || '',
									update: ( arrival ) =>
										update(
											index,
											Object.assign( {}, item, {
												arrival,
											} )
										),
								} ),
								el( TextControl, {
									key: 'carrier',
									label: __(
										'Carrier',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: item.carrier || '',
									onChange: ( carrier ) =>
										update(
											index,
											Object.assign( {}, item, {
												carrier,
											} )
										),
								} ),
							],
					el( SelectControl, {
						label: __(
							'Location shown',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: item.privacy || 'approximate',
						options: privacyOptions,
						onChange: ( privacy ) =>
							update(
								index,
								Object.assign( {}, item, { privacy } )
							),
					} ),
					ListButtons( { index, length: items.length, move, remove } )
				)
			),
			el(
				Button,
				{
					variant: 'primary',
					onClick() {
						writeItems(
							items.concat(
								isTrip
									? [
											Object.assign( emptyPlace(), {
												date: '',
												privacy: 'approximate',
											} ),
										]
									: [
											{
												from: emptyPlace(),
												to: emptyPlace(),
												departure: '',
												arrival: '',
												carrier: '',
												privacy: 'approximate',
											},
										]
							)
						);
					},
				},
				isTrip
					? __(
							'Add stop',
							'post-kinds-for-indieweb-in-block-themes'
						)
					: __( 'Add leg', 'post-kinds-for-indieweb-in-block-themes' )
			)
		);
	}

	[
		[
			'trip',
			'post-kinds-indieweb/trip-card',
			'_pkiw_trip_stops',
			__( 'Trip card', 'post-kinds-for-indieweb-in-block-themes' ),
		],
		[
			'itinerary',
			'post-kinds-indieweb/itinerary-card',
			'_pkiw_itinerary_legs',
			__( 'Itinerary card', 'post-kinds-for-indieweb-in-block-themes' ),
		],
	].forEach( ( [ kind, name, metaKey, title ] ) => {
		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title,
			category: 'post-kinds-indieweb',
			attributes: {},
			usesContext: [ 'postId', 'postType' ],
			supports: {
				html: false,
				multiple: false,
				reusable: false,
				className: true,
			},
			edit: ( props ) =>
				TravelEdit( Object.assign( {}, props, { kind, metaKey } ) ),
			save() {
				return null;
			},
		} );
	} );
} )( window.wp );
