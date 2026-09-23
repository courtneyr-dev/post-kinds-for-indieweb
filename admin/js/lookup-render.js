/**
 * Post Kinds for IndieWeb in Block Themes - Lookup/search result rendering
 *
 * Builds the DOM for a single media lookup or quick-search result from an
 * external API's response item. Every text field is written with
 * `textContent`/`createTextNode`, never concatenated into an HTML string,
 * so a malicious title such as `<img src=x onerror=...>` renders as
 * literal text instead of being parsed as markup (finding K2).
 *
 * Loaded as a plain script (no build step, matching admin.js), so this
 * exposes `PKIWLookupRender` as a global; it also exports via
 * `module.exports` when required from Node/Jest.
 *
 * @param {Object}   root    Global object to attach the export to in a browser.
 * @param {Function} factory Returns the module's exports.
 * @package
 * @since 1.8.6
 */

( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.PKIWLookupRender = factory();
	}
} )( typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	/**
	 * Build a `.lookup-result` element for the media-lookup panel.
	 *
	 * Markup matches the meta-box lookup panel:
	 * `<div class="lookup-result"><strong>TITLE</strong>[<br>ARTIST][ (YEAR)]</div>`
	 *
	 * @param {Object} item Result item from the lookup API (title/name, artist, year).
	 * @return {HTMLElement} The result element. The caller is responsible for
	 *                       attaching `item` as data (e.g. `$(el).data('item', item)`)
	 *                       and appending it to the results container.
	 */
	function buildLookupResultItem( item ) {
		const el = document.createElement( 'div' );
		el.className = 'lookup-result';

		const title = document.createElement( 'strong' );
		title.textContent = item.title || item.name || '';
		el.appendChild( title );

		if ( item.artist ) {
			el.appendChild( document.createElement( 'br' ) );
			el.appendChild( document.createTextNode( item.artist ) );
		}

		if ( item.year ) {
			el.appendChild( document.createTextNode( ' (' + item.year + ')' ) );
		}

		return el;
	}

	/**
	 * Build a `.search-result-item` element for the quick-post search panel.
	 *
	 * Markup matches the quick-post search panel:
	 * `<div class="search-result-item">[<img>]<div class="search-result-info">
	 *   <div class="search-result-title">TITLE</div>
	 *   <div class="search-result-subtitle">ARTIST[ - ]YEARAUTHOR</div>
	 * </div></div>`
	 *
	 * @param {Object} item Result item from the lookup API (title/name, artist,
	 *                      year, author, image/cover).
	 * @return {HTMLElement} The result element. The caller is responsible for
	 *                       attaching `item` as data (e.g. `$(el).data('item', item)`)
	 *                       and appending it to the results container.
	 */
	function buildSearchResultItem( item ) {
		const el = document.createElement( 'div' );
		el.className = 'search-result-item';

		const imageUrl = item.image || item.cover;
		if ( imageUrl ) {
			const img = document.createElement( 'img' );
			img.src = imageUrl;
			img.alt = '';
			el.appendChild( img );
		}

		const info = document.createElement( 'div' );
		info.className = 'search-result-info';

		const title = document.createElement( 'div' );
		title.className = 'search-result-title';
		title.textContent = item.title || item.name || '';
		info.appendChild( title );

		const subtitle = document.createElement( 'div' );
		subtitle.className = 'search-result-subtitle';
		let subtitleText = '';
		if ( item.artist ) {
			subtitleText += item.artist;
		}
		if ( item.year ) {
			subtitleText += ( item.artist ? ' - ' : '' ) + item.year;
		}
		if ( item.author ) {
			subtitleText += item.author;
		}
		subtitle.textContent = subtitleText;
		info.appendChild( subtitle );

		el.appendChild( info );

		return el;
	}

	return {
		buildLookupResultItem,
		buildSearchResultItem,
	};
} );
