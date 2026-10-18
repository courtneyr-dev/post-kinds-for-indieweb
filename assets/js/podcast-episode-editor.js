/**
 * Podcast episode lineup: editor registration.
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
	const NAME = 'post-kinds-indieweb/podcast-episode';
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const ToggleControl = wp.components && wp.components.ToggleControl;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;
	const TextControl = wp.components && wp.components.TextControl;

	function controls( props ) {
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
						'Podcast episodes',
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
						max: 100,
						value: props.attributes.linesPerPage,
						onChange( value ) {
							props.setAttributes( { linesPerPage: value } );
						},
					} ),
				ToggleControl &&
					el( ToggleControl, {
						label: __(
							'Show station headings',
							'post-kinds-for-indieweb-in-block-themes'
						),
						checked: props.attributes.showSections,
						onChange( value ) {
							props.setAttributes( { showSections: value } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						label: __(
							'Heading level',
							'post-kinds-for-indieweb-in-block-themes'
						),
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
				SelectControl &&
					el( SelectControl, {
						label: __(
							'Station order',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: props.attributes.sectionOrder,
						options: [
							{
								label: __(
									'A to Z',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'asc',
							},
							{
								label: __(
									'Z to A',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'desc',
							},
						],
						onChange( value ) {
							props.setAttributes( { sectionOrder: value } );
						},
					} ),
				TextControl &&
					el( TextControl, {
						label: __(
							'Label for shows with no name',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: props.attributes.emptyLabel,
						onChange( value ) {
							props.setAttributes( { emptyLabel: value } );
						},
					} )
			)
		);
	}

	function PodcastEpisodeEdit( props ) {
		const blockProps = useBlockProps( {
			className: 'pkiw-podcast-episode-editor',
		} );
		const postId = props.context && props.context.postId;

		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				__(
					'Podcast episodes: place this block inside a Query Loop’s Post Template.',
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
				urlQueryArgs: {
					post_id: postId,
					pkiw_kind: 'listen',
					pkiw_view: 'podcast',
				},
			} )
		);
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __(
			'Podcast episodes',
			'post-kinds-for-indieweb-in-block-themes'
		),
		description: __(
			'Podcast episodes grouped into show stations.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'microphone',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		supports: { html: false, reusable: false },
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 12 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '' },
		},
		edit: PodcastEpisodeEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
