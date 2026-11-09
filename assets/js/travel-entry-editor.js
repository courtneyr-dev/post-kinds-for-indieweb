/**
 * Travel archive map and entry block editor registrations.
 *
 * @param {Object} wp WordPress script globals.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const RangeControl = wp.components.RangeControl;
	const SelectControl = wp.components.SelectControl;

	function kindFromContext( context ) {
		return String( context && context.templateSlug ).includes( 'itinerary' )
			? 'itinerary'
			: 'trip';
	}

	function EntryEdit( props ) {
		const postId = props.context && props.context.postId;
		const kind = props.name.includes( 'itinerary' ) ? 'itinerary' : 'trip';
		const controls = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __(
						'Travel entries',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
				el( SelectControl, {
					label: __(
						'Heading level',
						'post-kinds-for-indieweb-in-block-themes'
					),
					value: props.attributes.headingLevel,
					options: [ 2, 3, 4 ].map( ( value ) => ( {
						label: String( value ),
						value,
					} ) ),
					onChange( value ) {
						props.setAttributes( {
							headingLevel: parseInt( value, 10 ),
						} );
					},
				} ),
				el( RangeControl, {
					label: __(
						'Lines per page',
						'post-kinds-for-indieweb-in-block-themes'
					),
					min: 1,
					max: 100,
					value: props.attributes.linesPerPage,
					onChange( value ) {
						props.setAttributes( { linesPerPage: value } );
					},
				} )
			)
		);
		const body = postId
			? el( ServerSideRender, {
					block: props.name,
					attributes: props.attributes,
					urlQueryArgs: { post_id: postId, pkiw_kind: kind },
				} )
			: __(
					'Place this block inside a Query Loop’s Post Template.',
					'post-kinds-for-indieweb-in-block-themes'
				);

		return el(
			'div',
			useBlockProps( { className: 'pkiw-travel-entry-editor' } ),
			controls,
			body
		);
	}

	function registerEntry( name, title ) {
		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title,
			category: 'post-kinds-indieweb',
			usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			ancestor: [ 'core/post-template' ],
			supports: { html: false, reusable: false, className: true },
			attributes: {
				showSections: { type: 'boolean', default: true },
				headingLevel: { type: 'integer', default: 2 },
				linesPerPage: { type: 'integer', default: 12 },
				sectionOrder: { type: 'string', default: 'asc' },
				emptyGroup: { type: 'string', default: 'last' },
				emptyLabel: { type: 'string', default: '' },
			},
			edit: EntryEdit,
			save() {
				return null;
			},
		} );
	}

	function MapEdit( props ) {
		return el(
			'div',
			useBlockProps( { className: 'pkiw-travel-map-editor' } ),
			el( ServerSideRender, {
				block: 'post-kinds-indieweb/travel-map',
				urlQueryArgs: {
					pkiw_kind: kindFromContext( props.context ),
				},
			} )
		);
	}

	registerEntry(
		'post-kinds-indieweb/trip-entry',
		__( 'Trip entry', 'post-kinds-for-indieweb-in-block-themes' )
	);
	registerEntry(
		'post-kinds-indieweb/itinerary-entry',
		__( 'Itinerary entry', 'post-kinds-for-indieweb-in-block-themes' )
	);

	wp.blocks.registerBlockType( 'post-kinds-indieweb/travel-map', {
		apiVersion: 3,
		title: __( 'Travel map', 'post-kinds-for-indieweb-in-block-themes' ),
		category: 'post-kinds-indieweb',
		usesContext: [ 'query', 'queryId', 'templateSlug' ],
		supports: { html: false, reusable: false },
		edit: MapEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
