/**
 * Kind archive templates — Site Editor preview.
 *
 * A Query Loop that inherits the main query previews the site's latest posts
 * in the Site Editor. Core narrows that preview for category, tag, post type
 * and post format templates, and for no other taxonomy. A template named
 * taxonomy-kind-<slug> is that kind's archive, so this hands its Post Template
 * a query for that kind's posts. The page size comes from the menu entry's
 * "Lines per page" when it is set, then from the
 * `pkiw_kind_archive_preview_per_page` filter when a site sets one for the
 * kind, and otherwise from the site's posts per page, the number core shows.
 * A grouped kind (eat, drink, or one with a registered group source) is asked
 * for in grouped order, `orderby=pkiw_group`, which the plugin adds to the
 * REST posts routes; the entry block's section order and empty-group setting
 * pick the variant of that order. An entry block that fixes a date bucket is
 * asked for newest first, the order its buckets need.
 *
 * Core works out a block's context before this filter runs and passes it down
 * as a prop, so the preview replaces that prop. Nothing is saved: the block's
 * attributes are untouched.
 *
 * Core's own Query Loop edit writes an inheriting loop's perPage (the site's
 * posts per page) and excludeCurrent (null) as soon as the block shows. That
 * marks a kind template changed when nothing was edited, and a save would
 * store a database copy in place of the template file. Neither value reaches
 * an inheriting loop's front end, and the editor shows neither control for
 * one, so on a kind template the loop keeps its stored values.
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
	const grouped = settings.grouped || [];
	// Registered entry blocks: { name: { fixed } }. Without the list, the menu entry.
	const entries = window.pkiwGroupedEntries || null;

	const MENU_ENTRY = 'post-kinds-indieweb/menu-entry';

	function isEntry( name ) {
		return entries
			? Object.prototype.hasOwnProperty.call( entries, name )
			: MENU_ENTRY === name;
	}

	// The first entry block among a block's inner blocks, at any depth short
	// of a nested Query Loop, whose blocks belong to that loop.
	function findEntry( blocks ) {
		for ( const block of blocks || [] ) {
			if ( isEntry( block.name ) ) {
				return block;
			}
			const found =
				'core/query' !== block.name && findEntry( block.innerBlocks );
			if ( found ) {
				return found;
			}
		}
		return null;
	}

	// The REST `orderby` value for a menu's section order and empty-group setting.
	function menuOrder( attributes ) {
		return (
			'pkiw_group' +
			( 'desc' === attributes.sectionOrder ? '_desc' : '' ) +
			( 'first' === attributes.emptyGroup ? '_empty_first' : '' )
		);
	}

	function kindFromTemplateSlug( slug ) {
		return 'string' === typeof slug && 0 === slug.indexOf( PREFIX )
			? slug.slice( PREFIX.length )
			: '';
	}

	// The attributes to write for a Query Loop on a kind template: what the
	// edit asked for, with an inheriting loop's stored perPage and
	// excludeCurrent kept. A query that comes out unchanged is the stored
	// object itself, so the block editor records no change.
	function keepQuery( current, next ) {
		const stored = current && current.query;
		const query = next && next.query;
		if ( ! stored || ! query || ! stored.inherit || ! query.inherit ) {
			return next;
		}
		const kept = Object.assign( {}, query );
		[ 'perPage', 'excludeCurrent' ].forEach( function ( key ) {
			if ( undefined === stored[ key ] ) {
				delete kept[ key ];
			} else {
				kept[ key ] = stored[ key ];
			}
		} );
		const same = Object.keys( Object.assign( {}, stored, kept ) ).every(
			function ( key ) {
				return stored[ key ] === kept[ key ];
			}
		);
		return Object.assign( {}, next, { query: same ? stored : kept } );
	}

	const withKindTemplatePreview = wp.compose.createHigherOrderComponent(
		function ( BlockEdit ) {
			return function ( props ) {
				const context = props.context;
				const loopKind =
					'core/query' === props.name && context
						? kindFromTemplateSlug( context.templateSlug )
						: '';
				const setAttributes = props.setAttributes;
				const attributes = wp.element.useRef( props.attributes );
				attributes.current = props.attributes;
				const keepStoredQuery = wp.element.useCallback(
					function ( next ) {
						setAttributes(
							'function' === typeof next
								? function ( current ) {
										return keepQuery(
											current,
											next( current )
										);
									}
								: keepQuery( attributes.current, next )
						);
					},
					[ setAttributes ]
				);
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
				// The grouping settings live on the entry block inside this Post Template.
				const clientId = props.clientId;
				const entry = wp.data.useSelect(
					function ( select ) {
						const editor = kind
							? select( 'core/block-editor' )
							: null;
						return editor && editor.getBlocks
							? findEntry( editor.getBlocks( clientId ) )
							: null;
					},
					[ kind, clientId ]
				);
				const sitePerPage = wp.data.useSelect(
					function ( select ) {
						const editorSettings = kind
							? select( 'core/block-editor' ).getSettings()
							: null;
						return (
							( editorSettings && editorSettings.postsPerPage ) ||
							0
						);
					},
					[ kind ]
				);
				const menu = entry ? entry.attributes : null;
				const fixed = !! (
					entry &&
					entries &&
					entries[ entry.name ] &&
					entries[ entry.name ].fixed
				);
				const lines =
					( menu && menu.linesPerPage > 0 && menu.linesPerPage ) ||
					perPage[ kind ] ||
					sitePerPage ||
					0;
				const order =
					! fixed && -1 !== grouped.indexOf( kind )
						? menuOrder( menu || {} )
						: '';
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
								lines ? { perPage: lines } : {},
								// A grouped kind comes back in grouped order: group, date, ID.
								order ? { orderBy: order } : {},
								// Date buckets need newest first.
								fixed ? { orderBy: 'date', order: 'desc' } : {}
							),
						} );
					},
					[ context, query, termId, lines, order, fixed ]
				);

				let blockProps = props;
				if ( loopKind ) {
					blockProps = Object.assign( {}, props, {
						setAttributes: keepStoredQuery,
					} );
				} else if ( preview ) {
					blockProps = Object.assign( {}, props, {
						context: preview,
					} );
				}

				return el( BlockEdit, blockProps );
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
