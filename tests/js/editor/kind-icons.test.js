/**
 * Tests for the filterable kind-icon resolver.
 */
import { addFilter, removeFilter } from '@wordpress/hooks';
import {
	getKindIcon,
	kindIcons,
} from '../../../src/editor/kind-selector/icons';

describe( 'getKindIcon', () => {
	afterEach( () => {
		removeFilter( 'postKindsIndieweb.kindIcons', 'test/custom' );
	} );

	it( 'returns the built-in icon for a registered kind', () => {
		expect( getKindIcon( 'watch' ) ).toBe( kindIcons.watch );
	} );

	it( 'falls back to the note icon for unknown slugs', () => {
		expect( getKindIcon( 'zine-club' ) ).toBe( kindIcons.note );
	} );

	it( 'lets a filter register icons for custom kind terms', () => {
		const ZineClubIcon = () => null;
		addFilter(
			'postKindsIndieweb.kindIcons',
			'test/custom',
			( icons ) => ( {
				...icons,
				'zine-club': ZineClubIcon,
			} )
		);
		expect( getKindIcon( 'zine-club' ) ).toBe( ZineClubIcon );
		// Built-ins are untouched by an additive filter.
		expect( getKindIcon( 'watch' ) ).toBe( kindIcons.watch );
	} );

	it( 'lets a filter override a built-in icon', () => {
		const Custom = () => null;
		addFilter(
			'postKindsIndieweb.kindIcons',
			'test/custom',
			( icons ) => ( {
				...icons,
				watch: Custom,
			} )
		);
		expect( getKindIcon( 'watch' ) ).toBe( Custom );
	} );

	it( 'does not let a filter mutate the canonical map', () => {
		addFilter( 'postKindsIndieweb.kindIcons', 'test/custom', ( icons ) => {
			icons.note = null;
			return icons;
		} );
		getKindIcon( 'anything' );
		expect( kindIcons.note ).not.toBeNull();
	} );
} );
