<?php
/**
 * Tests for Presentation_Classifier.
 *
 * Fixtures copy the markup shapes found in real posts on courtneyr.dev:
 * SlideShare iframes (5221, 3557), a Flash SlideShare player (191), a
 * Google Slides iframe (2588), a wordpress-tv-embed block (7184), YouTube,
 * VideoPress and Dailymotion blocks (11424, 8238, 6962) and a link-only
 * SlideShare mention (1610).
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;
use PKIW\Presentation_Classifier;
use PKIW\Taxonomy;

class PresentationClassifierTest extends WP_UnitTestCase {

	private const SPEAKER_DECK_URL = 'https://speakerdeck.com/courtneyr/blocks-for-everyone';

	private const WPTV_URL = 'https://wordpress.tv/2021/07/22/hari-shanker-hauwa-abashiya-courtney-robertson-help-shape-content-on-learn-wordpress/';

	private const YOUTUBE_URL = 'https://www.youtube.com/watch?v=Zr1m5aYk0aQ';

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'listen', 'presentation', 'article' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( $slug, Taxonomy::TAXONOMY );
			}
		}
	}

	/**
	 * A core/embed block the way the block editor saves it.
	 */
	private static function embed_block( string $url, string $slug, string $type = 'video' ): string {
		$attrs = wp_json_encode(
			[
				'url'              => $url,
				'type'             => $type,
				'providerNameSlug' => $slug,
				'responsive'       => true,
			],
			JSON_UNESCAPED_SLASHES
		);

		return "<!-- wp:embed $attrs -->\n"
			. "<figure class=\"wp-block-embed is-type-$type is-provider-$slug wp-block-embed-$slug\"><div class=\"wp-block-embed__wrapper\">\n$url\n</div></figure>\n"
			. '<!-- /wp:embed -->';
	}

	private static function html_block( string $html ): string {
		return "<!-- wp:html -->\n$html\n<!-- /wp:html -->";
	}

	private static function listen_card(): string {
		return '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"Episode 12: Community"} /-->';
	}

	private function post( string $content ): \WP_Post {
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		return get_post( $post_id );
	}

	// --- deck -------------------------------------------------------------

	public function test_speaker_deck_embed_block_is_a_deck(): void {
		$post    = $this->post( self::embed_block( self::SPEAKER_DECK_URL, 'speaker-deck', 'rich' ) );
		$signals = Presentation_Classifier::signals( $post );

		$this->assertSame( [ self::SPEAKER_DECK_URL ], $signals['deck'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_speaker_deck_player_iframe_is_a_deck(): void {
		$post = $this->post(
			self::html_block( '<iframe class="speakerdeck-iframe" src="https://speakerdeck.com/player/0a1b2c3d4e5f60718293a4b5c6d7e8f9" title="Blocks for everyone" allowfullscreen="true"></iframe>' )
		);

		$this->assertSame(
			[ 'https://speakerdeck.com/player/0a1b2c3d4e5f60718293a4b5c6d7e8f9' ],
			Presentation_Classifier::signals( $post )['deck']
		);
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_slideshare_embed_code_iframe_in_html_block_is_a_deck(): void {
		// Post 5221: protocol-relative iframe plus the "from" links SlideShare adds.
		$post = $this->post(
			self::html_block(
				'<iframe src="//www.slideshare.net/slideshow/embed_code/key/fRg05TpkEwlzD9" width="1200" height="628" frameborder="0" marginwidth="0" marginheight="0" scrolling="no" style="border:1px solid #CCC; border-width:1px; margin-bottom:5px; max-width: 100%;" allowfullscreen> </iframe> <div style="margin-bottom:5px"> <strong> <a href="//www.slideshare.net/courane01/your-ultimate-website-checklist" title="Your Ultimate Website checklist">Your Ultimate WordPress Website checklist</a> </strong> from <strong><a href="//www.slideshare.net/courane01">Courtney Robertson</a></strong> </div>'
			)
		);

		$this->assertSame(
			[ 'https://www.slideshare.net/slideshow/embed_code/key/fRg05TpkEwlzD9' ],
			Presentation_Classifier::signals( $post )['deck']
		);
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_slideshare_iframe_in_classic_content_is_a_deck(): void {
		// Post 3557: classic content, no blocks.
		$post = $this->post(
			'<em id="__mceDel"> <iframe style="border: 1px solid #CCC; border-width: 1px 1px 0; margin-bottom: 5px;" src="http://www.slideshare.net/slideshow/embed_code/20679421?rel=0" height="356" width="427" allowfullscreen="" frameborder="0" marginwidth="0" marginheight="0" scrolling="no" title="How to Use LinkedIn Channels"></iframe></em>'
		);

		$this->assertSame(
			[ 'http://www.slideshare.net/slideshow/embed_code/20679421?rel=0' ],
			Presentation_Classifier::signals( $post )['deck']
		);
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_legacy_flash_slideshare_player_is_a_deck(): void {
		// Post 191: the 2010 SlideShare <object> with a <param> and an <embed>.
		$player = 'http://static.slidesharecdn.com/swf/ssplayer2.swf?doc=purchaseyourdomainname-100422&amp;stripped_title=purchase-your-domain-name';
		$post   = $this->post(
			'<a style="font:14px Helvetica,Arial,Sans-serif;display:block;margin:12px 0 3px 0;text-decoration:underline;" title="Purchase Your Domain Name" href="http://www.slideshare.net/courane01/purchase-your-domain-name">Purchase Your Domain Name</a>'
			. '<object style="margin:0px" classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000" width="425" height="355" codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,40,0">'
			. '<param name="movie" value="' . $player . '" /><param name="allowFullScreen" value="true" /><param name="allowScriptAccess" value="always" />'
			. '<embed style="margin:0px" type="application/x-shockwave-flash" width="425" height="355" src="' . $player . '" allowscriptaccess="always" allowfullscreen="true"></embed></object>'
		);

		$this->assertSame(
			[ 'http://static.slidesharecdn.com/swf/ssplayer2.swf?doc=purchaseyourdomainname-100422&stripped_title=purchase-your-domain-name' ],
			Presentation_Classifier::signals( $post )['deck'],
			'the <param> and <embed> carry one player URL; it is reported once'
		);
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_google_slides_iframe_is_a_deck(): void {
		// Post 2588.
		$post = $this->post(
			'<p><iframe src="https://docs.google.com/presentation/embed?id=1sdVsnj3yHEDVOLU6WtFU08O7H_FM3n6ou25LLqvqBTc&amp;start=false&amp;loop=false&amp;delayms=3000" frameborder="0" width="483" height="341" title="Google Docs in Google Drive - Google Slides presentation" allowfullscreen="true"></iframe></p>'
		);

		$this->assertSame(
			[ 'https://docs.google.com/presentation/embed?id=1sdVsnj3yHEDVOLU6WtFU08O7H_FM3n6ou25LLqvqBTc&start=false&loop=false&delayms=3000' ],
			Presentation_Classifier::signals( $post )['deck']
		);
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_google_docs_document_iframe_is_not_a_deck(): void {
		$post = $this->post( '<p><iframe src="https://docs.google.com/document/d/e/2PACX-1vT/pub?embedded=true"></iframe></p>' );

		$this->assertSame( [], Presentation_Classifier::signals( $post )['deck'] );
		$this->assertFalse( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_notist_script_embed_is_a_deck(): void {
		// The markup Notist's oEmbed endpoint returns.
		$post = $this->post(
			self::html_block( '<p data-notist="courtneyr/AbC123">View <a href="https://noti.st/courtneyr/AbC123">Building Community</a> on Notist.</p><script async src="https://on.notist.cloud/embed/002.js"></script>' )
		);

		$this->assertSame( [ 'https://noti.st/courtneyr/AbC123' ], Presentation_Classifier::signals( $post )['deck'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_notist_embed_iframe_is_a_deck(): void {
		// The iframe Notist's embed script writes.
		$post = $this->post( self::html_block( '<iframe src="https://noti.st/courtneyr/AbC123/embed" width="960" height="540" frameborder="0"></iframe>' ) );

		$this->assertSame( [ 'https://noti.st/courtneyr/AbC123/embed' ], Presentation_Classifier::signals( $post )['deck'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_slideshare_and_legacy_embed_block_slugs_are_decks(): void {
		// A pre-6.6 SlideShare core/embed block, and the pre-5.6 core-embed/* block name.
		$slideshare = $this->post( self::embed_block( 'https://www.slideshare.net/courane01/your-ultimate-website-checklist', 'slideshare', 'rich' ) );
		$legacy     = $this->post( '<!-- wp:core-embed/speaker-deck {"url":"' . self::SPEAKER_DECK_URL . '","type":"rich","providerNameSlug":"speaker-deck"} -->' . "\n<figure class=\"wp-block-embed-speaker-deck wp-block-embed is-type-rich is-provider-speaker-deck\"><div class=\"wp-block-embed__wrapper\">\n" . self::SPEAKER_DECK_URL . "\n</div></figure>\n<!-- /wp:core-embed/speaker-deck -->" );

		$this->assertTrue( Presentation_Classifier::is_presentation( $slideshare ) );
		$this->assertSame( [ self::SPEAKER_DECK_URL ], Presentation_Classifier::signals( $legacy )['deck'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $legacy ) );
	}

	public function test_speaker_deck_embed_nested_in_a_group_is_a_deck(): void {
		$post = $this->post(
			"<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Slides from the talk:</p>\n<!-- /wp:paragraph -->\n\n"
			. self::embed_block( self::SPEAKER_DECK_URL, 'speaker-deck', 'rich' )
			. "</div>\n<!-- /wp:group -->"
		);

		$this->assertSame( [ self::SPEAKER_DECK_URL ], Presentation_Classifier::signals( $post )['deck'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	// --- links and unsupported hosts never signal ---------------------------

	public function test_slideshare_link_only_is_not_a_presentation(): void {
		// Post 1610.
		$post = $this->post( '<p>Of course, there are many more options too.&nbsp; See my <a title="What is Mobile Marketing" href="http://www.slideshare.net/courane01/what-is-mobile-marketing-7900735">Slideshare</a> for more ideas.</p>' );

		$this->assertSame( [], Presentation_Classifier::signals( $post )['deck'] );
		$this->assertFalse( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_notist_and_speaker_deck_links_only_are_not_presentations(): void {
		$post = $this->post( '<p>Slides on <a href="https://noti.st/courtneyr/AbC123">Notist</a> and <a href="' . self::SPEAKER_DECK_URL . '">Speaker Deck</a>.</p>' );

		$this->assertSame( [], Presentation_Classifier::signals( $post )['deck'] );
		$this->assertFalse( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_podcast_host_and_audio_embeds_are_not_presentations(): void {
		$post = $this->post(
			self::embed_block( 'https://wpbuilds.com/2023/01/12/episode-310/', 'wp-builds', 'rich' )
			. "\n\n" . self::embed_block( 'https://soundcloud.com/courtneyr/episode', 'soundcloud', 'rich' )
		);

		$signals = Presentation_Classifier::signals( $post );
		$this->assertSame( [], $signals['deck'] );
		$this->assertSame( [], $signals['talk_recording'] );
		$this->assertSame( [], $signals['recording'] );
		$this->assertFalse( Presentation_Classifier::is_presentation( $post ) );
	}

	// --- talk recording -----------------------------------------------------

	public function test_wordpress_tv_embed_block_is_a_talk_recording(): void {
		// Post 7184 uses wordpress-tv-embed; core's variation slug is wordpress-tv.
		foreach ( [ 'wordpress-tv-embed', 'wordpress-tv' ] as $slug ) {
			$post    = $this->post( self::embed_block( self::WPTV_URL, $slug ) );
			$signals = Presentation_Classifier::signals( $post );

			$this->assertSame( [ self::WPTV_URL ], $signals['talk_recording'], $slug );
			$this->assertTrue( Presentation_Classifier::is_presentation( $post ), $slug );
		}
	}

	public function test_wordpress_tv_iframe_is_a_talk_recording(): void {
		$post = $this->post( self::html_block( '<iframe width="640" height="360" src="https://wordpress.tv/embed/abc123/" frameborder="0" allowfullscreen></iframe>' ) );

		$this->assertSame( [ 'https://wordpress.tv/embed/abc123/' ], Presentation_Classifier::signals( $post )['talk_recording'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	// --- recording alone never classifies ---------------------------------

	public function test_recording_embed_alone_is_not_a_presentation(): void {
		$cases = [
			'youtube'     => self::YOUTUBE_URL,
			'videopress'  => 'https://videopress.com/v/6cnjGmW3',
			'dailymotion' => 'https://www.dailymotion.com/video/x8abc12',
			'vimeo'       => 'https://vimeo.com/123456789',
		];

		foreach ( $cases as $slug => $url ) {
			$post    = $this->post( self::embed_block( $url, $slug ) );
			$signals = Presentation_Classifier::signals( $post );

			$this->assertSame( [ $url ], $signals['recording'], $slug );
			$this->assertFalse( Presentation_Classifier::is_presentation( $post ), $slug );
		}
	}

	// --- podcast rule -------------------------------------------------------

	public function test_listen_card_with_youtube_stays_a_podcast(): void {
		$post    = $this->post( self::listen_card() . "\n\n" . self::embed_block( self::YOUTUBE_URL, 'youtube' ) );
		$signals = Presentation_Classifier::signals( $post );

		$this->assertTrue( $signals['listen'] );
		$this->assertSame( [ self::YOUTUBE_URL ], $signals['recording'] );
		$this->assertFalse( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_listen_card_with_a_deck_is_a_presentation(): void {
		$post = $this->post( self::listen_card() . "\n\n" . self::embed_block( self::SPEAKER_DECK_URL, 'speaker-deck', 'rich' ) );

		$this->assertTrue( Presentation_Classifier::signals( $post )['listen'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_listen_kind_with_youtube_stays_a_podcast(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => self::embed_block( self::YOUTUBE_URL, 'youtube' ) ] );
		wp_set_post_terms( $post_id, [ 'listen' ], Taxonomy::TAXONOMY );
		$post = get_post( $post_id );

		$this->assertTrue( Presentation_Classifier::signals( $post )['listen'] );
		$this->assertFalse( Presentation_Classifier::is_presentation( $post ) );
	}

	// --- authored presentation fields --------------------------------------

	public function test_authored_event_name_with_youtube_is_a_presentation(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => self::embed_block( self::YOUTUBE_URL, 'youtube' ) ] );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'WordCamp Montclair 2023' );
		$post    = get_post( $post_id );
		$signals = Presentation_Classifier::signals( $post );

		$this->assertTrue( $signals['presentation_meta'] );
		$this->assertSame( [ self::YOUTUBE_URL ], $signals['recording'] );
		$this->assertTrue( Presentation_Classifier::is_presentation( $post ) );
	}

	public function test_authored_presentation_fields(): void {
		$slides = self::factory()->post->create();
		update_post_meta( $slides, '_pkiw_presentation_slides_url', 'https://example.com/slides.pdf' );

		$event_url = self::factory()->post->create();
		update_post_meta( $event_url, '_pkiw_presentation_event_url', 'https://montclair.wordcamp.org/2023/' );

		$pair = self::factory()->post->create();
		update_post_meta( $pair, '_pkiw_presentation_calendar_source', 'the-events-calendar' );
		update_post_meta( $pair, '_pkiw_presentation_calendar_event_id', 42 );

		$source_only = self::factory()->post->create();
		update_post_meta( $source_only, '_pkiw_presentation_calendar_source', 'my-calendar' );

		// The recording field is filled from YouTube embeds, so it must not classify.
		$recording_only = self::factory()->post->create();
		update_post_meta( $recording_only, '_pkiw_presentation_recording_url', self::YOUTUBE_URL );

		$this->assertTrue( Presentation_Classifier::is_presentation( get_post( $slides ) ) );
		$this->assertTrue( Presentation_Classifier::is_presentation( get_post( $event_url ) ) );
		$this->assertTrue( Presentation_Classifier::is_presentation( get_post( $pair ) ) );
		$this->assertFalse( Presentation_Classifier::is_presentation( get_post( $source_only ) ), 'a calendar source needs an event id' );
		$this->assertFalse( Presentation_Classifier::is_presentation( get_post( $recording_only ) ), 'a recording URL alone is not a talk' );
	}

	public function test_plain_post_has_no_signals(): void {
		$post = $this->post( '<!-- wp:paragraph --><p>Notes from the meetup.</p><!-- /wp:paragraph -->' );

		$this->assertSame(
			[
				'deck'              => [],
				'talk_recording'    => [],
				'recording'         => [],
				'presentation_meta' => false,
				'listen'            => false,
			],
			Presentation_Classifier::signals( $post )
		);
		$this->assertFalse( Presentation_Classifier::is_presentation( $post ) );
	}
}
