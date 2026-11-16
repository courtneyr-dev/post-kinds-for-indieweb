/** Plain-script Tag entry registration. */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender ) {
		return;
	}
	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const NAME = 'post-kinds-indieweb/tag-entry';
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const RangeControl = wp.components.RangeControl;
	const SelectControl = wp.components.SelectControl;
	function edit( props ) {
		const attributes = props.attributes;
		return el(
			'div',
			wp.blockEditor.useBlockProps( { className: 'pkiw-tag-entry-editor' } ),
			el( InspectorControls, null, el( PanelBody, { title: __( 'Tag archive', 'post-kinds-for-indieweb-in-block-themes' ) },
				el( RangeControl, { label: __( 'Lines per page', 'post-kinds-for-indieweb-in-block-themes' ), min: 1, max: 50, value: attributes.linesPerPage, onChange( value ) { props.setAttributes( { linesPerPage: value } ); } } ),
				el( SelectControl, { label: __( 'Heading level', 'post-kinds-for-indieweb-in-block-themes' ), value: attributes.headingLevel, options: [ 2, 3, 4 ].map( ( value ) => ( { label: 'H' + value, value } ) ), onChange( value ) { props.setAttributes( { headingLevel: Number( value ) } ); } } )
			) ),
			el( wp.serverSideRender, { block: NAME, attributes, urlQueryArgs: { post_id: props.context && props.context.postId, pkiw_kind: 'tag' } } )
		);
	}
	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Tag entry', 'post-kinds-for-indieweb-in-block-themes' ),
		category: 'post-kinds-indieweb',
		icon: 'tag',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		attributes: { showSections: { type: 'boolean', default: true }, headingLevel: { type: 'integer', default: 2 }, linesPerPage: { type: 'integer', default: 12 }, sectionOrder: { type: 'string', default: 'asc' }, emptyGroup: { type: 'string', default: 'last' }, emptyLabel: { type: 'string', default: '' } },
		supports: { html: false, reusable: false },
		ancestor: [ 'core/post-template' ],
		edit,
		save() { return null; },
	} );
} )( window.wp );
