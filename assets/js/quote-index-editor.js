/**
 * Quote archive editor registrations.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender || ! wp.components ) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const Disabled = wp.components.Disabled;
	const PanelBody = wp.components.PanelBody;
	const ToggleControl = wp.components.ToggleControl;
	const RangeControl = wp.components.RangeControl;
	const SelectControl = wp.components.SelectControl;
	const TextControl = wp.components.TextControl;
	const ENTRY = 'post-kinds-indieweb/quote-entry';
	const AUTHORS = 'post-kinds-indieweb/quote-authors';
	const WORKS = 'post-kinds-indieweb/quote-works';

	function EntryEdit( props ) {
		const attributes = props.attributes;
		const set = props.setAttributes;
		const postId = props.context && props.context.postId;
		return el(
			'div',
			useBlockProps( { className: 'pkiw-quote-entry-editor' } ),
			InspectorControls &&
				PanelBody &&
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __(
								'Quote sections',
								'post-kinds-for-indieweb-in-block-themes'
							),
						},
						ToggleControl &&
							el( ToggleControl, {
								label: __(
									'Show section headings',
									'post-kinds-for-indieweb-in-block-themes'
								),
								checked: attributes.showSections,
								onChange: ( value ) =>
									set( { showSections: value } ),
							} ),
						RangeControl &&
							el( RangeControl, {
								label: __(
									'Lines per page',
									'post-kinds-for-indieweb-in-block-themes'
								),
								min: 1,
								max: 50,
								value: attributes.linesPerPage,
								onChange: ( value ) =>
									set( { linesPerPage: value } ),
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
								onChange: ( value ) =>
									set( { headingLevel: Number( value ) } ),
							} ),
						SelectControl &&
							el( SelectControl, {
								label: __(
									'Section order',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: attributes.sectionOrder,
								options: [
									{
										label: __(
											'A–Z',
											'post-kinds-for-indieweb-in-block-themes'
										),
										value: 'asc',
									},
									{
										label: __(
											'Z–A',
											'post-kinds-for-indieweb-in-block-themes'
										),
										value: 'desc',
									},
								],
								onChange: ( value ) =>
									set( { sectionOrder: value } ),
							} ),
						SelectControl &&
							el( SelectControl, {
								label: __(
									'Quotes without an author',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: attributes.emptyGroup,
								options: [
									{
										label: __(
											'Last',
											'post-kinds-for-indieweb-in-block-themes'
										),
										value: 'last',
									},
									{
										label: __(
											'First',
											'post-kinds-for-indieweb-in-block-themes'
										),
										value: 'first',
									},
								],
								onChange: ( value ) =>
									set( { emptyGroup: value } ),
							} ),
						TextControl &&
							el( TextControl, {
								label: __(
									'Empty group heading',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: attributes.emptyLabel,
								onChange: ( value ) =>
									set( { emptyLabel: value } ),
							} )
					)
				),
			el( ServerSideRender, {
				block: ENTRY,
				attributes,
				urlQueryArgs: { post_id: postId, pkiw_kind: 'quote' },
			} )
		);
	}

	function indexEdit( name ) {
		return function IndexEdit( props ) {
			return el(
				'div',
				useBlockProps(),
				InspectorControls &&
					PanelBody &&
					el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{
								title: __(
									'Settings',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
							TextControl &&
								el( TextControl, {
									label: __(
										'Heading',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: props.attributes.heading,
									onChange: ( value ) =>
										props.setAttributes( {
											heading: value,
										} ),
								} )
						)
					),
				el(
					Disabled,
					null,
					el( ServerSideRender, {
						block: name,
						attributes: props.attributes,
					} )
				)
			);
		};
	}

	const shared = {
		apiVersion: 3,
		category: 'post-kinds-indieweb',
		supports: { html: false, reusable: false },
		save() {
			return null;
		},
	};

	wp.blocks.registerBlockType( ENTRY, {
		...shared,
		title: __( 'Quote entry', 'post-kinds-for-indieweb-in-block-themes' ),
		icon: 'format-quote',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 3 },
			linesPerPage: { type: 'integer', default: 8 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
		},
		edit: EntryEdit,
	} );

	wp.blocks.registerBlockType( AUTHORS, {
		...shared,
		title: __( 'Quote authors', 'post-kinds-for-indieweb-in-block-themes' ),
		icon: 'groups',
		attributes: {
			heading: { type: 'string', default: 'By author' },
			headingLevel: { type: 'integer', default: 2 },
		},
		edit: indexEdit( AUTHORS ),
	} );

	wp.blocks.registerBlockType( WORKS, {
		...shared,
		title: __(
			'Quote source works',
			'post-kinds-for-indieweb-in-block-themes'
		),
		icon: 'book',
		attributes: {
			heading: { type: 'string', default: 'By source work' },
			headingLevel: { type: 'integer', default: 2 },
			twoPageMax: { type: 'integer', default: 16 },
		},
		edit: indexEdit( WORKS ),
	} );
} )( window.wp );
