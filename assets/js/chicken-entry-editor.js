/**
 * Chicken entry — editor registration.
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
	const NAME = 'post-kinds-indieweb/chicken-entry';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const ToggleControl = wp.components && wp.components.ToggleControl;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;

	function ChickenEntryEdit( props ) {
		return renderEdit(
			props,
			useBlockProps( { className: 'pkiw-chicken-entry-editor' } )
		);
	}

	function controls( props ) {
		const panelTitle = __(
			'Chicken entries',
			'post-kinds-for-indieweb-in-block-themes'
		);
		const sectionLabel = __(
			'Show month headings',
			'post-kinds-for-indieweb-in-block-themes'
		);
		const headingLabel = __(
			'Heading level',
			'post-kinds-for-indieweb-in-block-themes'
		);
		const linesLabel = __(
			'Lines per page',
			'post-kinds-for-indieweb-in-block-themes'
		);

		return InspectorControls && PanelBody && ToggleControl
			? el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: panelTitle,
						},
						el( ToggleControl, {
							label: sectionLabel,
							checked: props.attributes.showSections,
							onChange( value ) {
								props.setAttributes( {
									showSections: value,
								} );
							},
						} ),
						SelectControl &&
							el( SelectControl, {
								label: headingLabel,
								value: props.attributes.headingLevel,
								options: [
									{ label: '2', value: 2 },
									{ label: '3', value: 3 },
									{ label: '4', value: 4 },
								],
								onChange( value ) {
									props.setAttributes( {
										headingLevel: parseInt( value, 10 ),
									} );
								},
							} ),
						RangeControl &&
							el( RangeControl, {
								label: linesLabel,
								min: 1,
								max: 100,
								value: props.attributes.linesPerPage,
								onChange( value ) {
									props.setAttributes( {
										linesPerPage: value,
									} );
								},
							} )
					)
			  )
			: null;
	}

	function renderEdit( props, blockProps ) {
		const postId = props.context && props.context.postId;
		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				__(
					'Chicken entry: place this block inside a Query Loop’s Post Template.',
					'post-kinds-for-indieweb-in-block-themes'
				)
			);
		}

		return el(
			'div',
			blockProps,
			controls( props ),
			el( ServerSideRender, {
				block: NAME,
				attributes: props.attributes,
				urlQueryArgs: { post_id: postId, pkiw_kind: 'chicken' },
			} )
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __(
			'Chicken entries',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		supports: { html: false, reusable: false },
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 24 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
		},
		edit: ChickenEntryEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
