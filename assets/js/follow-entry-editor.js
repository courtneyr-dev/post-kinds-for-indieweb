/**
 * Follow archive block editor registrations.
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
	const PanelBody = wp.components.PanelBody;
	const SelectControl = wp.components.SelectControl;
	const TextControl = wp.components.TextControl;
	const ToggleControl = wp.components.ToggleControl;
	const ENTRY = 'post-kinds-indieweb/follow-entry';
	const LETTERS = 'post-kinds-indieweb/follow-letters';

	function EntryEdit( props ) {
		const postId = props.context && props.context.postId;
		return el(
			'div',
			useBlockProps(),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{
						title: __(
							'Follow entry',
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
							label: 'H' + value,
							value,
						} ) ),
						onChange: ( value ) =>
							props.setAttributes( {
								headingLevel: Number( value ),
							} ),
					} )
				)
			),
			el( ServerSideRender, {
				block: ENTRY,
				attributes: props.attributes,
				urlQueryArgs: { post_id: postId, pkiw_kind: 'follow' },
			} )
		);
	}

	function LettersEdit( props ) {
		return el(
			'div',
			useBlockProps(),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{
						title: __(
							'Follow letters',
							'post-kinds-for-indieweb-in-block-themes'
						),
					},
					el( TextControl, {
						label: __(
							'Label',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: props.attributes.label,
						onChange: ( value ) =>
							props.setAttributes( { label: value } ),
					} ),
					el( ToggleControl, {
						label: __(
							'Show OPML link',
							'post-kinds-for-indieweb-in-block-themes'
						),
						checked: props.attributes.opmlLink,
						onChange: ( value ) =>
							props.setAttributes( { opmlLink: value } ),
					} )
				)
			),
			el( ServerSideRender, {
				block: LETTERS,
				attributes: props.attributes,
			} )
		);
	}

	wp.blocks.registerBlockType( ENTRY, {
		apiVersion: 3,
		title: __( 'Follow entry', 'post-kinds-for-indieweb-in-block-themes' ),
		category: 'post-kinds-indieweb',
		icon: 'groups',
		usesContext: [ 'postId', 'postType', 'queryId', 'templateSlug' ],
		ancestor: [ 'core/post-template' ],
		attributes: { headingLevel: { type: 'integer', default: 2 } },
		supports: { html: false, reusable: false },
		edit: EntryEdit,
		save() {
			return null;
		},
	} );

	wp.blocks.registerBlockType( LETTERS, {
		apiVersion: 3,
		title: __(
			'Follow letters',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'sort',
		attributes: {
			label: {
				type: 'string',
				default: __(
					'Follows by first letter',
					'post-kinds-for-indieweb-in-block-themes'
				),
			},
			opmlLink: { type: 'boolean', default: true },
		},
		supports: { html: false, reusable: false },
		edit: LettersEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
