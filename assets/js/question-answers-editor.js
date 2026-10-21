/**
 * Question answers settings block.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if (
		! wp ||
		! wp.blocks ||
		! wp.element ||
		! wp.i18n ||
		! wp.blockEditor ||
		! wp.blockEditor.useBlockProps
	) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps;
	const InspectorControls =
		wp.blockEditor && wp.blockEditor.InspectorControls;
	const PanelBody = wp.components && wp.components.PanelBody;
	const TextControl = wp.components && wp.components.TextControl;
	const SelectControl = wp.components && wp.components.SelectControl;

	function AnswersEdit( props ) {
		const attributes = props.attributes;
		const set = props.setAttributes;
		const blockProps = useBlockProps( {
			className: 'pkiw-question-answers-editor',
		} );
		const inspector =
			InspectorControls && PanelBody
				? el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{
								title: __(
									'Answers',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
							TextControl &&
								el( TextControl, {
									label: __(
										'Heading label',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: attributes.headingLabel,
									onChange( value ) {
										set( { headingLabel: value } );
									},
								} ),
							SelectControl &&
								el( SelectControl, {
									label: __(
										'Heading level',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: String( attributes.headingLevel ),
									options: [ 2, 3, 4 ].map( ( level ) => ( {
										label: String( level ),
										value: String( level ),
									} ) ),
									onChange( value ) {
										set( {
											headingLevel: Number( value ),
										} );
									},
								} ),
							TextControl &&
								el( TextControl, {
									label: __(
										'Response link label',
										'post-kinds-for-indieweb-in-block-themes'
									),
									value: attributes.responseLinkLabel,
									onChange( value ) {
										set( { responseLinkLabel: value } );
									},
								} )
						)
				  )
				: null;

		return el(
			'div',
			blockProps,
			inspector,
			el(
				'p',
				{ className: 'pkiw-question-answers-editor__preview' },
				attributes.headingLabel
			)
		);
	}

	wp.blocks.registerBlockType( 'post-kinds-indieweb/answers', {
		apiVersion: 3,
		title: __( 'Answers', 'post-kinds-for-indieweb-in-block-themes' ),
		description: __(
			'Configure the answers section shown on a question.',
			'post-kinds-for-indieweb-in-block-themes'
		),
		category: 'post-kinds-indieweb',
		icon: 'format-chat',
		usesContext: [ 'postId' ],
		attributes: {
			headingLabel: {
				type: 'string',
				default: 'Answers',
				role: 'content',
			},
			headingLevel: { type: 'integer', default: 2 },
			responseLinkLabel: {
				type: 'string',
				default: 'Leave an answer',
				role: 'content',
			},
		},
		supports: { multiple: false, html: false, reusable: false },
		edit: AnswersEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
