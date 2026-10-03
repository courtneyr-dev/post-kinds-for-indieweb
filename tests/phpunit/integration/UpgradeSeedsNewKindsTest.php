<?php
/**
 * Upgrade path coverage for the default kind terms.
 *
 * `maybe_create_default_terms()` was guarded by a boolean option set once
 * on first activation, so the seeder never ran again. WordPress does not
 * fire the activation hook on update, which meant every kind added after
 * a site's first install — the twelve added between 1.0.0 and 1.5.0 —
 * never got a term on existing sites. Those users saw 24 kinds while the
 * readme advertised 36, with no picker entries and no archives for the
 * missing ones.
 *
 * Reproduced against the real published builds: a site installed on
 * 1.0.0 and upgraded to 1.5.0 reported 24 kinds; clearing the flag
 * yielded 36, confirming the seeder itself is idempotent and correct.
 *
 * @group integration
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * Verifies newly-added kinds reach sites that upgrade rather than install fresh.
 */
final class UpgradeSeedsNewKindsTest extends WP_UnitTestCase {

	/**
	 * Every kind the plugin declares must exist as a term.
	 */
	private function assert_all_kinds_present( string $context ): void {
		$taxonomy = new Taxonomy();
		$declared = array_keys( apply_filters( 'pkiw_default_kinds', $this->declared_kinds( $taxonomy ) ) );

		$existing = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'slugs',
			]
		);
		$this->assertNotWPError( $existing );

		$missing = array_values( array_diff( $declared, (array) $existing ) );

		$this->assertSame(
			[],
			$missing,
			$context . ' — kinds declared by the plugin but absent as terms: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Read the declared kind list off the taxonomy instance.
	 *
	 * @param Taxonomy $taxonomy Taxonomy instance.
	 * @return array<string, array<string, string>> Declared kinds.
	 */
	private function declared_kinds( Taxonomy $taxonomy ): array {
		$reflection = new ReflectionProperty( Taxonomy::class, 'default_kinds' );
		$reflection->setAccessible( true );

		return (array) $reflection->getValue( $taxonomy );
	}

	/**
	 * A site that already seeded terms under an older version still gets
	 * kinds added since — the upgrade case.
	 */
	public function test_upgrade_from_older_version_seeds_newly_added_kinds(): void {
		$taxonomy = new Taxonomy();

		// Simulate a site seeded by an older release: terms exist for the
		// kinds that version knew about, and the guard is already set.
		$taxonomy->create_default_terms();

		$legacy_only = [ 'audio', 'quote', 'weather', 'follow', 'sleep', 'craft' ];
		foreach ( $legacy_only as $slug ) {
			$term = get_term_by( 'slug', $slug, Taxonomy::TAXONOMY );
			if ( $term instanceof WP_Term ) {
				wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );
			}
		}

		// The pre-1.5.1 guard: a bare boolean, as written by the old code.
		update_option( 'pkiw_terms_created', true );

		$this->assertNotEmpty(
			array_diff(
				$legacy_only,
				(array) get_terms(
					[
						'taxonomy'   => Taxonomy::TAXONOMY,
						'hide_empty' => false,
						'fields'     => 'slugs',
					]
				)
			),
			'Fixture failed: the legacy-only kinds should be missing before the upgrade runs.'
		);

		// The upgrade: init fires and the seeder gets its chance.
		$taxonomy->maybe_create_default_terms();

		$this->assert_all_kinds_present( 'After upgrading from a site seeded by an older version' );
	}

	/**
	 * The guard still prevents redundant work once the current version has seeded.
	 */
	public function test_seeder_does_not_repeat_for_the_current_version(): void {
		$taxonomy = new Taxonomy();

		$taxonomy->maybe_create_default_terms();
		$stamp = get_option( 'pkiw_terms_created' );

		$this->assertSame(
			PKIW_VERSION,
			$stamp,
			'The guard should record the plugin version that seeded, not a bare boolean.'
		);

		// A second pass in the same version must be a no-op, not a re-seed.
		$before = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$taxonomy->maybe_create_default_terms();
		$after = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		$this->assertSame( count( (array) $before ), count( (array) $after ), 'Re-running created duplicate terms.' );
		$this->assertSame( $stamp, get_option( 'pkiw_terms_created' ), 'The version stamp changed unexpectedly.' );
	}

	/**
	 * A fresh install gets the full vocabulary.
	 */
	public function test_fresh_install_seeds_every_declared_kind(): void {
		delete_option( 'pkiw_terms_created' );

		$taxonomy = new Taxonomy();
		$taxonomy->maybe_create_default_terms();

		$this->assert_all_kinds_present( 'On a fresh install' );
	}

	/**
	 * The comics kind covers a comic someone read and a comic they made.
	 *
	 * The archive prints this description above every comics post, so a
	 * description naming only authored strips excludes the comic reads
	 * listed under it.
	 */
	public function test_comics_description_covers_read_and_authored_comics(): void {
		$description = $this->declared_kinds( new Taxonomy() )['comics']['description'];

		$this->assertSame( 'A comic you read, drew, or published.', $description );
		$this->assertMatchesRegularExpression( '/\bread\b/', $description, 'A comic read is not covered.' );
		$this->assertMatchesRegularExpression( '/\bdrew\b/', $description, 'A comic its author drew is not covered.' );
		$this->assertMatchesRegularExpression( '/\bpublished\b/', $description, 'A published comic is not covered.' );
	}

	/**
	 * A stored description still equal to a default this plugin shipped
	 * earlier was never edited, so it follows the current default.
	 *
	 * @dataProvider superseded_comics_descriptions
	 *
	 * @param string $shipped A comics description an earlier build seeded.
	 */
	public function test_unedited_comics_description_follows_the_current_default( string $shipped ): void {
		$taxonomy = new Taxonomy();
		$taxonomy->create_default_terms();
		$term = get_term_by( 'slug', 'comics', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		wp_update_term( $term->term_id, Taxonomy::TAXONOMY, [ 'description' => $shipped ] );
		delete_option( Taxonomy::DESCRIPTIONS_OPTION );

		$taxonomy->maybe_refresh_default_descriptions();

		$this->assertSame(
			'A comic you read, drew, or published.',
			get_term_by( 'slug', 'comics', Taxonomy::TAXONOMY )->description
		);
	}

	/**
	 * Comics descriptions seeded by earlier builds.
	 *
	 * @return array<string, array{string}>
	 */
	public function superseded_comics_descriptions(): array {
		return [
			'authored only, through 1.8.6' => [ 'A comic strip or panel you drew or published.' ],
			'first reworded default'       => [ 'A comic you read, or a comic strip or panel you drew or published.' ],
		];
	}

	/**
	 * A description the site wrote itself is never replaced.
	 */
	public function test_edited_comics_description_is_kept(): void {
		$taxonomy = new Taxonomy();
		$taxonomy->create_default_terms();
		$term = get_term_by( 'slug', 'comics', Taxonomy::TAXONOMY );
		wp_update_term( $term->term_id, Taxonomy::TAXONOMY, [ 'description' => 'Strips from my sketchbook.' ] );
		delete_option( Taxonomy::DESCRIPTIONS_OPTION );

		$taxonomy->maybe_refresh_default_descriptions();

		$this->assertSame( 'Strips from my sketchbook.', get_term_by( 'slug', 'comics', Taxonomy::TAXONOMY )->description );
	}

	/**
	 * The refresh runs once per revision, so a description edited back to
	 * an old default afterwards stays as the site left it.
	 */
	public function test_description_refresh_runs_once_per_revision(): void {
		$taxonomy = new Taxonomy();
		$taxonomy->create_default_terms();
		delete_option( Taxonomy::DESCRIPTIONS_OPTION );
		$taxonomy->maybe_refresh_default_descriptions();

		$term = get_term_by( 'slug', 'comics', Taxonomy::TAXONOMY );
		wp_update_term( $term->term_id, Taxonomy::TAXONOMY, [ 'description' => 'A comic strip or panel you drew or published.' ] );
		$taxonomy->maybe_refresh_default_descriptions();

		$this->assertSame(
			'A comic strip or panel you drew or published.',
			get_term_by( 'slug', 'comics', Taxonomy::TAXONOMY )->description
		);
	}
}
