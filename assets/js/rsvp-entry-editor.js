/**
 * RSVP entry editor registration.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const NAME = 'post-kinds-indieweb/rsvp-entry';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;
	const TextControl = wp.components && wp.components.TextControl;
	const localized = window.pkiwRsvpEntry || {};
	const groups = localized.groups || {};

	function controls( props ) {
		const attributes = props.attributes;
		const set = props.setAttributes;
		const groupLabels = Object.assign( {}, groups, attributes.groupLabels );
		if ( ! InspectorControls || ! PanelBody ) {
			return null;
		}

		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __(
						'RSVP archive',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
				RangeControl &&
					el( RangeControl, {
						label: __(
							'Lines per page',
							'post-kinds-for-indieweb-in-block-themes'
						),
						min: 1,
						max: 50,
						value: attributes.linesPerPage,
						onChange( value ) {
							set( { linesPerPage: value } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						label: __(
							'Heading level',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.headingLevel,
						options: [ 2, 3, 4 ].map( ( value ) => ( {
							label: 'H' + value,
							value,
						} ) ),
						onChange( value ) {
							set( { headingLevel: Number( value ) } );
						},
					} ),
				Object.keys( groups ).map( ( key ) =>
					TextControl
						? el( TextControl, {
								key,
								label: groups[ key ],
								placeholder: groups[ key ],
								value: attributes.groupLabels[ key ] || '',
								onChange( value ) {
									set( {
										groupLabels: Object.assign(
											{},
											groupLabels,
											{ [ key ]: value }
										),
									} );
								},
							} )
						: null
				)
			)
		);
	}

	function edit( props ) {
		const postId = props.context && props.context.postId;
		return el(
			'div',
			useBlockProps( { className: 'pkiw-rsvp-entry-editor' } ),
			controls( props ),
			el( ServerSideRender, {
				block: NAME,
				attributes: props.attributes,
				urlQueryArgs: { post_id: postId, pkiw_kind: 'rsvp' },
			} )
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'RSVP entry', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'An RSVP archive item for the current post. Use inside a Query Loop.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'calendar-alt',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 9 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
			groupLabels: { type: 'object', default: {} },
		},
		supports: { html: false, reusable: false },
		ancestor: [ 'core/post-template' ],
		edit,
		save() {
			return null;
		},
	} );
} )( window.wp );
