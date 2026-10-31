<?php
/**
 * RSVP archive grouping, facts and entry block.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the public RSVP archive contract.
 */
final class Rsvp_Archive {

	public const KIND        = 'rsvp';
	public const SOURCE      = 'rsvp_timing';
	public const ENTRY_BLOCK = 'post-kinds-indieweb/rsvp-entry';
	public const SORT_KEY    = '_pkiw_rsvp_sort';
	public const PER_PAGE    = 9;
	public const GROUPS      = [ 'upcoming', 'past' ];

	/** Wire RSVP grouping, facts, blocks and derived-sort refreshes. */
	public function register(): void {
		Grouped_Archive::register_source( new Grouping\Rsvp_Timing_Source(), self::KIND );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ], [ 'location' => 'name' ] );
		add_filter( 'pkiw_archive_sections_groups', [ self::class, 'section_groups' ] );
		add_filter( 'pkiw_kind_archive_preview_per_page', [ self::class, 'preview_per_page' ], 10, 2 );
		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', [ self::class, 'register_blocks' ] );
		}
		add_action( 'wp_after_insert_post', [ self::class, 'post_inserted' ], 20, 4 );
		add_action( 'set_object_terms', [ self::class, 'terms_set' ], 10, 6 );
		add_action( 'deleted_term_relationships', [ self::class, 'term_relationships_deleted' ], 10, 3 );
		add_action( 'added_post_meta', [ self::class, 'meta_changed' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'meta_changed' ], 10, 4 );
		add_action( 'deleted_post_meta', [ self::class, 'meta_changed' ], 10, 4 );
		add_filter( 'pkiw_card_meta_backfill_kinds', [ self::class, 'backfill_kinds' ] );
		add_action( 'pkiw_card_meta_backfill_post', [ self::class, 'backfill_post' ], 10, 2 );
	}

	/**
	 * Human-readable timing group labels.
	 *
	 * @return array<string, string>
	 */
	public static function group_labels(): array {
		return [
			'upcoming' => __( 'Upcoming', 'post-kinds-for-indieweb-in-block-themes' ),
			'past'     => __( 'Past', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}

	/**
	 * Add RSVP labels to the archive editor's group maps.
	 *
	 * @param mixed $groups Existing group maps.
	 * @return array<string, mixed>
	 */
	public static function section_groups( $groups ): array {
		$groups               = is_array( $groups ) ? $groups : [];
		$groups[ self::KIND ] = self::group_labels();
		return $groups;
	}

	/**
	 * Set the RSVP archive preview page size.
	 *
	 * @param int    $per_page Current size.
	 * @param string $kind     Kind slug.
	 * @return int
	 */
	public static function preview_per_page( int $per_page, string $kind ): int {
		return self::KIND === $kind ? self::PER_PAGE : $per_page;
	}

	/** Return one filtered UTC boundary for the request. */
	public static function now(): string {
		static $now = null;
		if ( null === $now ) {
			$now = gmdate( 'Y-m-d H:i:s' );
		}
		$filtered = apply_filters( 'pkiw_rsvp_now', $now );
		if ( ! is_string( $filtered ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $filtered ) ) {
			return $now;
		}
		return $filtered;
	}

	/**
	 * Convert a card wall-clock start to the UTC archive sort value.
	 *
	 * @param string $start Stored event start.
	 * @return string
	 */
	public static function sort_value( string $start ): string {
		$date = card_datetime( $start );
		if ( $date instanceof \DateTimeImmutable ) {
			return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		if ( 1 === preg_match( '/[Tt ]\d{2}:\d{2}/', trim( $start ) ) ) {
			return '';
		}
		[ $day ] = card_calendar_date( $start );
		if ( '' === $day ) {
			return '';
		}
		$date = date_create_immutable_from_format( '!Y-m-d', $day, wp_timezone() );
		return $date instanceof \DateTimeImmutable
			? $date->setTime( 23, 59, 59 )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
			: '';
	}

	/**
	 * Read ungated RSVP source data with card-first precedence.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function raw( int $post_id ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- One precedence pass reads the correlated card fields.
		$post  = get_post( $post_id );
		$block = $post instanceof \WP_Post ? self::first_card( parse_blocks( (string) $post->post_content ) ) : null;
		$attrs = is_array( $block ) && is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$card  = null !== $block;
		$name  = $card ? sanitize_text_field( (string) ( $attrs['eventName'] ?? '' ) ) : '';
		$url   = $card ? web_url( (string) ( $attrs['eventUrl'] ?? '' ) ) : '';
		if ( '' === $name ) {
			$name = sanitize_text_field( (string) get_post_meta( $post_id, '_pkiw_cite_name', true ) );
		}
		if ( '' === $name ) {
			$name = sanitize_text_field( (string) get_post_meta( $post_id, '_pkiw_event_name', true ) );
		}
		if ( '' === $url ) {
			$url = web_url( (string) get_post_meta( $post_id, '_pkiw_cite_url', true ) );
		}
		if ( '' === $url ) {
			$url = web_url( (string) get_post_meta( $post_id, '_pkiw_event_url', true ) );
		}
		$status = $card ? (string) ( $attrs['rsvpStatus'] ?? 'yes' ) : (string) get_post_meta( $post_id, '_pkiw_rsvp_status', true );
		if ( ! $card && '' === $status ) {
			$status = (string) get_post_meta( $post_id, '_pkiw_rsvp_value', true );
		}
		if ( ! array_key_exists( $status, rsvp_status_labels() ) ) {
			$status = '';
		}
		$location        = $card ? sanitize_text_field( (string) ( $attrs['eventLocation'] ?? '' ) ) : sanitize_text_field( (string) get_post_meta( $post_id, '_pkiw_event_location', true ) );
		$location_public = ( ! $card || 'public' === ( $attrs['locationVisibility'] ?? 'private' ) ) && Meta_Fields::rsvp_location_visible( $post_id );

		return [
			'has_card'        => $card,
			'name'            => $name,
			'url'             => $url,
			'status'          => $status,
			'start'           => $card ? sanitize_text_field( (string) ( $attrs['eventStart'] ?? '' ) ) : sanitize_text_field( (string) get_post_meta( $post_id, '_pkiw_event_start', true ) ),
			'end'             => $card ? sanitize_text_field( (string) ( $attrs['eventEnd'] ?? '' ) ) : sanitize_text_field( (string) get_post_meta( $post_id, '_pkiw_event_end', true ) ),
			'location'        => $location,
			'location_public' => $location_public,
			'description'     => $card ? sanitize_text_field( (string) ( $attrs['eventDescription'] ?? '' ) ) : '',
			'note'            => $card ? wp_kses_post( (string) ( $attrs['rsvpNote'] ?? '' ) ) : '',
			'rsvp_at'         => $card ? sanitize_text_field( (string) ( $attrs['rsvpAt'] ?? '' ) ) : '',
			'image'           => $card ? web_url( (string) ( $attrs['eventImage'] ?? '' ) ) : '',
			'image_alt'       => $card ? sanitize_text_field( (string) ( $attrs['eventImageAlt'] ?? '' ) ) : '',
		];
	}

	/**
	 * Return visitor-safe RSVP facts.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}
		$raw                                 = self::raw( $post_id );
		$title                               = pkiw_get_safe_title( $post_id );
		$name                                = '' !== $raw['name'] ? (string) $raw['name'] : $title;
		[ $start_iso ]                       = card_wall_clock( (string) $raw['start'] );
		[ $end_iso ]                         = card_wall_clock( (string) $raw['end'] );
		$start_date                          = card_datetime( (string) $raw['start'] );
		$end_date                            = card_datetime( (string) $raw['end'] );
		[ $calendar_day, $calendar_display ] = card_calendar_date( (string) $raw['start'] );
		$all_day                             = '' !== $calendar_day && 1 !== preg_match( '/[Tt ]\d{2}:\d{2}/', (string) $raw['start'] );
		$start_day                           = $start_date instanceof \DateTimeImmutable ? $start_date->format( 'Y-m-d' ) : ( $all_day ? $calendar_day : '' );
		$day_display                         = $start_date instanceof \DateTimeImmutable ? (string) wp_date( (string) get_option( 'date_format' ), $start_date->getTimestamp(), $start_date->getTimezone() ) : ( $all_day ? $calendar_display : '' );
		$time_display                        = '';
		if ( $start_date instanceof \DateTimeImmutable ) {
			$time_display = (string) wp_date( (string) get_option( 'time_format' ), $start_date->getTimestamp(), $start_date->getTimezone() );
			if ( '' !== $end_iso ) {
				$end_display   = $end_date instanceof \DateTimeImmutable && $start_day === $end_date->format( 'Y-m-d' )
					? (string) wp_date( (string) get_option( 'time_format' ), $end_date->getTimestamp(), $end_date->getTimezone() )
					: card_wall_clock( (string) $raw['end'] )[1];
				$time_display .= ' – ' . $end_display;
			}
		}
		$sort                              = self::sort_value( (string) $raw['start'] );
		[ $rsvp_at_iso, $rsvp_at_display ] = card_wall_clock( (string) $raw['rsvp_at'], (string) get_option( 'date_format' ) );
		$image_alt                         = (string) $raw['image_alt'];
		if ( '' === $image_alt && '' !== $name ) {
			/* translators: %s: event name. */
			$image_alt = sprintf( __( '%s event', 'post-kinds-for-indieweb-in-block-themes' ), $name );
		}

		return [
			'name'            => $name,
			'name_is_title'   => '' === $raw['name'],
			'title'           => $title,
			'url'             => (string) $raw['url'],
			'url_host'        => url_host( (string) $raw['url'] ),
			'has_card'        => (bool) $raw['has_card'],
			'status'          => (string) $raw['status'],
			'status_label'    => rsvp_status_labels()[ (string) $raw['status'] ] ?? '',
			'start_iso'       => $start_iso,
			'start_day'       => $start_day,
			'day_display'     => $day_display,
			'time_display'    => $time_display,
			'end_iso'         => $end_iso,
			'all_day'         => $all_day,
			'sort'            => $sort,
			'group'           => '' !== $sort && $sort >= self::now() ? 'upcoming' : 'past',
			'location'        => $raw['location_public'] ? (string) $raw['location'] : '',
			'description'     => (string) $raw['description'],
			'note'            => (string) $raw['note'],
			'rsvp_at_iso'     => $rsvp_at_iso,
			'rsvp_at_display' => $rsvp_at_display,
			'image'           => (string) $raw['image'],
			'image_alt'       => $image_alt,
			'image_local'     => '' !== $raw['image'] && is_upload_url( (string) $raw['image'] ),
			'date_iso'        => get_post_time( 'c', true, $post ),
		];
	}

	/**
	 * Determine a post's group without public-read gating.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function group_of_post( \WP_Post $post ): string {
		if ( '' !== (string) $post->post_password ) {
			return 'past';
		}
		$sort = self::sort_value( (string) self::raw( $post->ID )['start'] );
		return '' !== $sort && $sort >= self::now() ? 'upcoming' : 'past';
	}

	/**
	 * Refresh or remove the derived sort row.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function refresh_sort( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			delete_post_meta( $post_id, self::SORT_KEY );
			return;
		}
		$value = self::sort_value( (string) self::raw( $post_id )['start'] );
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::SORT_KEY );
		} elseif ( get_metadata_raw( 'post', $post_id, self::SORT_KEY, true ) !== $value ) {
			update_post_meta( $post_id, self::SORT_KEY, $value );
		}
	}

	/**
	 * Fill a missing derived sort without changing an existing row.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function fill_sort( int $post_id ): void {
		if ( metadata_exists( 'post', $post_id, self::SORT_KEY ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return;
		}
		$value = self::sort_value( (string) self::raw( $post_id )['start'] );
		if ( '' !== $value ) {
			add_post_meta( $post_id, self::SORT_KEY, $value, true );
		}
	}

	/**
	 * Plan absent RSVP rows without writing.
	 *
	 * @param int $post_id Post ID.
	 * @return list<array{op:string, post_id:int, key:string, value:string}>
	 */
	public static function backfill_plan( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return [];
		}
		$raw  = self::raw( $post_id );
		$kind = has_term( self::KIND, Taxonomy::TAXONOMY, $post );
		if ( ! $raw['has_card'] && ! $kind ) {
			return [];
		}
		$plan = [];
		if ( $raw['has_card'] ) {
			$values = [
				'cite_name'   => (string) $raw['name'],
				'cite_url'    => (string) $raw['url'],
				'rsvp_status' => (string) $raw['status'],
				'event_start' => self::accepted_wall_clock( (string) $raw['start'] ),
				'event_end'   => self::accepted_wall_clock( (string) $raw['end'] ),
			];
			foreach ( $values as $suffix => $value ) {
				$key = Meta_Fields::PREFIX . $suffix;
				if ( '' !== $value && ! metadata_exists( 'post', $post_id, $key ) ) {
					$plan[] = [
						'op'      => 'insert_meta',
						'post_id' => $post_id,
						'key'     => $key,
						'value'   => $value,
					];
				}
			}
		}
		$sort = self::sort_value( (string) $raw['start'] );
		if ( $kind && '' !== $sort && ! metadata_exists( 'post', $post_id, self::SORT_KEY ) ) {
			$plan[] = [
				'op'      => 'insert_meta',
				'post_id' => $post_id,
				'key'     => self::SORT_KEY,
				'value'   => $sort,
			];
		}
		return $plan;
	}

	/** Register the dynamic RSVP entry block. */
	public static function register_blocks(): void {
		wp_register_script( 'pkiw-rsvp-entry-editor', \PKIW_URL . 'assets/js/rsvp-entry-editor.js', [ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ], \PKIW_VERSION, true );
		wp_set_script_translations( 'pkiw-rsvp-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );
		$before = wp_scripts()->get_data( 'pkiw-rsvp-entry-editor', 'before' );
		$inline = implode( "\n", is_array( $before ) ? $before : [] );
		if ( ! str_contains( $inline, 'window.pkiwRsvpEntry' ) ) {
			wp_add_inline_script( 'pkiw-rsvp-entry-editor', 'window.pkiwRsvpEntry = ' . wp_json_encode( [ 'groups' => self::group_labels() ] ) . ';', 'before' );
		}
		Grouped_Archive::register_entry_block(
			self::ENTRY_BLOCK,
			[
				'shape'       => Grouped_Archive::ENTRY_LINE,
				'heading_ids' => true,
				'expose_key'  => true,
				'list'        => 'ul',
				'classes'     => [
					'wrapper'     => 'pkiw-rsvp--sectioned',
					'section'     => 'pkiw-rsvp-section',
					'heading'     => 'pkiw-rsvp-section__heading',
					'items'       => 'pkiw-rsvp-section__items',
					'placeholder' => 'pkiw-rsvp-entry pkiw-rsvp-entry--continued',
				],
			]
		);
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			return;
		}
		register_block_type(
			self::ENTRY_BLOCK,
			[
				'api_version'     => 3,
				'title'           => __( 'RSVP entry', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => [ self::class, 'render_entry_block' ],
				'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
				'attributes'      => Grouped_Archive::entry_attributes(
					[
						'linesPerPage' => [ 'default' => 9 ],
						'headingLevel' => [ 'default' => 2 ],
						'groupLabels'  => [
							'type'    => 'object',
							'default' => [],
							'role'    => 'content',
						],
						'emptyLabel'   => [ 'role' => 'content' ],
					]
				),
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-rsvp-entry-editor',
				'style'           => 'pkiw-kind-layouts',
				'example'         => [ 'attributes' => [ 'linesPerPage' => 9 ] ],
			]
		);
	}

	/**
	 * Render through the shared grouped archive engine.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused content.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string
	 */
	public static function render_entry_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry( self::ENTRY_BLOCK, $attributes, $block, static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post, $attributes, $in_section ) ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	}

	/**
	 * Render one RSVP archive item.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether the item follows a section heading.
	 * @return string
	 */
	public static function entry_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$level     = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$protected = '' !== (string) $post->post_password;
		$date_iso  = get_post_time( 'c', true, $post );
		if ( $protected ) {
			$name = '' !== trim( (string) $post->post_title ) ? visitor_post_title( $post ) : untitled_name( $post, false );
			return '<article class="pkiw-rsvp-entry pkiw-rsvp-entry--past pkiw-rsvp-entry--protected h-entry"><h' . $level . ' class="pkiw-rsvp-entry__name"><a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $name ) . '</a></h' . $level . '><time class="dt-published" datetime="' . esc_attr( $date_iso ) . '" hidden></time></article>';
		}
		$facts = self::facts( $post->ID );
		if ( [] === $facts ) {
			return '';
		}
		$name_id  = 'pkiw-rsvp-' . $post->ID . '-name';
		$day_id   = 'pkiw-rsvp-' . $post->ID . '-day';
		$repeated = self::name_repeats( (string) $facts['name'], $post->ID );
		$labelled = $repeated && '' !== $facts['start_day'] ? ' aria-labelledby="' . esc_attr( $name_id . ' ' . $day_id ) . '"' : '';
		$html     = '<article class="pkiw-rsvp-entry pkiw-rsvp-entry--' . esc_attr( (string) $facts['group'] ) . ' h-entry" data-pkiw-rsvp-status="' . esc_attr( (string) $facts['status'] ) . '">';
		if ( 'upcoming' === $facts['group'] ) {
			$html .= '<figure class="pkiw-rsvp-entry__poster" aria-hidden="true">';
			if ( $facts['image_local'] ) {
				$attachment = attachment_url_to_postid( (string) $facts['image'] );
				$size       = $attachment ? wp_get_attachment_image_src( $attachment, 'full' ) : false;
				if ( is_array( $size ) ) {
					$high  = self::is_first_three_upcoming( $post->ID );
					$html .= '<img class="pkiw-rsvp-entry__image" src="' . esc_url( (string) $facts['image'] ) . '" alt="" width="' . (int) $size[1] . '" height="' . (int) $size[2] . '" loading="' . ( $high ? 'eager' : 'lazy' ) . '" fetchpriority="' . ( $high ? 'high' : 'auto' ) . '">';
				} else {
					$html .= '<span class="pkiw-rsvp-entry__poster-name">' . esc_html( (string) $facts['name'] ) . '</span>';
				}
			} else {
				$html .= '<span class="pkiw-rsvp-entry__poster-name">' . esc_html( (string) $facts['name'] ) . '</span>';
			}
			$html .= '</figure>';
		}
		$html .= '<h' . $level . ' class="pkiw-rsvp-entry__name"><a class="u-url" id="' . esc_attr( $name_id ) . '" href="' . esc_url( (string) get_permalink( $post ) ) . '"' . $labelled . '>' . esc_html( (string) $facts['name'] ) . '</a></h' . $level . '>';
		if ( '' !== $facts['start_day'] ) {
			$html .= '<time class="pkiw-rsvp-entry__day" id="' . esc_attr( $day_id ) . '" datetime="' . esc_attr( (string) $facts['start_day'] ) . '">' . esc_html( (string) $facts['day_display'] ) . '</time>';
		}
		if ( '' !== $facts['status'] ) {
			$html .= '<data class="p-rsvp pkiw-rsvp-entry__status" value="' . esc_attr( (string) $facts['status'] ) . '">' . esc_html( (string) $facts['status_label'] ) . '</data>';
		}
		$html  .= '<time class="dt-published" datetime="' . esc_attr( $date_iso ) . '" hidden></time>';
		$author = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';
		if ( '' !== $author ) {
			$html .= '<span hidden>' . $author . '</span>';
		}
		if ( '' !== $facts['url'] ) {
			$html .= '<data class="u-in-reply-to" value="' . esc_url( (string) $facts['url'] ) . '" hidden></data>';
		}
		return $html . '</article>';
	}

	/**
	 * Refresh after a post save.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post.
	 * @param bool          $update      Whether WordPress updated a post.
	 * @param \WP_Post|null $post_before Previous post.
	 * @return void
	 */
	public static function post_inserted( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'post' === $post->post_type && ! wp_is_post_revision( $post_id ) ) {
			self::refresh_sort( $post_id );
		}
	}

	/**
	 * Refresh after replacing terms.
	 *
	 * @param int        $object_id  Object ID.
	 * @param mixed      $terms      Assigned terms.
	 * @param array<int> $tt_ids     Term taxonomy IDs.
	 * @param string     $taxonomy   Taxonomy name.
	 * @param bool       $append     Whether terms were appended.
	 * @param array<int> $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public static function terms_set( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::refresh_sort( $object_id );
		}
	}

	/**
	 * Refresh after removing terms.
	 *
	 * @param int        $object_id Object ID.
	 * @param array<int> $tt_ids    Term taxonomy IDs.
	 * @param string     $taxonomy  Taxonomy name.
	 * @return void
	 */
	public static function term_relationships_deleted( int $object_id, array $tt_ids, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::refresh_sort( $object_id );
		}
	}

	/**
	 * Refresh after event-start metadata changes.
	 *
	 * @param mixed  $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function meta_changed( $meta_id, int $post_id, string $meta_key, $meta_value = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( '_pkiw_event_start' === $meta_key ) {
			self::refresh_sort( $post_id );
		}
	}

	/**
	 * Add RSVP to the shared replay kinds.
	 *
	 * @param mixed $kinds Kind slugs.
	 * @return string[]
	 */
	public static function backfill_kinds( $kinds ): array {
		$kinds   = is_array( $kinds ) ? array_map( 'strval', $kinds ) : [];
		$kinds[] = self::KIND;
		return array_values( array_unique( $kinds ) );
	}

	/**
	 * Fill RSVP rows through the shared replay action.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function backfill_post( int $post_id, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		self::fill_sort( $post_id );
	}

	/**
	 * Find the first RSVP card depth-first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private static function first_card( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( 'post-kinds-indieweb/rsvp-card' === ( $block['blockName'] ?? '' ) ) {
				return $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::first_card( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Keep a wall-clock value only when the card formatter accepts it.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function accepted_wall_clock( string $value ): string {
		return '' !== card_wall_clock( $value )[0] ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Whether this name occurs more than once on the current grouped page.
	 *
	 * @param string $name    Event name.
	 * @param int    $post_id Current post ID.
	 * @return bool
	 */
	private static function name_repeats( string $name, int $post_id ): bool {
		$ids = Grouped_Archive::current_page_ids();
		if ( [] === $ids ) {
			$ids = [ $post_id ];
		}
		$needle = mb_strtolower( $name );
		$count  = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && '' === (string) $post->post_password ) {
				$raw   = self::raw( $post->ID );
				$other = '' !== $raw['name'] ? (string) $raw['name'] : pkiw_get_safe_title( $post->ID );
				if ( mb_strtolower( $other ) === $needle && ++$count > 1 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether an item is one of the first three upcoming posts on page one.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_first_three_upcoming( int $post_id ): bool {
		global $paged;
		if ( max( 1, (int) $paged ) > 1 ) {
			return false;
		}
		$upcoming = [];
		foreach ( Grouped_Archive::current_page_ids() as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && 'upcoming' === self::group_of_post( $post ) ) {
				$upcoming[] = $post->ID;
			}
		}
		if ( [] === $upcoming ) {
			return true;
		}
		return in_array( $post_id, array_slice( $upcoming, 0, 3 ), true );
	}
}
