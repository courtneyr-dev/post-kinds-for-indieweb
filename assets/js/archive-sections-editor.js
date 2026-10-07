/**
 * Archive sections marker — editor registration.
 *
 * The block is server-rendered (see includes/class-kind-archive-layouts.php).
 * It prints nothing on the front end. In the editor, a ServerSideRender asks
 * the block-renderer endpoint for the loop post's render: the heading of the
 * section that post opens, or a hidden placeholder when it continues one.
 * The inspector sets the section settings the engine reads, plus a heading
 * field for each group of the template's kind (window.pkiwArchiveSections)
 * and for any group the block already names.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender ) {
		return;
	}

	const BLOCK = 'post-kinds-indieweb/archive-sections';
	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const sprintf = wp.i18n.sprintf;
	const ServerSideRender = wp.serverSideRender;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const ToggleControl = wp.components && wp.components.ToggleControl;
	const RangeControl = wp.components && wp.components.RangeControl;
	const SelectControl = wp.components && wp.components.SelectControl;
	const TextControl = wp.components && wp.components.TextControl;
	const data = window.pkiwArchiveSections || {};
	const groupsByKind = data.groups || {};

	// The kind a taxonomy-kind-<slug> template is the archive of, or ''.
	function templateKind( slug ) {
		return 'string' === typeof slug &&
			0 === slug.indexOf( 'taxonomy-kind-' )
			? slug.slice( 'taxonomy-kind-'.length )
			: '';
	}

	// Group key => default label: the kind's groups, then any the block already names.
	function groupFields( kind, labels ) {
		const fields = Object.assign( {}, groupsByKind[ kind ] || {} );
		Object.keys( labels ).forEach( function ( key ) {
			if ( ! Object.prototype.hasOwnProperty.call( fields, key ) ) {
				fields[ key ] = key;
			}
		} );
		return fields;
	}

	function controls( props, kind ) {
		const attributes = props.attributes;
		const labels = attributes.groupLabels || {};
		const set = props.setAttributes;
		const fields = groupFields( kind, labels );

		const settings = [
			el( ToggleControl, {
				key: 'showSections',
				label: __(
					'Show section headings',
					'post-kinds-for-indieweb-in-block-themes'
				),
				checked: attributes.showSections,
				onChange( value ) {
					set( { showSections: value } );
				},
			} ),
			RangeControl &&
				el( RangeControl, {
					key: 'linesPerPage',
					label: __(
						'Posts per page',
						'post-kinds-for-indieweb-in-block-themes'
					),
					help: __(
						'On the kind archive. 0 uses the site’s Reading setting.',
						'post-kinds-for-indieweb-in-block-themes'
					),
					min: 0,
					max: 100,
					value: attributes.linesPerPage,
					onChange( value ) {
						set( { linesPerPage: value || 0 } );
					},
				} ),
		];

		if ( attributes.showSections ) {
			settings.push(
				SelectControl &&
					el( SelectControl, {
						key: 'headingLevel',
						label: __(
							'Heading level',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: String( attributes.headingLevel ),
						options: [ 2, 3, 4 ].map( function ( level ) {
							return {
								label: sprintf(
									/* translators: %d: heading level number. */
									__(
										'Heading %d',
										'post-kinds-for-indieweb-in-block-themes'
									),
									level
								),
								value: String( level ),
							};
						} ),
						onChange( value ) {
							set( { headingLevel: parseInt( value, 10 ) || 2 } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						key: 'sectionOrder',
						label: __(
							'Section order',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.sectionOrder,
						options: [
							{
								label: __(
									'First to last',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'asc',
							},
							{
								label: __(
									'Last to first',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'desc',
							},
						],
						onChange( value ) {
							set( { sectionOrder: value } );
						},
					} ),
				SelectControl &&
					el( SelectControl, {
						key: 'emptyGroup',
						label: __(
							'Posts in no group',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.emptyGroup,
						options: [
							{
								label: __(
									'After the other sections',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'last',
							},
							{
								label: __(
									'Before the other sections',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'first',
							},
						],
						onChange( value ) {
							set( { emptyGroup: value } );
						},
					} ),
				TextControl &&
					el( TextControl, {
						key: 'emptyLabel',
						label: __(
							'Heading for posts in no group',
							'post-kinds-for-indieweb-in-block-themes'
						),
						help: __(
							'Empty uses “Other”.',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: attributes.emptyLabel,
						onChange( value ) {
							set( { emptyLabel: value } );
						},
					} )
			);
		}

		const headings =
			attributes.showSections && TextControl
				? Object.keys( fields ).map( function ( key ) {
						return el( TextControl, {
							key: 'group-' + key,
							label: sprintf(
								/* translators: %s: the section's default heading, such as "Video games". */
								__(
									'Heading for %s',
									'post-kinds-for-indieweb-in-block-themes'
								),
								fields[ key ]
							),
							value: labels[ key ] || '',
							onChange( value ) {
								const next = Object.assign( {}, labels );
								if ( value ) {
									next[ key ] = value;
								} else {
									delete next[ key ];
								}
								set( { groupLabels: next } );
							},
						} );
					} )
				: [];

		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __(
						'Sections',
						'post-kinds-for-indieweb-in-block-themes'
					),
				},
				...settings.filter( Boolean )
			),
			headings.length
				? el(
						PanelBody,
						{
							title: __(
								'Section headings',
								'post-kinds-for-indieweb-in-block-themes'
							),
						},
						...headings
					)
				: null
		);
	}

	function ArchiveSectionsEdit( props ) {
		const blockProps = useBlockProps( {
			className: 'pkiw-archive-sections-editor',
		} );
		const postId = props.context && props.context.postId;
		const kind = templateKind(
			props.context && props.context.templateSlug
		);
		const inspector =
			InspectorControls && PanelBody && ToggleControl
				? controls( props, kind )
				: null;

		if ( ! postId ) {
			return el(
				'div',
				blockProps,
				inspector,
				__(
					'Archive sections: place this block inside a Query Loop’s Post Template.',
					'post-kinds-for-indieweb-in-block-themes'
				)
			);
		}

		// A post can hold several kinds; preview it as the template's kind.
		return el(
			'div',
			blockProps,
			inspector,
			el( ServerSideRender, {
				block: BLOCK,
				attributes: props.attributes,
				urlQueryArgs: kind
					? { post_id: postId, pkiw_kind: kind }
					: { post_id: postId },
			} )
		);
	}

	wp.blocks.registerBlockType( BLOCK, {
		apiVersion: 3,
		title: __(
			'Archive sections',
			'post-kinds-for-indieweb-in-block-themes'
		),
		description: __(
			'Sorts a kind archive into sections with headings, and sets how many posts each page shows. Use inside a Query Loop.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'excerpt-view',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		attributes: {
			showSections: { type: 'boolean', default: true },
			headingLevel: { type: 'integer', default: 2 },
			linesPerPage: { type: 'integer', default: 0 },
			sectionOrder: { type: 'string', default: 'asc' },
			emptyGroup: { type: 'string', default: 'last' },
			emptyLabel: { type: 'string', default: '', role: 'content' },
			groupLabels: { type: 'object', default: {}, role: 'content' },
		},
		supports: { html: false, reusable: false },
		ancestor: [ 'core/post-template' ],
		edit: ArchiveSectionsEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
