/**
 * Issue fields document panel.
 *
 * Plain script on purpose: no build step, only WordPress globals.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if (
		! wp ||
		! wp.plugins ||
		! wp.editor ||
		! wp.editor.PluginDocumentSettingPanel ||
		! wp.element ||
		! wp.components ||
		! wp.data
	) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const TextControl = wp.components.TextControl;
	const SelectControl = wp.components.SelectControl;
	const PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	const localized = window.pkiwIssueFields || {};

	function stringValue( meta, key ) {
		return 'string' === typeof meta[ key ] ? meta[ key ] : '';
	}

	function IssueFieldsPanel() {
		const meta = wp.data.useSelect( ( select ) => {
			const store = select( 'core/editor' );
			return store ? store.getEditedPostAttribute( 'meta' ) || {} : {};
		}, [] );
		const { editPost } = wp.data.useDispatch( 'core/editor' );

		function control( key, label, type ) {
			return el( TextControl, {
				key,
				label,
				type,
				value: stringValue( meta, key ),
				onChange( value ) {
					editPost( { meta: { [ key ]: value } } );
				},
			} );
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'pkiw-issue-fields',
				title: __(
					'Issue details',
					'post-kinds-for-indieweb-in-block-themes'
				),
			},
			control(
				'_pkiw_cite_url',
				__(
					'Repository URL',
					'post-kinds-for-indieweb-in-block-themes'
				),
				'url'
			),
			control(
				'_pkiw_cite_name',
				__(
					'Repository name',
					'post-kinds-for-indieweb-in-block-themes'
				)
			),
			control(
				'_pkiw_issue_number',
				__( 'Issue number', 'post-kinds-for-indieweb-in-block-themes' )
			),
			el( SelectControl, {
				label: __(
					'Status',
					'post-kinds-for-indieweb-in-block-themes'
				),
				value: stringValue( meta, '_pkiw_issue_status' ),
				options: [
					{ label: '—', value: '' },
					{
						label: __(
							'Open',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: 'open',
					},
					{
						label: __(
							'Closed',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: 'closed',
					},
				],
				onChange( value ) {
					editPost( { meta: { _pkiw_issue_status: value } } );
				},
			} )
		);
	}

	function render() {
		const editor = wp.data.select( 'core/editor' );
		const termId = Number( localized.termId || 0 );
		const kinds = editor
			? editor.getEditedPostAttribute( 'kind' ) || []
			: [];
		const kindStore = wp.data.select( 'post-kinds-indieweb/post-kinds' );
		const selectedKind =
			kindStore && 'function' === typeof kindStore.getSelectedKind
				? kindStore.getSelectedKind()
				: '';
		if (
			! (
				termId > 0 &&
				Array.isArray( kinds ) &&
				kinds.includes( termId )
			) &&
			'issue' !== selectedKind
		) {
			return null;
		}

		return el( IssueFieldsPanel );
	}

	wp.plugins.registerPlugin( 'pkiw-issue-fields', { render } );
} )( window.wp );
