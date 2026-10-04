/**
 * Kind archive templates — Site Editor preview.
 *
 * A Query Loop that inherits the main query previews the site's latest posts
 * in the Site Editor. Core narrows that preview for category, tag, post type
 * and post format templates, and for no other taxonomy. A template named
 * taxonomy-kind-<slug> is that kind's archive, so this hands its Post Template
 * a query for that kind's posts. Core also resets an inheriting loop's page
 * size to the site's setting, so the size comes from the
 * `pkiw_kind_archive_preview_per_page` filter when a site sets one for the kind.
 *
 * Core works out a block's context before this filter runs and passes it down
 * as a prop, so the preview replaces that prop. Nothing is saved: the block's
 * attributes are untouched.
 *
 * Plain script on purpose: no build step, only WordPress globals. The editor
 * bundle doesn't load in the Site Editor.
 *
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.compose || ! wp.data || ! wp.element ) {
		return;
	}

	const el = wp.element.createElement;
	const PREFIX = 'taxonomy-kind-';
	const settings = window.pkiwKindTemplatePreview || {};
	const perPage = settings.perPage || {};

	function kindFromTemplateSlug( slug ) {
		return 'string' === typeof slug && 0 === slug.indexOf( PREFIX )
			? slug.slice( PREFIX.length )
			: '';
	}

	const withKindTemplatePreview = wp.compose.createHigherOrderComponent(
		function ( BlockEdit ) {
			return function ( props ) {
				const context = props.context;
				const query =
					'core/post-template' === props.name && context
						? context.query
						: null;
				const kind =
					query && query.inherit
						? kindFromTemplateSlug( context.templateSlug )
						: '';
				const termId = wp.data.useSelect(
					function ( select ) {
						if ( ! kind ) {
							return 0;
						}
						const terms = select( 'core' ).getEntityRecords(
							'taxonomy',
							'kind',
							{ slug: kind, per_page: 1, _fields: 'id' }
						);
						return terms && terms.length ? terms[ 0 ].id : 0;
					},
					[ kind ]
				);
				const preview = wp.element.useMemo(
					function () {
						if ( ! termId ) {
							return null;
						}
						// Core 7.1 reads the terms under `include`; earlier
						// releases read them by taxonomy at the top level.
						const terms = {};
						terms.kind = [ termId ];
						terms.include = { kind: [ termId ] };
						return Object.assign( {}, context, {
							query: Object.assign(
								{},
								query,
								{ inherit: false, taxQuery: terms },
								perPage[ kind ]
									? { perPage: perPage[ kind ] }
									: {}
							),
						} );
					},
					[ context, query, kind, termId ]
				);

				return el(
					BlockEdit,
					preview
						? Object.assign( {}, props, { context: preview } )
						: props
				);
			};
		},
		'withKindTemplatePreview'
	);

	wp.hooks.addFilter(
		'editor.BlockEdit',
		'post-kinds-indieweb/kind-template-preview',
		withKindTemplatePreview
	);
} )( window.wp );
