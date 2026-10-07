<?php
/**
 * An RSVP's event location prints only when its visibility is public (#251).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Block_Bindings;
use PKIW\Meta_Fields;
use PKIW\Micropub_Content_Builder;

/**
 * Courtney's answer A on #251: the RSVP card's free-text `eventLocation`
 * never prints unless that RSVP's location visibility is `public`, for every
 * status and for past and future events, with no "Location hidden" text. A
 * hidden location prints the same markup as an RSVP with no location. The
 * event name, date and the hidden `u-in-reply-to` link still print.
 *
 * Covers the card and its microformats, the Stream card, the feed's
 * `content:encoded` and whole RSS2 and Atom documents, content rendered for
 * federation in the publishing editor's request (ActivityPub and ATmosphere
 * copy the post's rendered content), REST `content.rendered` and meta, the
 * `post-kinds/get-post-meta` ability and the `event_location` binding.
 * Logged-in editors get the same front end as visitors, because a plugin that
 * caches rendered content can serve their render to everyone; they see the
 * location in the block editor and in REST meta. Each card follows its own
 * toggle, and the first RSVP card sets the post. Existing RSVPs have no
 * stored visibility, so they count as private and keep their stored text.
 *
 * @group integration
 */
final class RsvpLocationPrivacyTest extends WP_UnitTestCase {

	private const EVENT     = 'Quillfeather Meetup Rv51';
	private const LOCATION  = 'Back room, 9 Quill Lane Rv51';
	private const EVENT_URL = 'https://events.example/quillfeather-rv51';

	/**
	 * Visible label per stored status, as the card prints it.
	 */
	private const LABELS = [
		'yes'        => 'Going',
		'no'         => 'Not Going',
		'maybe'      => 'Maybe',
		'interested' => 'Interested',
		'remote'     => 'Attending Remotely',
	];

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_doing_cron', '__return_true' );
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Event start as the card stores it: a datetime-local value.
	 *
	 * @param string $when `past` or `future`.
	 */
	private function start( string $when ): string {
		$offset = 'past' === $when ? -30 * DAY_IN_SECONDS : 30 * DAY_IN_SECONDS;

		return gmdate( 'Y-m-d\TH:i', time() + $offset );
	}

	/**
	 * RSVP card markup.
	 *
	 * @param string      $status        Stored RSVP status.
	 * @param string      $when          `past` or `future`.
	 * @param string|null $visibility    `locationVisibility` attribute, or null to leave it out.
	 * @param bool        $with_location Whether the card stores an event location.
	 */
	private function card( string $status = 'yes', string $when = 'future', ?string $visibility = null, bool $with_location = true ): string {
		$attrs = [
			'eventName'  => self::EVENT,
			'eventUrl'   => self::EVENT_URL,
			'eventStart' => $this->start( $when ),
			'rsvpStatus' => $status,
		];
		if ( $with_location ) {
			$attrs['eventLocation'] = self::LOCATION;
		}
		if ( null !== $visibility ) {
			$attrs['locationVisibility'] = $visibility;
		}

		return '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * A published RSVP whose card stores the event location.
	 *
	 * @param string      $status     Stored RSVP status.
	 * @param string      $when       `past` or `future`.
	 * @param string|null $visibility `locationVisibility` attribute, or null to leave it out.
	 * @return int Post ID.
	 */
	private function rsvp( string $status = 'yes', string $when = 'future', ?string $visibility = null ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'RSVP to Quillfeather',
				'post_content' => $this->card( $status, $when, $visibility ),
			]
		);
		wp_set_object_terms( $id, 'rsvp', 'kind' );

		return $id;
	}

	/**
	 * Turn on the RSVP card's "Show event location publicly" toggle and save.
	 *
	 * @param int $id Post ID.
	 */
	private function make_public( int $id ): void {
		$blocks = parse_blocks( (string) get_post_field( 'post_content', $id ) );
		foreach ( $blocks as $i => $block ) {
			if ( 'post-kinds-indieweb/rsvp-card' === $block['blockName'] ) {
				$blocks[ $i ]['attrs']['locationVisibility'] = 'public';
			}
		}
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( serialize_blocks( $blocks ) ),
			]
		);

		$this->assertSame( 'public', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ), 'The card synced its visibility.' );
	}

	/**
	 * The card as its permalink renders it.
	 *
	 * @param int $id Post ID.
	 */
	private function render_card( int $id ): string {
		$this->go_to( get_permalink( $id ) );

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * Log in as an editor.
	 */
	private function as_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * The first parsed microformats item of a type, searched through
	 * properties and children.
	 *
	 * @param string $html Card HTML.
	 * @param string $type Microformats root type, such as `h-event`.
	 * @return array<string, mixed>|null
	 */
	private function find_item( string $html, string $type ): ?array {
		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$stack  = $parsed['items'];
		while ( $stack ) {
			$item = array_shift( $stack );
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( in_array( $type, $item['type'] ?? [], true ) ) {
				return $item;
			}
			foreach ( $item['properties'] ?? [] as $values ) {
				foreach ( $values as $value ) {
					if ( is_array( $value ) && isset( $value['type'] ) ) {
						$stack[] = $value;
					}
				}
			}
			foreach ( $item['children'] ?? [] as $child ) {
				$stack[] = $child;
			}
		}

		return null;
	}

	/**
	 * The post as the REST posts route returns it.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function rest_post( int $id ): array {
		$GLOBALS['wp_rest_server'] = null;

		return (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
	}

	/**
	 * The post's feed item content (`content:encoded`), as the RSS2 template prints it.
	 *
	 * @param int $id Post ID.
	 */
	private function feed_content( int $id ): string {
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed(), 'Feed context did not take.' );

		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $id ) {
				return get_the_content_feed( 'rss2' );
			}
		}

		$this->fail( sprintf( 'Post %d never appeared in the feed loop.', $id ) );
	}

	/**
	 * A whole feed document, as core's feed template prints it.
	 *
	 * @param string $type `rss2` or `atom`.
	 */
	private function feed_document( string $type ): string {
		$this->go_to( '/?feed=' . $type );
		$this->assertTrue( is_feed(), 'Feed context did not take.' );

		ob_start();
		try {
			// The template sends headers after PHPUnit's output, as core's feed tests do.
			@require ABSPATH . WPINC . '/feed-' . $type . '.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$out = (string) ob_get_clean();
		}

		return $out;
	}

	/**
	 * The `event_location` binding's value for a post.
	 *
	 * @param int $id Post ID.
	 */
	private function event_location_binding( int $id ): ?string {
		$block = new WP_Block(
			[
				'blockName' => 'core/paragraph',
				'attrs'     => [],
			],
			[ 'postId' => $id ]
		);
		// WP_Block keeps only the context keys a block type declares in
		// uses_context; set it the way a Query Loop's postId arrives.
		$block->context = [ 'postId' => $id ];

		return ( new Block_Bindings() )->get_binding_value( [ 'key' => 'event_location' ], $block, 'content' );
	}

	/**
	 * Assert a rendered card keeps the RSVP's identity and prints no location.
	 *
	 * @param string $html   Card HTML.
	 * @param string $status Stored RSVP status.
	 */
	private function assert_identity_without_location( string $html, string $status ): void {
		$this->assertStringContainsString( self::EVENT, $html, 'The event name prints.' );
		$this->assertStringContainsString( 'class="dt-start"', $html, 'The event date prints.' );
		$this->assertStringContainsString( '<data class="u-in-reply-to" value="' . self::EVENT_URL . '" hidden></data>', $html, 'The in-reply-to link stays.' );
		$this->assertStringContainsString( 'value="' . $status . '">' . self::LABELS[ $status ] . '</data>', $html, 'The status prints.' );

		$this->assertStringNotContainsString( self::LOCATION, $html );
		$this->assertStringNotContainsString( 'p-location', $html );
		$this->assertStringNotContainsString( 'pk-dot', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'location hidden', $html );

		$event = $this->find_item( $html, 'h-event' );
		$this->assertNotNull( $event, 'Expected a parsed h-event.' );
		$this->assertArrayNotHasKey( 'location', $event['properties'] );
	}

	/**
	 * Every stored status, for a past and an upcoming event.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function statuses_and_times(): array {
		$cases = [];
		foreach ( array_keys( self::LABELS ) as $status ) {
			foreach ( [ 'past', 'future' ] as $when ) {
				$cases[ "{$status}, {$when} event" ] = [ $status, $when ];
			}
		}

		return $cases;
	}

	public function test_the_card_declares_a_location_visibility_that_defaults_to_private(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'post-kinds-indieweb/rsvp-card' );
		$this->assertNotNull( $type );
		$this->assertArrayHasKey( 'locationVisibility', $type->attributes );
		$this->assertSame( 'private', $type->attributes['locationVisibility']['default'] );
		$this->assertSame( [ 'private', 'public' ], $type->attributes['locationVisibility']['enum'] );

		$src   = json_decode( (string) file_get_contents( PKIW_PATH . 'src/blocks/rsvp-card/block.json' ), true );
		$build = json_decode( (string) file_get_contents( PKIW_PATH . 'build/blocks/rsvp-card/block.json' ), true );
		$this->assertSame( $build['attributes']['locationVisibility'], $src['attributes']['locationVisibility'], 'src and build declare the same attribute.' );
	}

	/**
	 * @dataProvider statuses_and_times
	 */
	public function test_a_visitor_sees_no_location_on_an_rsvp_left_at_the_default( string $status, string $when ): void {
		$id   = $this->rsvp( $status, $when );
		$html = $this->render_card( $id );

		$this->assert_identity_without_location( $html, $status );
		$this->assertSame(
			do_blocks( $this->card( $status, $when, null, false ) ),
			$html,
			'A hidden location prints the same markup as an RSVP with no location.'
		);
		$this->assertFalse( Meta_Fields::rsvp_location_visible( $id ) );
	}

	/**
	 * @dataProvider statuses_and_times
	 */
	public function test_the_public_opt_in_prints_the_location( string $status, string $when ): void {
		$id   = $this->rsvp( $status, $when, 'public' );
		$html = $this->render_card( $id );

		$this->assertSame( 'public', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ), 'The card synced its visibility.' );
		$this->assertStringContainsString( '<span class="p-location">' . self::LOCATION . '</span>', $html );
		$event = $this->find_item( $html, 'h-event' );
		$this->assertNotNull( $event, 'Expected a parsed h-event.' );
		$this->assertSame( self::LOCATION, $event['properties']['location'][0] );
		$this->assertTrue( Meta_Fields::rsvp_location_visible( $id ) );
	}

	public function test_an_explicitly_private_rsvp_prints_no_location(): void {
		$id = $this->rsvp( 'yes', 'future', 'private' );

		$this->assert_identity_without_location( $this->render_card( $id ), 'yes' );
	}

	public function test_an_rsvp_saved_before_the_setting_existed_is_private_and_keeps_its_text(): void {
		$id = $this->rsvp( 'yes', 'past' );
		// An RSVP saved before #251 has no stored visibility at all.
		delete_post_meta( $id, '_pkiw_rsvp_location_privacy' );

		$this->assertFalse( Meta_Fields::rsvp_location_visible( $id ) );
		$this->assert_identity_without_location( $this->render_card( $id ), 'yes' );
		$this->assertStringContainsString( self::LOCATION, (string) get_post_field( 'post_content', $id ), 'The stored location text stays.' );
	}

	public function test_switching_the_card_back_to_private_stores_private(): void {
		$id = $this->rsvp( 'yes', 'future', 'public' );
		$this->assertSame( 'public', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ) );

		// The editor leaves an attribute that equals its default out of the block comment.
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $this->card( 'yes', 'future' ),
			]
		);

		$this->assertSame( 'private', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ) );
		$this->assert_identity_without_location( $this->render_card( $id ), 'yes' );
		$this->assertStringContainsString( self::LOCATION, (string) get_post_field( 'post_content', $id ), 'The stored location text stays.' );
	}

	public function test_an_unknown_visibility_value_is_stored_as_private(): void {
		$id = $this->rsvp( 'yes', 'future', 'everyone' );

		$this->assertSame( 'private', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ) );
		$this->assertStringNotContainsString( self::LOCATION, $this->render_card( $id ) );
	}

	/**
	 * Both ways a location is private under the shared location rule.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function private_locations(): array {
		return [
			'geo_privacy private' => [ '_pkiw_geo_privacy', 'private' ],
			'geo_public 0'        => [ 'geo_public', '0' ],
		];
	}

	/**
	 * @dataProvider private_locations
	 */
	public function test_an_explicitly_private_post_location_wins_over_the_public_opt_in( string $key, string $value ): void {
		$id = $this->rsvp( 'yes', 'future', 'public' );
		update_post_meta( $id, $key, $value );

		$this->assertFalse( Meta_Fields::rsvp_location_visible( $id ) );
		$this->assert_identity_without_location( $this->render_card( $id ), 'yes' );
	}

	public function test_a_card_left_private_prints_no_location_when_the_post_says_public(): void {
		$id = $this->rsvp( 'interested', 'past' );
		// A REST or Micropub client can write the post's setting without touching the card.
		update_post_meta( $id, '_pkiw_rsvp_location_privacy', 'public' );

		$this->assert_identity_without_location( $this->render_card( $id ), 'interested' );
	}

	public function test_a_second_rsvp_card_left_private_prints_no_location(): void {
		$other = 'Front hall, 1 Quill Lane Rv51';
		$first = '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode(
			[
				'eventName'          => 'Inkwell Social Rv51',
				'eventUrl'           => 'https://events.example/inkwell-rv51',
				'eventLocation'      => $other,
				'rsvpStatus'         => 'yes',
				'locationVisibility' => 'public',
			]
		) . ' /-->';
		$id    = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $first . "\n\n" . $this->card( 'yes', 'future' ),
			]
		);
		wp_set_object_terms( $id, 'rsvp', 'kind' );

		$this->assertSame( 'public', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ), 'The first RSVP card sets the post.' );
		$html = $this->render_card( $id );
		$this->assertStringContainsString( '<span class="p-location">' . $other . '</span>', $html, 'The public card prints its location.' );
		$this->assertStringContainsString( self::EVENT, $html, 'The second card renders.' );
		$this->assertStringNotContainsString( self::LOCATION, $html, 'The card left private prints none.' );
	}

	public function test_a_card_of_another_kind_ahead_of_the_rsvp_card_cannot_keep_it_public(): void {
		$id = $this->rsvp( 'yes', 'future', 'public' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );
		$this->assertSame( 'public', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ) );

		// A read card added ahead of the RSVP card, and the RSVP card switched back off.
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Quill Primer Rv51"} /-->' . "\n\n" . $this->card( 'yes', 'future' ),
			]
		);
		wp_set_object_terms( $id, 'rsvp', 'kind' );

		$this->assertSame( 'private', get_post_meta( $id, '_pkiw_rsvp_location_privacy', true ) );
		$this->assertFalse( Meta_Fields::rsvp_location_visible( $id ) );
		$this->assertStringNotContainsString( self::LOCATION, $this->render_card( $id ) );
		$this->assertSame( '', $this->rest_post( $id )['meta']['_pkiw_event_location'], 'REST meta follows the RSVP card.' );
	}

	/**
	 * Front-end views a logged-in editor can load.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function front_end_views(): array {
		return [
			'single post' => [ 'single' ],
			'home'        => [ 'home' ],
			'search'      => [ 'search' ],
		];
	}

	/**
	 * A plugin that caches rendered content, such as Markdown Alternate's
	 * md_alt_cache_{ID} transient, can serve an editor's front-end render
	 * to visitors, so the editor gets the visitor answer there too.
	 *
	 * @dataProvider front_end_views
	 */
	public function test_an_editor_on_the_front_end_sees_no_private_location( string $view ): void {
		$id = $this->rsvp( 'maybe', 'future' );
		$this->as_editor();

		$urls = [
			'single' => get_permalink( $id ),
			'home'   => home_url( '/' ),
			'search' => home_url( '/?s=Quillfeather' ),
		];
		$this->go_to( $urls[ $view ] );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$content = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		wp_reset_postdata();

		$this->assertStringContainsString( self::EVENT, $content, 'The card itself renders.' );
		$this->assertStringNotContainsString( self::LOCATION, $content );
		$this->assertStringNotContainsString( 'p-location', $content );
		$this->assertFalse( Meta_Fields::rsvp_location_visible( $id ) );
	}

	public function test_the_feed_content_omits_a_private_location(): void {
		$id = $this->rsvp( 'yes', 'future' );

		$content = $this->feed_content( $id );
		$this->assertStringContainsString( self::EVENT, $content, 'The card itself is in the feed.' );
		$this->assertStringNotContainsString( self::LOCATION, $content );
		$this->assertStringNotContainsString( 'p-location', $content );

		$this->make_public( $id );
		$this->assertStringContainsString( self::LOCATION, $this->feed_content( $id ), 'A public RSVP prints its location in the feed.' );
	}

	/**
	 * Both feed templates core ships, read by a visitor and by the RSVP's editor.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function feed_readers(): array {
		return [
			'rss2, visitor' => [ 'rss2', false ],
			'atom, visitor' => [ 'atom', false ],
			'rss2, editor'  => [ 'rss2', true ],
			'atom, editor'  => [ 'atom', true ],
		];
	}

	/**
	 * @dataProvider feed_readers
	 */
	public function test_a_whole_feed_document_omits_a_private_location( string $type, bool $editor ): void {
		$id = $this->rsvp( 'interested', 'future' );
		if ( $editor ) {
			$this->as_editor();
		}

		$doc = $this->feed_document( $type );
		$this->assertStringContainsString( self::EVENT, $doc, 'The RSVP is in the feed.' );
		$this->assertStringNotContainsString( self::LOCATION, $doc );

		$this->make_public( $id );
		$this->assertStringContainsString( self::LOCATION, $this->feed_document( $type ), 'A public RSVP prints its location in the same feed.' );
	}

	public function test_the_stream_card_omits_a_private_location(): void {
		$id = $this->rsvp( 'remote', 'future' );
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// A card-only post goes through do_blocks() in the Stream.
		$html = \PKIW\render_stream_card();
		$this->assertStringContainsString( self::EVENT, $html, 'The Stream card itself renders.' );
		$this->assertStringNotContainsString( self::LOCATION, $html );
		$this->assertStringNotContainsString( 'p-location', $html );

		$this->make_public( $id );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertStringContainsString( self::LOCATION, \PKIW\render_stream_card(), 'A public RSVP prints its location in the Stream card.' );
	}

	/**
	 * Requests that build federated copies while the editor is logged in:
	 * the publish request itself (REST or classic, no front-end query), a
	 * cron run, and an admin screen.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function publishing_contexts(): array {
		return [
			'publish request' => [ 'request' ],
			'cron'            => [ 'cron' ],
			'admin'           => [ 'admin' ],
		];
	}

	/**
	 * ActivityPub and ATmosphere copy the post's rendered content, built in
	 * the request that publishes it. Simulated here as their transformers do
	 * it: the post set up as the global post and its content passed through
	 * `the_content`.
	 *
	 * @dataProvider publishing_contexts
	 */
	public function test_content_rendered_for_federation_omits_a_private_location_for_the_editor( string $context ): void {
		$id = $this->rsvp( 'yes', 'future' );
		$this->as_editor();
		$this->go_to( get_permalink( $id ) );

		if ( 'request' === $context ) {
			$GLOBALS['wp_the_query'] = new WP_Query();
			$GLOBALS['wp_query']     = $GLOBALS['wp_the_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		} elseif ( 'cron' === $context ) {
			add_filter( 'wp_doing_cron', '__return_true' );
		} else {
			set_current_screen( 'edit-post' );
		}

		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$content = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		wp_reset_postdata();

		$this->assertStringContainsString( self::EVENT, $content, 'The card itself renders.' );
		$this->assertStringNotContainsString( self::LOCATION, $content );
		$this->assertFalse( Meta_Fields::rsvp_location_visible( $id ) );
	}

	public function test_rest_hides_a_private_location_from_a_visitor(): void {
		$id = $this->rsvp( 'no', 'past' );
		// Quick Post and the RSVP meta box store the location in meta.
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$post = $this->rest_post( $id );
		$this->assertStringContainsString( self::EVENT, $post['content']['rendered'], 'The card itself is in content.rendered.' );
		$this->assertStringNotContainsString( self::LOCATION, $post['content']['rendered'] );
		$this->assertArrayHasKey( '_pkiw_event_location', $post['meta'] );
		$this->assertSame( '', $post['meta']['_pkiw_event_location'] );
		$this->assertSame( 'private', $post['meta']['_pkiw_rsvp_location_privacy'] );
		$this->assertSame( self::LOCATION, get_post_meta( $id, '_pkiw_event_location', true ), 'Stored data stays intact.' );

		$this->make_public( $id );
		$post = $this->rest_post( $id );
		$this->assertSame( self::LOCATION, $post['meta']['_pkiw_event_location'], 'A public RSVP returns its location.' );
		$this->assertStringContainsString( self::LOCATION, $post['content']['rendered'] );
	}

	public function test_rest_gives_the_editor_the_stored_location_and_a_rendered_card_without_it(): void {
		$id = $this->rsvp( 'yes', 'future' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );
		$this->as_editor();

		$post = $this->rest_post( $id );
		$this->assertSame( self::LOCATION, $post['meta']['_pkiw_event_location'], 'The editor reads the stored meta.' );
		$this->assertStringNotContainsString( self::LOCATION, $post['content']['rendered'], 'Rendered content leaves the request, so it gets the visitor answer.' );
	}

	public function test_an_event_kind_post_keeps_its_event_location_in_rest(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'event', 'kind' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assertSame( self::LOCATION, $this->rest_post( $id )['meta']['_pkiw_event_location'], 'The gate covers RSVPs only.' );
	}

	public function test_a_plain_event_post_keeps_its_location_in_the_ability_and_the_binding(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'event', 'kind' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->go_to( get_permalink( $id ) );
		$this->assertSame( self::LOCATION, $this->event_location_binding( $id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( self::LOCATION, Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] )['meta']['event_location'] );
	}

	/**
	 * Kind terms on an RSVP whose author set it to Event.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public function rsvp_set_to_event_terms(): array {
		return [
			'event'          => [ [ 'event' ] ],
			'rsvp and event' => [ [ 'rsvp', 'event' ] ],
		];
	}

	/**
	 * An Event post with a private RSVP card and a location typed in the
	 * Event sidebar is an RSVP, so the Event exemption doesn't apply.
	 *
	 * @dataProvider rsvp_set_to_event_terms
	 *
	 * @param string[] $terms Kind terms on the post.
	 */
	public function test_a_private_rsvp_card_on_an_event_post_keeps_the_location_from_visitors( array $terms ): void {
		$id = $this->rsvp( 'yes', 'future' );
		wp_set_object_terms( $id, $terms, 'kind' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );
		$this->assertSame( 'private', get_metadata_raw( 'post', $id, '_pkiw_rsvp_location_privacy', true ), 'The card stored its setting.' );

		$this->assert_no_location_for_visitors( $id );
		$this->assert_location_for_editors( $id );

		$this->make_public( $id );
		wp_set_current_user( 0 );
		$this->assertSame( self::LOCATION, $this->rest_post( $id )['meta']['_pkiw_event_location'], 'A public RSVP returns its location.' );
	}

	/**
	 * RSVP status rows a post keeps without an RSVP card: the editor
	 * sidebar's, and the one Quick Post and the RSVP meta box store.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function rsvp_status_keys(): array {
		return [
			'editor sidebar'          => [ '_pkiw_rsvp_status' ],
			'Quick Post and meta box' => [ '_pkiw_rsvp_value' ],
		];
	}

	/**
	 * A Quick Post or meta-box RSVP set to Event has no card, only its
	 * status row, and that row makes it an RSVP.
	 *
	 * @dataProvider rsvp_status_keys
	 *
	 * @param string $key RSVP status meta key.
	 */
	public function test_an_rsvp_status_row_on_an_event_post_keeps_the_location_from_visitors( string $key ): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'event', 'kind' );
		update_post_meta( $id, $key, 'yes' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assert_no_location_for_visitors( $id );
		$this->assert_location_for_editors( $id );
	}

	/**
	 * Only the Event kind is exempt, so a Quick Post RSVP set to Note keeps
	 * its location private too.
	 */
	public function test_an_rsvp_set_to_another_kind_keeps_the_location_from_visitors(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'note', 'kind' );
		update_post_meta( $id, '_pkiw_rsvp_value', 'yes' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assert_no_location_for_visitors( $id );
		$this->assert_location_for_editors( $id );
	}

	public function test_a_micropub_rsvp_hinted_as_an_event_keeps_the_location_from_visitors(): void {
		// The hint needs the term, and an earlier class can commit its deletion.
		if ( ! term_exists( 'event', 'kind' ) ) {
			wp_insert_term( 'Event', 'kind', [ 'slug' => 'event' ] );
		}
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Micropub_Content_Builder::apply(
			[
				'h'           => 'entry',
				'rsvp'        => 'yes',
				'in-reply-to' => self::EVENT_URL,
				'pkiw-kind'   => 'event',
			],
			[ 'ID' => $id ]
		);
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assertTrue( has_term( 'event', 'kind', $id ), 'The hint set the kind.' );
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/rsvp-card', (string) get_post_field( 'post_content', $id ) );

		$this->assert_no_location_for_visitors( $id );
		$this->assert_location_for_editors( $id );
	}

	/**
	 * An RSVP saved before the card wrote its privacy row, then set to
	 * Event, has only its card. No backfill writes the row, so the card
	 * itself makes it an RSVP.
	 */
	public function test_an_rsvp_card_saved_before_the_privacy_row_keeps_the_location_from_visitors(): void {
		$id = $this->rsvp( 'yes', 'future' );
		wp_set_object_terms( $id, 'event', 'kind' );
		foreach ( [ '_pkiw_rsvp_location_privacy', '_pkiw_rsvp_status', '_pkiw_rsvp_value' ] as $key ) {
			delete_post_meta( $id, $key );
			$this->assertFalse( metadata_exists( 'post', $id, $key ), "No {$key} row." );
		}
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assertFalse( Meta_Fields::event_location_visible( $id ) );
		$this->assert_no_location_for_visitors( $id );
		$this->assert_location_for_editors( $id );
	}

	/**
	 * A published synced pattern (wp_block).
	 *
	 * @param string $content Pattern content.
	 * @return int Pattern post ID.
	 */
	private function pattern( string $content ): int {
		return self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Quillfeather pattern Rv51',
				'post_content' => $content,
			]
		);
	}

	/**
	 * Markup that places a synced pattern.
	 *
	 * @param int $pattern_id Pattern post ID.
	 */
	private function ref( int $pattern_id ): string {
		return '<!-- wp:block {"ref":' . $pattern_id . '} /-->';
	}

	/**
	 * An RSVP card wrapped in this many synced patterns.
	 *
	 * @param int $depth Number of patterns between the post and the card.
	 */
	private function card_in_patterns( int $depth ): string {
		$content = $this->card( 'yes', 'future' );
		for ( $i = 0; $i < $depth; $i++ ) {
			$content = $this->ref( $this->pattern( $content ) );
		}

		return $content;
	}

	/**
	 * How many synced patterns sit between the post and its RSVP card.
	 *
	 * @return array<string, array{0: int}>
	 */
	public function synced_pattern_depths(): array {
		return [
			'one ref'       => [ 1 ],
			'two refs deep' => [ 2 ],
		];
	}

	/**
	 * An Event post whose only RSVP card sits in a synced pattern has no
	 * RSVP row, because Card_Meta_Sync reads the post's own blocks, and
	 * has_block() reads only the post's own content. The pattern's card
	 * still makes it an RSVP.
	 *
	 * @dataProvider synced_pattern_depths
	 *
	 * @param int $depth Number of patterns between the post and the card.
	 */
	public function test_an_rsvp_card_in_a_synced_pattern_keeps_the_location_from_visitors( int $depth ): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->card_in_patterns( $depth ),
			]
		);
		wp_set_object_terms( $id, 'event', 'kind' );
		foreach ( [ '_pkiw_rsvp_location_privacy', '_pkiw_rsvp_status', '_pkiw_rsvp_value' ] as $key ) {
			$this->assertFalse( metadata_exists( 'post', $id, $key ), "No {$key} row." );
		}
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assertStringContainsString( self::EVENT, $this->rest_post( $id )['content']['rendered'], 'The pattern renders its card.' );
		$this->assert_no_location_for_visitors( $id );
		$this->assert_location_for_editors( $id );
		$this->assertFalse( Meta_Fields::event_location_visible( $id ) );
	}

	/**
	 * Nesting past the depth cap counts as an RSVP, so a card buried deeper
	 * than the walk goes still keeps its location.
	 */
	public function test_an_rsvp_card_past_the_synced_pattern_depth_cap_keeps_the_location_from_visitors(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->card_in_patterns( 12 ),
			]
		);
		wp_set_object_terms( $id, 'event', 'kind' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assert_no_location_for_visitors( $id );
		$this->assertFalse( Meta_Fields::event_location_visible( $id ) );
	}

	/**
	 * Two synced patterns that place each other end the walk, and an Event
	 * post with no RSVP card among them keeps its location.
	 */
	public function test_a_plain_event_post_whose_synced_patterns_ref_each_other_keeps_its_location(): void {
		$first  = $this->pattern( '' );
		$second = $this->pattern( $this->ref( $first ) );
		wp_update_post(
			[
				'ID'           => $first,
				'post_content' => wp_slash( $this->ref( $second ) ),
			]
		);
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->ref( $first ),
			]
		);
		wp_set_object_terms( $id, 'event', 'kind' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );

		$this->assertTrue( Meta_Fields::event_location_visible( $id ) );
		$this->assertSame( self::LOCATION, $this->rest_post( $id )['meta']['_pkiw_event_location'] );
	}

	/**
	 * Event Card markup that stores the event location.
	 *
	 * @param array<string, mixed> $attrs Attributes that replace the defaults.
	 */
	private function event_card( array $attrs = [] ): string {
		$attrs += [
			'eventName'     => self::EVENT,
			'eventUrl'      => self::EVENT_URL,
			'eventStart'    => $this->start( 'future' ),
			'eventLocation' => self::LOCATION,
		];

		return '<!-- wp:post-kinds-indieweb/event-card ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * A published post with a kind and the given content.
	 *
	 * @param string   $content Post content.
	 * @param string[] $terms   Kind terms.
	 * @return int Post ID.
	 */
	private function post_with( string $content, array $terms ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $id, $terms, 'kind' );

		return $id;
	}

	/**
	 * The post's content as a visitor's single view renders it.
	 *
	 * @param int $id Post ID.
	 */
	private function the_content( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$content = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		wp_reset_postdata();

		return $content;
	}

	/**
	 * An Event Card on an RSVP follows the RSVP's setting: it printed
	 * `eventLocation` with no check, so a private RSVP's location reached
	 * content, mf2, REST, the feed and federated copies.
	 */
	public function test_an_event_card_on_a_private_rsvp_prints_no_location(): void {
		$id = $this->post_with( $this->card( 'yes', 'future' ) . "\n\n" . $this->event_card(), [ 'rsvp' ] );
		$this->assertSame( 'private', get_metadata_raw( 'post', $id, '_pkiw_rsvp_location_privacy', true ) );

		$html = $this->the_content( $id );
		$this->assertStringContainsString( 'k-event', $html, 'The Event Card renders.' );
		$this->assertStringNotContainsString( self::LOCATION, $html, 'Content.' );
		$this->assertStringNotContainsString( 'p-location', $html );
		$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( \Mf2\parse( $html ) ), 'Parsed mf2.' );
		$this->assertStringNotContainsString( self::LOCATION, $this->rest_post( $id )['content']['rendered'], 'REST content.rendered.' );
		$this->assertStringNotContainsString( self::LOCATION, $this->feed_content( $id ), 'Feed content.' );

		// Federated copies are built in the editor's publish request.
		$this->as_editor();
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['wp_the_query'] = new WP_Query();
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post']         = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$federated = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		wp_reset_postdata();
		$this->assertStringNotContainsString( self::LOCATION, $federated, 'Content rendered for federation.' );
		wp_set_current_user( 0 );

		$this->make_public( $id );
		$this->assertStringContainsString( self::LOCATION, $this->the_content( $id ), 'A public RSVP prints the Event Card location.' );
	}

	/**
	 * The post's content filtered with no global post, as ATmosphere's
	 * publish and update crons filter it for the document's textContent and
	 * the Bluesky post text.
	 *
	 * @param int $id Post ID.
	 */
	private function the_content_with_no_post( int $id ): string {
		unset( $GLOBALS['post'] );

		return apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * An Event Card that can't name its post prints no location. With no
	 * global post get_the_ID() is false, the card got post ID 0, and
	 * event_card_location_visible( 0 ) let a private RSVP's location print.
	 */
	public function test_an_event_card_rendered_with_no_global_post_prints_no_location(): void {
		$id = $this->post_with( $this->card( 'yes', 'future' ) . "\n\n" . $this->event_card(), [ 'rsvp' ] );

		$html = $this->the_content_with_no_post( $id );
		$this->assertStringContainsString( 'k-event', $html, 'The Event Card renders.' );
		$this->assertStringNotContainsString( self::LOCATION, $html );
		$this->assertStringNotContainsString( 'p-location', $html );
		$this->assertFalse( Meta_Fields::event_card_location_visible( 0 ) );
	}

	/**
	 * A private RSVP with an Event Card, for the ATmosphere transformers,
	 * which skip the test when ATmosphere isn't loaded.
	 */
	private function private_rsvp_with_an_event_card_for_atmosphere(): int {
		if ( ! class_exists( '\Atmosphere\Transformer\Document' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to build ATmosphere records.' );
		}

		$id = $this->post_with( $this->card( 'yes', 'future' ) . "\n\n" . $this->event_card(), [ 'rsvp' ] );
		$this->assertSame( 'private', get_metadata_raw( 'post', $id, '_pkiw_rsvp_location_privacy', true ) );
		unset( $GLOBALS['post'] );

		return $id;
	}

	/**
	 * ATmosphere's Document transformer filters the_content for textContent
	 * with no global post (Transformer\Base::render_post_content_html()), and
	 * its publish cron sets none.
	 *
	 * @group atmosphere
	 */
	public function test_the_atmosphere_document_built_with_no_global_post_omits_a_private_location(): void {
		$id     = $this->private_rsvp_with_an_event_card_for_atmosphere();
		$record = ( new \Atmosphere\Transformer\Document( get_post( $id ) ) )->transform();

		$this->assertStringContainsString( self::EVENT, (string) ( $record['textContent'] ?? '' ), 'textContent holds the cards.' );
		$this->assertStringNotContainsString( self::LOCATION, (string) $record['textContent'], 'textContent.' );
		$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $record ), 'The whole record.' );
	}

	/**
	 * A short-form Bluesky post's text comes from the same no-global-post
	 * render (Transformer\Post::build_short_form_text()).
	 *
	 * @group atmosphere
	 */
	public function test_the_bluesky_short_form_text_built_with_no_global_post_omits_a_private_location(): void {
		$id = $this->private_rsvp_with_an_event_card_for_atmosphere();
		add_filter( 'atmosphere_is_short_form_post', '__return_true' );
		$record = ( new \Atmosphere\Transformer\Post( get_post( $id ) ) )->transform();
		remove_filter( 'atmosphere_is_short_form_post', '__return_true' );

		$this->assertStringContainsString( self::EVENT, (string) ( $record['text'] ?? '' ), 'The post text holds the cards.' );
		$this->assertStringNotContainsString( self::LOCATION, (string) wp_json_encode( $record ) );
	}

	/**
	 * ATmosphere's post crons that publish a publishable post.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function atmosphere_post_crons(): array {
		return [
			'publish'          => [ 'atmosphere_publish_post' ],
			'update'           => [ 'atmosphere_update_post' ],
			'delete reconcile' => [ 'atmosphere_delete_post' ],
		];
	}

	/**
	 * The textContent ATmosphere's Document transformer builds inside one of
	 * its post crons, which start with no global post. ATmosphere's own
	 * callback, which writes to the PDS, is swapped for one that builds the
	 * document as Publisher does.
	 *
	 * @param string $hook Cron hook.
	 * @param int    $id   Post ID.
	 */
	private function atmosphere_cron_text_content( string $hook, int $id ): string {
		if ( ! class_exists( '\Atmosphere\Transformer\Document' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to build ATmosphere records.' );
		}

		remove_all_actions( $hook, 10 );
		$text = '';
		add_action(
			$hook,
			static function ( int $post_id ) use ( &$text ): void {
				$record = ( new \Atmosphere\Transformer\Document( get_post( $post_id ) ) )->transform();
				$text   = (string) ( $record['textContent'] ?? '' );
			}
		);
		unset( $GLOBALS['post'] );
		do_action( $hook, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return $text;
	}

	/**
	 * Inside ATmosphere's post crons each card renders for the post being
	 * published, so a plain Event or Note post keeps its Event Card location
	 * in textContent, and an RSVP's follows its setting.
	 *
	 * @dataProvider atmosphere_post_crons
	 * @group atmosphere
	 *
	 * @param string $hook Cron hook.
	 */
	public function test_atmosphere_post_crons_render_each_event_card_for_its_post( string $hook ): void {
		foreach ( [ 'event', 'note' ] as $kind ) {
			$id = $this->post_with( $this->event_card(), [ $kind ] );
			$this->assertStringContainsString( self::LOCATION, $this->atmosphere_cron_text_content( $hook, $id ), "A plain {$kind} post." );
			$this->assertFalse( isset( $GLOBALS['post'] ), 'The cron leaves no global post behind.' );
		}

		$rsvp = $this->post_with( $this->card( 'yes', 'future' ) . "\n\n" . $this->event_card(), [ 'rsvp' ] );
		$text = $this->atmosphere_cron_text_content( $hook, $rsvp );
		$this->assertStringContainsString( self::EVENT, $text, 'textContent holds the cards.' );
		$this->assertStringNotContainsString( self::LOCATION, $text, 'A private RSVP.' );

		$this->make_public( $rsvp );
		$this->assertStringContainsString( self::LOCATION, $this->atmosphere_cron_text_content( $hook, $rsvp ), 'A public RSVP.' );
	}

	public function test_an_event_card_on_a_private_rsvp_prints_no_calendar_location(): void {
		$venue = 'Calendar Hall Rv51';
		add_filter(
			'pkiw_pre_calendar_event',
			static function ( $pre, string $source, int $event_id ) use ( $venue ) {
				return 'the-events-calendar' === $source && 251 === $event_id ? [ 'location' => $venue ] : $pre;
			},
			10,
			3
		);
		$card = $this->event_card(
			[
				'eventLocation'   => '',
				'calendarSource'  => 'the-events-calendar',
				'calendarEventId' => 251,
			]
		);
		$id   = $this->post_with( $this->card( 'yes', 'future' ) . "\n\n" . $card, [ 'rsvp' ] );

		$this->assertStringNotContainsString( $venue, $this->the_content( $id ) );

		$this->make_public( $id );
		$this->assertStringContainsString( $venue, $this->the_content( $id ), 'A public RSVP prints the calendar location.' );
	}

	/**
	 * Kind terms on a post that holds an Event Card and isn't an RSVP.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public function event_card_kinds(): array {
		return [
			'event' => [ [ 'event' ] ],
			'note'  => [ [ 'note' ] ],
			'none'  => [ [] ],
		];
	}

	/**
	 * The Event Card isn't a kind card, so it never sets the Event kind,
	 * and a post with no RSVP keeps its location whatever its kind.
	 *
	 * @dataProvider event_card_kinds
	 *
	 * @param string[] $terms Kind terms on the post.
	 */
	public function test_an_event_card_on_a_post_that_is_not_an_rsvp_prints_its_location( array $terms ): void {
		$id = $this->post_with( $this->event_card(), $terms );

		$this->assertStringContainsString( '<span class="p-location">' . self::LOCATION . '</span>', $this->the_content( $id ) );
		$this->assertStringContainsString( self::LOCATION, $this->rest_post( $id )['content']['rendered'], 'REST content.rendered.' );
	}

	/**
	 * Assert someone who can't edit the post gets no event location from REST
	 * meta, `content.rendered`, the get-post-meta ability or the binding.
	 *
	 * @param int $id Post ID.
	 */
	private function assert_no_location_for_visitors( int $id ): void {
		wp_set_current_user( 0 );
		$post = $this->rest_post( $id );
		$this->assertSame( '', $post['meta']['_pkiw_event_location'], 'REST meta.' );
		$this->assertStringNotContainsString( self::LOCATION, $post['content']['rendered'], 'REST content.rendered.' );

		$this->go_to( get_permalink( $id ) );
		$this->assertSame( '', $this->event_location_binding( $id ), 'The event_location binding.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] );
		$this->assertIsArray( $result );
		$this->assertSame( '', $result['meta']['event_location'], 'The get-post-meta ability.' );
		wp_set_current_user( 0 );
	}

	/**
	 * Assert an editor still reads the stored event location from REST meta
	 * and the get-post-meta ability.
	 *
	 * @param int $id Post ID.
	 */
	private function assert_location_for_editors( int $id ): void {
		$this->as_editor();
		$this->assertSame( self::LOCATION, $this->rest_post( $id )['meta']['_pkiw_event_location'], 'The editor reads REST meta.' );
		$this->assertSame( self::LOCATION, Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] )['meta']['event_location'], 'The editor reads the ability.' );
		wp_set_current_user( 0 );
	}

	/**
	 * Both ways to call the ability: every field, or named keys.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function ability_inputs(): array {
		return [
			'all keys'  => [ [] ],
			'meta_keys' => [ [ 'meta_keys' => [ 'event_location' ] ] ],
		];
	}

	/**
	 * @dataProvider ability_inputs
	 *
	 * @param array<string, mixed> $input Extra ability input.
	 */
	public function test_the_get_post_meta_ability_blanks_a_private_location_for_a_subscriber( array $input ): void {
		$id = $this->rsvp( 'yes', 'future' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] + $input );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'event_location', $result['meta'] );
		$this->assertSame( '', $result['meta']['event_location'] );

		$this->make_public( $id );
		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] + $input );
		$this->assertIsArray( $result );
		$this->assertSame( self::LOCATION, $result['meta']['event_location'], 'A public RSVP returns its location.' );
	}

	public function test_the_event_location_binding_is_empty_on_a_private_rsvp(): void {
		$id = $this->rsvp( 'yes', 'future' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );
		$this->go_to( get_permalink( $id ) );

		$this->assertSame( '', $this->event_location_binding( $id ) );

		$this->make_public( $id );
		$this->assertSame( self::LOCATION, $this->event_location_binding( $id ) );
	}

	public function test_a_bound_paragraph_on_a_private_rsvp_drops_saved_text_that_names_the_location(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'rsvp', 'kind' );
		update_post_meta( $id, '_pkiw_event_location', self::LOCATION );
		// Core keeps a bound block's saved HTML when the source returns null.
		$paragraph = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"' . Block_Bindings::SOURCE_NAME . '","args":{"key":"event_location"}}}}} --><p>' . self::LOCATION . '</p><!-- /wp:paragraph -->';
		$this->go_to( get_permalink( $id ) );

		$this->assertStringNotContainsString( self::LOCATION, do_blocks( $paragraph ) );

		update_post_meta( $id, '_pkiw_rsvp_location_privacy', 'public' );
		$this->assertStringContainsString( self::LOCATION, do_blocks( $paragraph ), 'A public RSVP prints the bound location.' );
	}
}
