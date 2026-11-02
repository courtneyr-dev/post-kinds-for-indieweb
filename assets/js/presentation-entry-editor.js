/**
 * Presentation entry block editor registration.
 *
 * @param {Object} wp WordPress script globals.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const name = 'post-kinds-indieweb/presentation-entry';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const RangeControl = wp.components.RangeControl;
	const SelectControl = wp.components.SelectControl;

	function Edit( props ) {
		const postId = props.context && props.context.postId;
		const controls = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __(
						'Presentation entries',
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
					block: name,
					attributes: props.attributes,
					urlQueryArgs: {
						post_id: postId,
						pkiw_kind: 'presentation',
					},
				} )
			: __(
					'Place this block inside a Query Loop’s Post Template.',
					'post-kinds-for-indieweb-in-block-themes'
				);
		return el(
			'div',
			useBlockProps( { className: 'pkiw-presentation-entry-editor' } ),
			controls,
			body
		);
	}

	wp.blocks.registerBlockType( name, {
		apiVersion: 3,
		title: __(
			'Presentation entry',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		supports: { html: false, reusable: false },
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 12 },
			sectionOrder: { type: 'string', default: 'desc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
		},
		edit: Edit,
		save() {
			return null;
		},
	} );
} )( window.wp );
