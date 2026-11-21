<?php
/**
 * The public API the courtneyr-child theme probes for the mixed-kind label.
 *
 * Loads the theme's own `inc/archive-checkin-label.php` against the real
 * plugin inside a served archive. Set PKIW_TESTS_CHILD_THEME to a
 * courtneyr-child checkout; the test skips without it.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Contract between PKIW's archive seam and the theme's label helper.
 *
 * @group integration
 */
final class MixedKindThemeContractTest extends WP_UnitTestCase {

	/**
	 * Label classes the theme prints, by kind.
	 *
	 * @var array<string, string>
	 */
	private const CLASSES = [
		'like'     => 'cr-like-sticker__checkin',
		'repost'   => 'cr-repost-clip__checkin',
		'favorite' => 'cr-fav-clipping__checkin',
		'jam'      => 'cr-jam-strip__checkin',
	];

	/**
	 * Load the theme helper and register the request environment.
	 */
	public function set_up(): void {
		parent::set_up();
		$dir = (string) getenv( 'PKIW_TESTS_CHILD_THEME' );
		if ( '' === $dir || ! is_file( $dir . '/inc/archive-checkin-label.php' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_CHILD_THEME to a courtneyr-child checkout.' );
		}
		require_once $dir . '/inc/archive-checkin-label.php';
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'blocked', 'No network in tests.' ) );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	/**
	 * Restore URL and cookie state.
	 */
	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_every_symbol_the_theme_probes_exists_with_the_shape_it_expects(): void {
		$slug = new ReflectionFunction( 'PKIW\\mixed_kind_archive_slug' );
		$this->assertSame( [ 'post', 'instance' ], array_map( static fn( $p ) => $p->getName(), $slug->getParameters() ) );
		$this->assertSame( 'WP_Post', (string) $slug->getParameters()[0]->getType() );
		$this->assertSame( 'string', (string) $slug->getReturnType() );

		$facts = new ReflectionMethod( Kind_Facts::class, 'get_for_archive_kind' );
		$this->assertTrue( $facts->isPublic() && $facts->isStatic() );
		$this->assertSame( [ 'int', 'string' ], array_map( static fn( $p ) => (string) $p->getType(), $facts->getParameters() ) );
		$this->assertSame( 'array', (string) $facts->getReturnType() );

		foreach ( [ 'current_archive_kind' => 'string', 'current_page_ids' => 'array' ] as $method => $returns ) {
			$reflection = new ReflectionMethod( Grouped_Archive::class, $method );
			$this->assertTrue( $reflection->isPublic() && $reflection->isStatic(), $method );
			$this->assertSame( 0, $reflection->getNumberOfRequiredParameters(), $method . ' takes no required argument' );
			$this->assertSame( $returns, (string) $reflection->getReturnType(), $method );
		}
		foreach ( [ 'hides', 'is_eligible' ] as $method ) {
			$this->assertTrue( method_exists( PKIW\Public_Eligibility::class, $method ), $method );
		}
		$this->assertTrue( method_exists( Meta_Fields::class, 'get_public_location_fields' ) );
		$this->assertTrue( function_exists( 'PKIW\\visitor_post_title' ) && function_exists( 'PKIW\\untitled_name' ) );
		$this->assertTrue( function_exists( 'PKIW\\stream_card_kind_cite' ) );
		$this->assertSame( 3, ( new ReflectionFunction( 'PKIW\\stream_card_kind_cite' ) )->getNumberOfParameters(), 'post, facts, kind' );
	}

	/**
	 * Per-kind label outcomes by state: meta, post args, cookie, expected label count.
	 *
	 * @return array<string, array{0: array<string, string>, 1: array<string, mixed>, 2: bool, 3: int}>
	 */
	public function states(): array {
		return [
			'public'          => [ [ '_pkiw_geo_privacy' => 'public' ], [], false, 1 ],
			'approximate'     => [ [ '_pkiw_geo_privacy' => 'approximate' ], [], false, 1 ],
			'pkiw private'    => [ [ '_pkiw_geo_privacy' => 'private' ], [], false, 0 ],
			'sl private'      => [
				[
					'_pkiw_geo_privacy' => 'public',
					'geo_public'        => '0',
				],
				[],
				false,
				0,
			],
			'password, valid' => [ [ '_pkiw_geo_privacy' => 'public' ], [ 'post_password' => 'secret' ], true, 0 ],
			'password, locked' => [ [ '_pkiw_geo_privacy' => 'public' ], [ 'post_password' => 'secret' ], false, 0 ],
		];
	}

	/**
	 * @dataProvider states
	 *
	 * @param array<string, string> $meta   Post meta.
	 * @param array<string, mixed>  $args   Post arguments.
	 * @param bool                  $cookie Whether a valid password cookie is sent.
	 * @param int                   $labels Expected label count.
	 */
	public function test_the_theme_helper_reads_the_real_seam( array $meta, array $args, bool $cookie, int $labels ): void {
		require_once ABSPATH . WPINC . '/class-phpass.php';
		foreach ( self::CLASSES as $kind => $class ) {
			$id = wp_insert_post(
				array_merge(
					[
						'post_status'  => 'publish',
						'post_title'   => 'Contract ' . $kind,
						'post_name'    => 'contract-' . $kind,
						'post_date'    => '2026-08-20 10:00:00',
						'post_content' => '<!-- wp:post-kinds-indieweb/' . $kind . '-card {"title":"A Cited Page","url":"https://target.example.test/cited"} /--><!-- wp:post-kinds-indieweb/checkin-card {"venueName":"Contract Venue"} /-->',
					],
					$args
				)
			);
			wp_set_object_terms( $id, [ $kind, 'checkin' ], Taxonomy::TAXONOMY );
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
			if ( $cookie ) {
				$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'secret' );
			}
			$seen = [];
			$grab = static function ( $html, $block, $instance ) use ( &$seen, $kind, $id ) {
				$post = get_post( $id );
				if ( $instance instanceof WP_Block && (int) ( $instance->context['postId'] ?? 0 ) === $id ) {
					$seen = [
						'selected' => Courtneyr\Child\ArchiveCheckin\selected( $post, $instance, $kind ),
						'label'    => Courtneyr\Child\ArchiveCheckin\label( $post, $instance, $kind, (string) $html ),
						'pages'    => Courtneyr\Child\ArchiveCheckin\page_ids( $id, $instance, $kind ),
						'peer'     => Courtneyr\Child\ArchiveCheckin\peer_facts( $id, $kind ),
						'wrong'    => PKIW\mixed_kind_archive_slug( $post, $instance ),
					];
				}
				return $html;
			};
			add_filter( 'render_block_post-kinds-indieweb/stream-card', $grab, 100, 3 );

			$this->serve( $kind );

			remove_filter( 'render_block_post-kinds-indieweb/stream-card', $grab, 100 );
			$this->assertNotSame( [], $seen, $kind . ' row reached the theme filter' );
			$this->assertTrue( $seen['selected'], $kind . ' selected through the real seam' );
			$this->assertSame( $kind, $seen['wrong'] );
			$this->assertSame( $labels > 0 ? '<span class="' . $class . '">Also a check-in</span>' : '', $seen['label'], $kind . ' label' );
			$this->assertContains( $id, $seen['pages'], $kind . ' page map includes the row' );
			$this->assertIsArray( $seen['peer'] );
			unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		}
	}

	/**
	 * Serve a kind archive.
	 *
	 * @param string $kind Kind slug.
	 */
	private function serve( string $kind ): string {
		if ( 'like' === $kind ) {
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'wp_template',
					'post_name'    => 'taxonomy-kind-like',
					'post_title'   => 'Test like',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:query {"queryId":0,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/archive-sections {"linesPerPage":12} /--><!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /--><!-- /wp:post-template --></div><!-- /wp:query -->',
				]
			);
			wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );
		}
		$this->go_to( (string) get_term_link( $kind, Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-' . $kind . '.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}
}
