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
 * `post-kinds/get-post-meta` ability and the `event_location` binding. An
 * editor still sees the location on a front-end page. Existing RSVPs have no
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
	 * Make a post's stored location visibility public, as the card's toggle does on save.
	 *
	 * @param int $id Post ID.
	 */
	private function make_public( int $id ): void {
		update_post_meta( $id, '_pkiw_rsvp_location_privacy', 'public' );
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
		$this->assertTrue( Meta_Fields::rsvp_location_public( $id ) );
	}

	public function test_an_explicitly_private_rsvp_prints_no_location(): void {
		$id = $this->rsvp( 'yes', 'future', 'private' );

		$this->assert_identity_without_location( $this->render_card( $id ), 'yes' );
	}

	public function test_an_rsvp_saved_before_the_setting_existed_is_private_and_keeps_its_text(): void {
		$id = $this->rsvp( 'yes', 'past' );
		// An RSVP saved before #251 has no stored visibility at all.
		delete_post_meta( $id, '_pkiw_rsvp_location_privacy' );

		$this->assertFalse( Meta_Fields::rsvp_location_public( $id ) );
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

		$this->assertFalse( Meta_Fields::rsvp_location_public( $id ) );
		$this->assert_identity_without_location( $this->render_card( $id ), 'yes' );
	}

	public function test_an_editor_sees_a_private_location_on_the_front_end(): void {
		$id = $this->rsvp( 'maybe', 'future' );
		$this->as_editor();

		$this->assertStringContainsString( '<span class="p-location">' . self::LOCATION . '</span>', $this->render_card( $id ) );
		$this->assertTrue( Meta_Fields::rsvp_location_visible( $id ) );
		$this->assertFalse( Meta_Fields::rsvp_location_public( $id ), 'The visitor answer has no editor override.' );
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
		$this->assertStringContainsString( self::LOCATION, $this->render_card( $id ), 'The editor sees it on the front end.' );

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

		$this->assertNull( $this->event_location_binding( $id ) );

		$this->make_public( $id );
		$this->assertSame( self::LOCATION, $this->event_location_binding( $id ) );
	}
}
