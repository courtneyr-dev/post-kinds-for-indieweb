<?php
/**
 * Presentation fields, facts and single record.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Integrations\Calendar_Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own the presentation kind's authored data and visitor-safe record. */
final class Presentation {

	public const KIND            = 'presentation';
	public const RECORD_BLOCK    = 'post-kinds-indieweb/presentation-record';
	public const EVENT_TYPE_KEY  = '_pkiw_presentation_event_type';
	public const SLIDES_KEY      = '_pkiw_presentation_slides_url';
	public const RECORDING_KEY   = '_pkiw_presentation_recording_url';
	public const EVENT_NAME_KEY  = '_pkiw_presentation_event_name';
	public const EVENT_URL_KEY   = '_pkiw_presentation_event_url';
	public const CALENDAR_KEY    = '_pkiw_presentation_calendar_source';
	public const CALENDAR_ID_KEY = '_pkiw_presentation_calendar_event_id';

	/** Wire facts, save fill, the single record and editor data. */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_action( 'wp_after_insert_post', [ self::class, 'fill_on_save' ], 20, 2 );
		add_filter( 'the_content', [ self::class, 'prepend_record' ], 8 );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'editor_data' ], 20 );
		if ( did_action( 'init' ) ) {
			self::register_record_block();
		} else {
			add_action( 'init', [ self::class, 'register_record_block' ] );
		}
	}

	/**
	 * Filterable event types, cleaned after filtering.
	 *
	 * @return array<string, string>
	 */
	public static function event_types(): array {
		$defaults = [
			'talk'           => __( 'Talk', 'post-kinds-for-indieweb-in-block-themes' ),
			'keynote'        => __( 'Keynote', 'post-kinds-for-indieweb-in-block-themes' ),
			'workshop'       => __( 'Workshop', 'post-kinds-for-indieweb-in-block-themes' ),
			'panel'          => __( 'Panel', 'post-kinds-for-indieweb-in-block-themes' ),
			'lightning-talk' => __( 'Lightning talk', 'post-kinds-for-indieweb-in-block-themes' ),
			'meetup'         => __( 'Meetup', 'post-kinds-for-indieweb-in-block-themes' ),
			'podcast-guest'  => __( 'Podcast guest', 'post-kinds-for-indieweb-in-block-themes' ),
		];
		$filtered = apply_filters( 'pkiw_presentation_event_types', $defaults );
		$clean    = [];
		foreach ( is_array( $filtered ) ? $filtered : [] as $slug => $label ) {
			$slug  = sanitize_key( (string) $slug );
			$label = sanitize_text_field( is_scalar( $label ) ? (string) $label : '' );
			if ( '' !== $slug && '' !== $label ) {
				$clean[ $slug ] = $label;
			}
		}
		return $clean;
	}

	/**
	 * Return today's label for a stored type slug.
	 *
	 * @param string $slug Stored event-type slug.
	 */
	public static function event_type_label( string $slug ): string {
		return self::event_types()[ $slug ] ?? '';
	}

	/**
	 * Fill empty presentation URLs from authored content after kind sync.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Saved post.
	 */
	public static function fill_on_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! is_object_in_taxonomy( $post->post_type, Taxonomy::TAXONOMY ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return;
		}

		$signals = Presentation_Classifier::signals( $post );
		if ( ! $signals['listen'] && '' === (string) get_post_meta( $post_id, self::SLIDES_KEY, true ) && ! empty( $signals['deck'][0] ) ) {
			update_post_meta( $post_id, self::SLIDES_KEY, $signals['deck'][0] );
		}
		if ( '' !== (string) get_post_meta( $post_id, self::RECORDING_KEY, true ) ) {
			return;
		}
		$recording = (string) ( $signals['talk_recording'][0] ?? $signals['recording'][0] ?? '' );
		if ( '' === $recording ) {
			$recording = extract_first_video_url( (string) $post->post_content );
		}
		if ( '' !== $recording ) {
			update_post_meta( $post_id, self::RECORDING_KEY, $recording );
		}
	}

	/**
	 * Resolve a public calendar event without carrying location forward.
	 *
	 * @param string $source Calendar source slug.
	 * @param int    $id     Calendar event ID.
	 * @return array{name:string, url:string, source:string}
	 */
	public static function event_for( string $source, int $id ): array {
		$event = Calendar_Events::get_event( $source, $id );
		if ( ! is_array( $event ) ) {
			return [
				'name'   => '',
				'url'    => '',
				'source' => '',
			];
		}
		return [
			'name'   => sanitize_text_field( (string) ( $event['name'] ?? '' ) ),
			'url'    => web_url( (string) ( $event['url'] ?? '' ) ),
			'source' => 'calendar',
		];
	}

	/**
	 * Return visitor-safe presentation facts.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$event = self::event_for( (string) get_post_meta( $post_id, self::CALENDAR_KEY, true ), absint( get_post_meta( $post_id, self::CALENDAR_ID_KEY, true ) ) );
		if ( '' === $event['source'] ) {
			$event = [
				'name'   => sanitize_text_field( (string) get_post_meta( $post_id, self::EVENT_NAME_KEY, true ) ),
				'url'    => web_url( (string) get_post_meta( $post_id, self::EVENT_URL_KEY, true ) ),
				'source' => 'authored',
			];
			if ( '' === $event['name'] ) {
				$event['url']    = '';
				$event['source'] = '';
			}
		}

		$signals   = Presentation_Classifier::signals( $post );
		$slides    = web_url( (string) get_post_meta( $post_id, self::SLIDES_KEY, true ) );
		$recording = web_url( (string) get_post_meta( $post_id, self::RECORDING_KEY, true ) );
		if ( '' === $slides ) {
			$slides = web_url( (string) ( $signals['deck'][0] ?? '' ) );
		}
		if ( '' === $recording ) {
			$recording = web_url( (string) ( $signals['talk_recording'][0] ?? $signals['recording'][0] ?? extract_first_video_url( (string) $post->post_content ) ) );
		}
		$type  = sanitize_key( (string) get_post_meta( $post_id, self::EVENT_TYPE_KEY, true ) );
		$label = self::event_type_label( $type );
		if ( '' === $label ) {
			$type = '';
		}
		[ $date_ymd, $date_display ] = card_calendar_date( get_the_date( 'Y-m-d', $post ) );

		return [
			'event_name'       => $event['name'],
			'event_url'        => $event['url'],
			'event_source'     => $event['source'],
			'event_type'       => $type,
			'event_type_label' => $label,
			'slides_url'       => $slides,
			'recording_url'    => $recording,
			'date_iso'         => get_the_date( 'c', $post ),
			'date_ymd'         => $date_ymd,
			'date_display'     => $date_display,
			'title'            => visitor_post_title( $post ),
		];
	}

	/**
	 * Render a presentation record from supplied facts only.
	 *
	 * @param array<string, mixed> $facts Visitor-safe facts.
	 * @param array<string, mixed> $args  Record display arguments.
	 */
	public static function record_html( array $facts, array $args = [] ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- The renderer conditionally emits a fixed set of independent rows.
		if ( [] === $facts ) {
			return '';
		}
		$args  = wp_parse_args(
			$args,
			[
				'title_level' => 0,
				'show_date'   => false,
				'class'       => '',
			]
		);
		$level = max( 0, min( 6, (int) $args['title_level'] ) );
		$parts = preg_split( '/\s+/', (string) $args['class'] );
		$class = implode( ' ', array_filter( array_map( 'sanitize_html_class', is_array( $parts ) ? $parts : [] ) ) );
		$html  = '<section class="pkiw-presentation-record' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" data-pkiw-event-type="' . esc_attr( (string) ( $facts['event_type'] ?? '' ) ) . '"';
		if ( $level > 0 ) {
			$html .= ' aria-labelledby="pkiw-presentation-record-title"';
		}
		$html .= '>';
		$title = (string) ( $facts['title'] ?? '' );
		if ( $level > 0 ) {
			$html .= '<h' . $level . ' class="pkiw-presentation-record__title p-name" id="pkiw-presentation-record-title">' . esc_html( $title ) . '</h' . $level . '>';
		}
		$rows = '';
		$name = (string) ( $facts['event_name'] ?? '' );
		$url  = (string) ( $facts['event_url'] ?? '' );
		if ( '' !== $name ) {
			$value = '' !== $url ? '<a class="pkiw-presentation-record__event" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
			$rows .= '<dt>' . esc_html__( 'Event', 'post-kinds-for-indieweb-in-block-themes' ) . '</dt><dd>' . $value . '</dd>';
		}
		if ( ! empty( $args['show_date'] ) && '' !== (string) ( $facts['date_iso'] ?? '' ) ) {
			$rows .= '<dt>' . esc_html__( 'Date', 'post-kinds-for-indieweb-in-block-themes' ) . '</dt><dd><time class="dt-published" datetime="' . esc_attr( (string) $facts['date_iso'] ) . '">' . esc_html( (string) ( $facts['date_display'] ?? '' ) ) . '</time></dd>';
		}
		if ( '' !== (string) ( $facts['event_type_label'] ?? '' ) ) {
			$rows .= '<dt>' . esc_html__( 'Type', 'post-kinds-for-indieweb-in-block-themes' ) . '</dt><dd>' . esc_html( (string) $facts['event_type_label'] ) . '</dd>';
		}
		if ( '' !== $rows ) {
			$html .= '<dl class="pkiw-presentation-record__facts">' . $rows . '</dl>';
		}
		$links = '';
		foreach ( [
			'slides_url'    => __( 'Slides', 'post-kinds-for-indieweb-in-block-themes' ),
			'recording_url' => __( 'Recording', 'post-kinds-for-indieweb-in-block-themes' ),
		] as $key => $label ) {
			if ( '' !== (string) ( $facts[ $key ] ?? '' ) ) {
				/* translators: %s: presentation title. */
				$hidden = sprintf( __( ' for %s', 'post-kinds-for-indieweb-in-block-themes' ), $title );
				$links .= '<a href="' . esc_url( (string) $facts[ $key ] ) . '">' . esc_html( $label ) . '<span class="screen-reader-text">' . esc_html( $hidden ) . '</span></a> ';
			}
		}
		if ( '' !== $links ) {
			$html .= '<p class="pkiw-presentation-record__links">' . rtrim( $links ) . '</p>';
		}
		return $html . '</section>';
	}

	/** Register the dynamic single record block. */
	public static function register_record_block(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::RECORD_BLOCK ) ) {
			return;
		}
		wp_register_style( 'pkiw-presentation', \PKIW_URL . 'styles/presentation.css', [], \PKIW_VERSION );
		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Presentation record', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_record_block' ],
			'uses_context'    => [ 'postId', 'postType' ],
			'attributes'      => [ 'className' => [ 'type' => 'string' ] ],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
				'inserter' => false,
			],
			'style'           => 'pkiw-presentation',
		];
		register_block_type( self::RECORD_BLOCK, $args );
	}

	/**
	 * Render the record block for its post context.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused saved content.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render_record_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$args = apply_filters(
			'pkiw_presentation_record_args',
			[
				'title_level' => 0,
				'show_date'   => false,
				'class'       => '',
			],
			$post
		);
		return self::record_html( self::facts( $post_id ), is_array( $args ) ? $args : [] );
	}

	/**
	 * Prepend the record block comment on the readable HTML single only.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public static function prepend_record( $content ) {
		if ( ! is_string( $content ) || ! is_singular( 'post' ) || is_feed() || null !== get_query_var( 'atproto', null ) || '' !== (string) get_query_var( 'markdown_request', '' ) || 'markdown' === get_query_var( 'format', '' ) || ( function_exists( '\\Activitypub\\is_activitypub_request' ) && \Activitypub\is_activitypub_request() ) || doing_filter( 'get_excerpt' ) || doing_filter( 'get_the_excerpt' ) || doing_action( 'wp_head' ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || get_queried_object_id() !== $post->ID || post_password_required( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) || ! \WP_Block_Type_Registry::get_instance()->is_registered( self::RECORD_BLOCK ) || str_contains( $content, 'wp:' . self::RECORD_BLOCK ) ) {
			return $content;
		}
		if ( doing_filter( 'the_content' ) && ! has_blocks( $content ) ) {
			$content = wpautop( $content );
		}
		return '<!-- wp:' . self::RECORD_BLOCK . ' /-->' . "\n\n" . $content;
	}

	/** Print the filtered type list before the bundled editor script. */
	public static function editor_data(): void {
		$types = [];
		foreach ( self::event_types() as $slug => $label ) {
			$types[] = [
				'slug'  => $slug,
				'label' => $label,
			];
		}
		wp_add_inline_script( 'post-kinds-indieweb-editor', 'window.pkiwPresentationEventTypes = ' . wp_json_encode( $types ) . ';', 'before' );
	}
}
