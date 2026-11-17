<?php
/** Zine print-view tests. @package PKIW */
declare(strict_types=1);

use PKIW\Zine_Export;
use PKIW\Zine_Manifest;
use PKIW\Zine_Request;
use PKIW\Block_Bindings;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

require_once __DIR__ . '/ZineTestCase.php';

/** @group integration */
final class ZinePrintViewTest extends ZineTestCase {
	public function test_sixteen_page_manifest_keeps_dom_reading_order(): void {
		for ( $index = 1; $index <= 14; $index++ ) {
			$this->post( sprintf( 'Entry %02d', $index ), [ 'post_date' => sprintf( '2025-03-%02d 12:00:00', $index ) ] );
		}
		$manifest = $this->manifest( $this->archive_query( 16 ), 16 );
		$this->assertSame( range( 3, 16 ), array_column( $manifest['entries'], 'page' ) );
	}

	public function test_booklet_structure_has_one_h1_contents_and_slot_attributes(): void {
		$this->post( 'Printable entry' );
		$this->go_to( add_query_arg( 'pkiw_zine_pages', '8', get_term_link( $this->recipe ) ) );
		do_action( 'wp' );
		$html = Zine_Export::render_booklet();
		$this->assertSame( 1, substr_count( $html, '<h1 class="pkiw-zine__title">' ) );
		$this->assertStringContainsString( '<h2>Contents</h2>', $html );
		$this->assertSame( 8, substr_count( $html, 'data-pkiw-zine-page=' ) );
		$this->assertStringContainsString( 'Back to Recipe', $html );
	}

	public function test_full_document_has_one_title_main_h1_and_clean_back_link(): void {
		$this->post( 'Printable entry' );
		$document = $this->render_document( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) );
		$this->assertSame( $this->print_template(), $document['template'] );
		$html = $document['html'];
		$this->assertStringContainsString( '<html lang=', $html );
		$this->assertSame( 1, substr_count( $html, '<title>' ) );
		$this->assertSame( 1, substr_count( $html, '<main>' ) );
		$this->assertSame( 1, substr_count( $html, '<h1 class="pkiw-zine__title">' ) );
		$dom = new DOMDocument();
		$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $dom );
		$link = $xpath->query( '//a[normalize-space(.)="Back to Recipe"]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );
		$href = $link->getAttribute( 'href' );
		$this->assertSame( $this->archive_url(), $href );
		$this->assertStringNotContainsString( 'pkiw_zine_', (string) $href );
	}

	public function test_admin_and_logged_out_full_documents_are_identical_for_archive_and_stream(): void {
		$this->post( 'Anonymous rendering control' );
		$page_id = $this->stream_page();
		$admin   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$urls    = [
			$this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ),
			add_query_arg( Zine_Request::VAR_PAGES, 8, get_permalink( $page_id ) ),
		];
		foreach ( $urls as $url ) {
			$admin_document = $this->render_document( $url, $admin );
			$public_document = $this->render_document( $url, 0 );
			$this->assertSame( $this->print_template(), $admin_document['template'] );
			$this->assertSame( $public_document['html'], $admin_document['html'] );
			$this->assertStringNotContainsString( 'admin-bar', $admin_document['html'] );
			$this->assertStringNotContainsString( 'logged-in', $admin_document['html'] );
		}
	}

	public function test_archive_main_query_keeps_wp_post_objects_for_papc_shape(): void {
		for ( $index = 1; $index <= 14; $index++ ) {
			$this->post( sprintf( 'PAPC %02d', $index ), [ 'post_date' => sprintf( '2025-02-%02d 12:00:00', $index ) ] );
		}
		$errors = [];
		$seen   = [];
		$papc   = static function () use ( &$seen ): void {
			foreach ( $GLOBALS['wp_query']->posts as $post ) {
				$seen[] = [ $post->ID, $post->post_type ];
			}
		};
		add_action( 'wp', $papc, 10 );
		set_error_handler(
			static function ( int $severity, string $message ) use ( &$errors ): bool {
				if ( E_WARNING === $severity || E_NOTICE === $severity || E_USER_WARNING === $severity || E_USER_NOTICE === $severity ) {
					$errors[] = $message;
				}
				return true;
			}
		);
		try {
			foreach ( [ 8, 16 ] as $pages ) {
				$this->go_to( $this->archive_url( [ Zine_Request::VAR_PAGES => $pages ] ) );
				foreach ( $GLOBALS['wp_query']->posts as $post ) {
					$this->assertInstanceOf( WP_Post::class, $post );
				}
			}
		} finally {
			restore_error_handler();
			remove_action( 'wp', $papc, 10 );
		}
		$this->assertNotEmpty( $seen );
		$this->assertSame( [], $errors );
	}

	public function test_robots_header_and_nocache_run_through_hooks_for_every_zine_var_shape(): void {
		$this->assertSame( 0, has_action( 'template_redirect', [ Zine_Export::class, 'send_privacy_headers' ] ) );
		$this->assertNotFalse( has_filter( 'wp_robots', [ Zine_Export::class, 'robots' ] ) );
		$headers = [];
		Zine_Export::override_header_sink_for_tests( static function ( string $line ) use ( &$headers ): void { $headers[] = $line; } );
		$cases = [
			'valid'     => [ Zine_Request::VAR_PAGES => 8 ],
			'error'     => [ Zine_Request::VAR_PAGES => 8, Zine_Request::VAR_FROM => 'banana' ],
			'from only' => [ Zine_Request::VAR_FROM => '2025-01-01' ],
		];
		foreach ( $cases as $label => $vars ) {
			$headers = [];
			$this->go_to( $this->archive_url( $vars ) );
			do_action( 'template_redirect' );
			$robots = apply_filters( 'wp_robots', [] );
			$this->assertContains( 'X-Robots-Tag: noindex', $headers, $label );
			$this->assertNotEmpty( array_filter( $headers, static fn( string $line ): bool => str_starts_with( $line, 'Cache-Control:' ) && str_contains( $line, 'no-store' ) ), $label );
			$this->assertArrayHasKey( 'noindex', $robots, $label );
		}
		$headers = [];
		$this->go_to( $this->archive_url() );
		do_action( 'template_redirect' );
		$this->assertSame( [], $headers );
		$this->assertArrayNotHasKey( 'noindex', apply_filters( 'wp_robots', [] ) );
	}

	public function test_non_stream_source_page_never_enters_print_mode(): void {
		$this->post( 'Archive source sentinel Qx7' );
		$page_id = $this->stream_page( [], 'archive' );
		$this->go_to( add_query_arg( Zine_Request::VAR_PAGES, 8, get_permalink( $page_id ) ) );
		$template = apply_filters( 'template_include', 'theme-template.php' );
		$this->assertNotSame( $this->print_template(), $template );
		$this->assertNotContains( 'pkiw-zine-print', get_body_class() );
	}

	/** @dataProvider gated_page_cases */
	public function test_private_draft_and_password_pages_never_enter_print_mode( string $status, string $password, bool $logged_out_404 ): void {
		$this->post( 'Sentinel Gated Listed Post Qx7' );
		$page_id = $this->stream_page( [ 'post_status' => $status, 'post_password' => $password, 'post_name' => 'gated-' . $status . ( '' === $password ? '' : '-password' ), 'post_title' => 'Sentinel Gated Page Qx7' ] );
		$admin   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		foreach ( [ 0, $admin ] as $user_id ) {
			wp_set_current_user( $user_id );
			$this->go_to( add_query_arg( Zine_Request::VAR_PAGES, 8, get_permalink( $page_id ) ) );
			$this->assertNotSame( $this->print_template(), apply_filters( 'template_include', 'theme-template.php' ) );
			$this->assertSame( '', Zine_Export::render_booklet() );
			$this->assertNotContains( 'pkiw-zine-print', get_body_class() );
			$this->assertStringNotContainsString( 'Sentinel Gated Listed Post Qx7', Zine_Export::render_booklet() );
			$this->assertStringNotContainsString( 'Sentinel Gated Page Qx7', Zine_Export::render_booklet() );
			if ( 0 === $user_id ) {
				$this->assertSame( $logged_out_404, is_404() );
				if ( '' !== $password ) {
					$this->assertTrue( post_password_required( $page_id ) );
				}
			}
		}
	}

	public static function gated_page_cases(): array {
		return [
			'private'  => [ 'private', '', true ],
			'draft'    => [ 'draft', '', true ],
			'password' => [ 'publish', 'secret', false ],
		];
	}

	public function test_entry_renderer_skips_cross_post_blocks_and_forbidden_elements(): void {
		self::factory()->post->create( [ 'post_title' => 'Other title', 'post_status' => 'publish', 'post_date' => '2025-03-08 12:00:00' ] );
		$this->post( 'Context entry', [ 'post_content' => '<!-- wp:latest-posts /--><!-- wp:query {"query":{"perPage":1,"postType":"post","inherit":false}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query --><!-- wp:html --><iframe src="https://qx7.example/"></iframe><script>alert(1)</script><!-- /wp:html -->' ] );
		$this->go_to( add_query_arg( 'pkiw_zine_pages', '8', get_term_link( $this->recipe ) ) );
		do_action( 'wp' );
		$html = Zine_Export::render_booklet();
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 0, substr_count( $html, 'Other title' ), 'The non-zine post must not leak through core/latest-posts.' );
	}

	public function test_entry_block_context_keeps_each_venue_and_cite_with_its_post(): void {
		$binding = static fn( string $key, string $fallback ): string => '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"' . Block_Bindings::SOURCE_NAME . '","args":{"key":"' . $key . '"}}}}} --><p>' . $fallback . '</p><!-- /wp:paragraph -->';
		$first   = $this->post( 'First context check-in', [ 'post_date' => '2025-03-09 12:00:00', 'post_content' => $binding( 'checkin_name', 'fallback first' ) ], 'checkin' );
		$second  = $this->post( 'Second context check-in', [ 'post_date' => '2025-03-08 12:00:00', 'post_content' => $binding( 'checkin_name', 'fallback second' ) ], 'checkin' );
		$read    = $this->post( 'Read context entry', [ 'post_date' => '2025-03-07 12:00:00', 'post_content' => $binding( 'cite_name', 'fallback cite' ) ], 'read' );
		update_post_meta( $first, Meta_Fields::PREFIX . 'checkin_name', 'Sentinel Venue Alpha Qx7' );
		update_post_meta( $first, Meta_Fields::PREFIX . 'geo_privacy', 'public' );
		update_post_meta( $second, Meta_Fields::PREFIX . 'checkin_name', 'Sentinel Venue Beta Qx7' );
		update_post_meta( $second, Meta_Fields::PREFIX . 'geo_privacy', 'public' );
		update_post_meta( $read, Meta_Fields::PREFIX . 'cite_name', 'Sentinel Cite Gamma Qx7' );
		$page_id  = $this->stream_page();
		$url      = add_query_arg( Zine_Request::VAR_PAGES, 8, get_permalink( $page_id ) );
		$document = $this->render_document( $url );
		$first_section  = $this->entry_section_text( $document['html'], 'First context check-in' );
		$second_section = $this->entry_section_text( $document['html'], 'Second context check-in' );
		$read_section   = $this->entry_section_text( $document['html'], 'Read context entry' );
		$this->assertStringContainsString( 'Sentinel Venue Alpha Qx7', $first_section );
		$this->assertStringNotContainsString( 'Sentinel Venue Beta Qx7', $first_section );
		$this->assertStringContainsString( 'Sentinel Venue Beta Qx7', $second_section );
		$this->assertStringNotContainsString( 'Sentinel Venue Alpha Qx7', $second_section );
		$this->assertStringContainsString( 'Sentinel Cite Gamma Qx7', $read_section );

		// Negative control (A9): a recorded mutation run, not a production seam (OX-14 precedent). With the
		// entry renderer's postId context and setup_postdata() removed, the assertions above fail.
	}

	public function test_inner_loop_reset_keeps_following_block_on_the_entry(): void {
		$other = $this->post( 'Inner loop other post' );
		$entry = $this->post( 'Inner loop owner', [ 'post_content' => '<!-- wp:pkiw-test/inner-loop /--><!-- wp:pkiw-test/id-marker /-->' ] );
		register_block_type(
			'pkiw-test/inner-loop',
			[
				'render_callback' => static function () use ( $other ): string {
					$query = new WP_Query( [ 'p' => $other ] );
					while ( $query->have_posts() ) {
						$query->the_post();
					}
					wp_reset_postdata();
					return '<span>inner loop complete</span>';
				},
			]
		);
		register_block_type(
			'pkiw-test/id-marker',
			[ 'render_callback' => static fn(): string => '<span>ID-MARK-' . get_the_ID() . '-QX7</span>' ]
		);
		try {
			$html = $this->render_document( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) )['html'];
		} finally {
			unregister_block_type( 'pkiw-test/inner-loop' );
			unregister_block_type( 'pkiw-test/id-marker' );
		}
		$this->assertStringContainsString( 'ID-MARK-' . $entry . '-QX7', $this->entry_section_text( $html, 'Inner loop owner' ) );
	}

	public function test_non_kind_post_type_keeps_shell_and_omits_body(): void {
		register_post_type( 'pkiw_story', [ 'public' => true, 'show_ui' => false, 'supports' => [ 'title', 'editor' ] ] );
		$story = self::factory()->post->create( [ 'post_type' => 'pkiw_story', 'post_status' => 'publish', 'post_title' => 'Sentinel Story Heading Qx7', 'post_content' => 'Sentinel Story Body Qx7', 'post_date' => '2025-03-09 12:00:00' ] );
		$content = '<!-- wp:post-kinds-indieweb/zine-export {"source":"stream"} /--><!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"pkiw_story","order":"desc","orderBy":"date","inherit":false}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->';
		$page_id   = $this->stream_page( [ 'post_content' => $content ] );
		$permalink = get_permalink( $story );
		try {
			$html = $this->render_document( add_query_arg( Zine_Request::VAR_PAGES, 8, get_permalink( $page_id ) ) )['html'];
		} finally {
			unregister_post_type( 'pkiw_story' );
		}
		$section = $this->entry_section_text( $html, 'Sentinel Story Heading Qx7' );
		$this->assertStringContainsString( '2025-03-09', $section );
		$this->assertStringContainsString( $permalink, $section );
		$this->assertStringNotContainsString( 'Sentinel Story Body Qx7', $section );
	}

	public function test_footer_observes_the_main_query_post_after_booklet_rendering(): void {
		$id   = $this->post( 'Footer context owner' );
		$seen = null;
		$spy  = static function () use ( &$seen ): void { $seen = get_the_ID(); };
		add_action( 'wp_footer', $spy, 999 );
		try {
			$this->render_document( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) );
		} finally {
			remove_action( 'wp_footer', $spy, 999 );
		}
		$this->assertSame( $id, $seen );
	}

	public function test_featured_image_is_printed_once_and_all_images_are_eager(): void {
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$url        = (string) wp_get_attachment_image_url( $attachment, 'large' );
		$post       = $this->post( 'Featured image entry', [ 'post_content' => '<!-- wp:image {"id":' . $attachment . '} --><figure class="wp-block-image"><img src="' . esc_url( $url ) . '" alt="Canola fixture" class="wp-image-' . $attachment . '"/></figure><!-- /wp:image -->' ] );
		set_post_thumbnail( $post, $attachment );
		$html = $this->render_document( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) )['html'];
		$tags = new WP_HTML_Tag_Processor( $html );
		$images = 0;
		$matching_sources = 0;
		while ( $tags->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
			++$images;
			$this->assertSame( 'eager', $tags->get_attribute( 'loading' ) );
			if ( esc_url( $url ) === $tags->get_attribute( 'src' ) ) {
				++$matching_sources;
			}
		}
		$this->assertGreaterThan( 0, $images );
		$this->assertSame( 1, $matching_sources );
	}

	public function test_forbidden_media_and_enqueued_scripts_are_absent_from_full_document(): void {
		$content = '<!-- wp:html --><iframe src="https://qx7.example/"></iframe><video></video><audio></audio><script>alert(1)</script><object></object><embed><div class="leaflet-container">map</div><img src="https://images.example.test/qx7.jpg" alt="Fixture image"><!-- /wp:html -->';
		$this->post( 'Forbidden elements owner', [ 'post_content' => $content ] );
		wp_enqueue_script( 'pkiw-zine-test-classic', 'https://scripts.example.test/classic.js', [], '1', true );
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_register_script_module( 'pkiw-zine-test-module', 'https://scripts.example.test/module.js', [], '1' );
			wp_enqueue_script_module( 'pkiw-zine-test-module' );
		}
		$html = $this->render_document( $this->archive_url( [ Zine_Request::VAR_PAGES => 8 ] ) )['html'];
		foreach ( [ '<iframe', '<video', '<audio', '<object', '<embed', 'leaflet-container', 'classic.js', 'module.js' ] as $probe ) {
			$this->assertStringNotContainsString( $probe, $html );
		}
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( [ 'tag_name' => 'SCRIPT' ] ) ) {
			$src  = (string) $tags->get_attribute( 'src' );
			$type = (string) $tags->get_attribute( 'type' );
			// Core prints the import map and speculation rules as JSON data from wp_head/wp_footer; neither executes code (spec A9 scope note).
			$this->assertTrue( str_contains( $src, 'zine-print.js' ) || in_array( $type, [ 'importmap', 'speculationrules' ], true ), 'Unexpected script tag: ' . $src . ' type=' . $type );
		}
	}

	public function test_d1_private_visibility_changes_count_and_output_but_not_public_listing(): void {
		$id = $this->post( 'Private visibility' );
		add_post_meta( $id, PKIW\Public_Listing::meta_keys()[0], 'private' );
		Zine_Manifest::override_narrowed_visibility_for_tests( [] );
		$this->assertSame( 1, $this->manifest( $this->archive_query() )['total'] );
		Zine_Manifest::override_narrowed_visibility_for_tests( [ 'private' ] );
		$this->assertSame( 0, $this->manifest( $this->archive_query() )['total'] );
	}

	public function test_stream_query_vars_match_rendered_query_loop_with_empty_global(): void {
		$stream_ids = [];
		$outside_id = 0;
		for ( $index = 1; $index <= 12; $index++ ) {
			$id = $this->post( sprintf( 'Stream %02d', $index ), [ 'post_date' => sprintf( '2025-02-%02d 12:00:00', $index ) ] );
			if ( 12 !== $index ) {
				add_post_meta( $id, '_pkiw_surface', 'stream' );
				$stream_ids[] = $id;
			} else {
				$outside_id = $id;
			}
		}
		$content = '<!-- wp:post-kinds-indieweb/zine-export {"source":"stream"} /--><!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->';
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'stream', 'post_title' => 'Stream', 'post_content' => $content ] );
		$page     = get_post( $page_id );
		$this->assertInstanceOf( WP_Post::class, $page );
		$surface = static function ( array $vars ): array {
			if ( is_page( 'stream' ) ) {
				$vars['meta_query'][] = [ 'key' => '_pkiw_surface', 'value' => 'stream' ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The fixture proves page-conditioned Query Loop parity.
			}
			return $vars;
		};
		add_filter( 'query_loop_block_query_vars', $surface );
		try {
			$this->go_to( get_permalink( $page_id ) );
			$query_block = array_values( array_filter( parse_blocks( $content ), static fn( array $block ): bool => 'core/query' === $block['blockName'] ) )[0];
			$served_ids  = self::post_ids_from_html( render_block( $query_block ) );
			$front_vars  = Zine_Manifest::stream_query_vars( $page );
			$this->assertIsArray( $front_vars );
			$this->assertSame( $served_ids, self::query_ids( $front_vars ) );
			$this->assertNotContains( $outside_id, $served_ids, 'The non-stream fixture must be excluded so parity is not vacuous.' );
			$full_vars                   = $front_vars;
			$full_vars['posts_per_page'] = -1;
			$oracle_ids                  = self::query_ids( $full_vars );

			$prior               = $GLOBALS['wp_query'];
			$GLOBALS['wp_query'] = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This is the frozen WP-CLI/MCP context control.
			try {
				$empty_vars = Zine_Manifest::stream_query_vars( $page );
			} finally {
				$GLOBALS['wp_query'] = $prior; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the front-end request.
			}
			$this->assertIsArray( $empty_vars );
			$this->assertSame( $served_ids, self::query_ids( $empty_vars ) );

			foreach ( [ 8 => 6, 16 => 11 ] as $pages => $expected ) {
				$manifest = Zine_Manifest::build( [ 'source' => 'stream', 'page_id' => $page_id, 'pages' => $pages ] );
				$this->assertIsArray( $manifest );
				$this->assertSame( $expected, count( $manifest['included'] ) );
				$this->assertSame( 11, $manifest['total'] );
				$this->assertSame( array_slice( $oracle_ids, 0, 8 === $pages ? 6 : 14 ), $manifest['included'] );
			}
		} finally {
			remove_filter( 'query_loop_block_query_vars', $surface );
		}
	}

	/** Return post IDs in one rendered Post Template. */
	private static function post_ids_from_html( string $html ): array {
		$ids  = [];
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$classes = $tags->get_attribute( 'class' );
			if ( ! is_string( $classes ) ) {
				continue;
			}
			foreach ( explode( ' ', $classes ) as $class ) {
				if ( str_starts_with( $class, 'post-' ) && ctype_digit( substr( $class, 5 ) ) ) {
					$ids[] = (int) substr( $class, 5 );
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Return the normalized text for the entry section headed by one title. */
	private function entry_section_text( string $html, string $title ): string {
		$dom = new DOMDocument();
		$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $dom );
		foreach ( $xpath->query( '//section[@data-pkiw-zine-page]' ) as $section ) {
			$heading = trim( (string) $xpath->evaluate( 'string(.//h2)', $section ) );
			if ( $title === $heading ) {
				return trim( (string) $section->textContent );
			}
		}
		$this->fail( 'No zine entry section found for ' . $title );

		return '';
	}

	/** Run one Query Loop variable set and return ordered IDs. */
	private static function query_ids( array $vars ): array {
		$query = new WP_Query( $vars );
		return array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );
	}
}
