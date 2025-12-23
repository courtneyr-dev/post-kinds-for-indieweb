<?php
/**
 * Microformats2 Output Filter
 *
 * Filters rendered block output to inject microformats2 classes based on post kind.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microformats output class.
 *
 * Handles injection of microformats2 classes into rendered content
 * based on the post's assigned kind taxonomy term.
 *
 * @since 1.0.0
 */
class Microformats {

	/**
	 * Kind to microformat class mapping.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $kind_formats = array();

	/**
	 * IndieBlocks block names to skip (they already have mf2).
	 *
	 * @var array<string>
	 */
	private array $indieblocks_blocks = array(
		'indieblocks/bookmark',
		'indieblocks/like',
		'indieblocks/reply',
		'indieblocks/repost',
		'indieblocks/context',
		'indieblocks/facepile',
		'indieblocks/location',
		'indieblocks/syndication',
		'indieblocks/link-preview',
	);

	/**
	 * Constructor.
	 *
	 * Sets up microformat definitions and hooks.
	 */
	public function __construct() {
		$this->define_kind_formats();
		$this->register_hooks();
	}

	/**
	 * Define microformat classes for each kind.
	 *
	 * @return void
	 */
	private function define_kind_formats(): void {
		$this->kind_formats = array(
			'note'     => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'content' => 'e-content',
					'date'    => 'dt-published',
				),
			),
			'article'  => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'title'   => 'p-name',
					'content' => 'e-content',
					'date'    => 'dt-published',
					'author'  => 'p-author h-card',
				),
			),
			'reply'    => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'content'    => 'e-content',
					'date'       => 'dt-published',
					'reply-to'   => 'u-in-reply-to',
					'cite'       => 'h-cite',
				),
			),
			'like'     => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'like-of' => 'u-like-of',
					'date'    => 'dt-published',
				),
			),
			'repost'   => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'repost-of' => 'u-repost-of',
					'cite'      => 'h-cite',
					'date'      => 'dt-published',
				),
			),
			'bookmark' => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'bookmark-of' => 'u-bookmark-of',
					'cite'        => 'h-cite',
					'content'     => 'e-content',
					'date'        => 'dt-published',
				),
			),
			'rsvp'     => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'rsvp'     => 'p-rsvp',
					'reply-to' => 'u-in-reply-to',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				),
			),
			'checkin'  => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'checkin'  => 'u-checkin h-card',
					'location' => 'p-location h-card',
					'geo'      => 'p-geo h-geo',
					'name'     => 'p-name',
					'address'  => 'p-adr',
					'street'   => 'p-street-address',
					'locality' => 'p-locality',
					'region'   => 'p-region',
					'country'  => 'p-country-name',
					'lat'      => 'p-latitude',
					'lng'      => 'p-longitude',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				),
			),
			'listen'   => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'listen-of' => 'u-listen-of',
					'cite'      => 'h-cite',
					'name'      => 'p-name',
					'author'    => 'p-author h-card',
					'photo'     => 'u-photo',
					'content'   => 'e-content',
					'date'      => 'dt-published',
				),
			),
			'watch'    => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'watch-of' => 'u-watch-of',
					'cite'     => 'h-cite',
					'name'     => 'p-name',
					'photo'    => 'u-photo',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				),
			),
			'read'     => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'read-of' => 'u-read-of',
					'cite'    => 'h-cite',
					'name'    => 'p-name',
					'author'  => 'p-author',
					'uid'     => 'u-uid',
					'photo'   => 'u-photo',
					'content' => 'e-content',
					'date'    => 'dt-published',
				),
			),
			'event'    => array(
				'root'       => array( 'h-event' ),
				'properties' => array(
					'name'     => 'p-name',
					'start'    => 'dt-start',
					'end'      => 'dt-end',
					'location' => 'p-location',
					'content'  => 'e-content p-description',
					'url'      => 'u-url',
				),
			),
			'photo'    => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'photo'   => 'u-photo',
					'content' => 'e-content',
					'date'    => 'dt-published',
				),
			),
			'video'    => array(
				'root'       => array( 'h-entry' ),
				'properties' => array(
					'video'   => 'u-video',
					'content' => 'e-content',
					'date'    => 'dt-published',
				),
			),
			'review'   => array(
				'root'       => array( 'h-review' ),
				'properties' => array(
					'item'    => 'p-item h-product',
					'name'    => 'p-name',
					'rating'  => 'p-rating',
					'best'    => 'p-best',
					'url'     => 'u-url',
					'photo'   => 'u-photo',
					'content' => 'e-content p-description',
					'date'    => 'dt-published',
				),
			),
			'recipe'   => array(
				'root'       => array( 'h-recipe' ),
				'properties' => array(
					'name'        => 'p-name',
					'photo'       => 'u-photo',
					'author'      => 'p-author h-card',
					'yield'       => 'p-yield',
					'duration'    => 'dt-duration',
					'ingredient'  => 'p-ingredient',
					'instructions'=> 'e-instructions',
					'content'     => 'e-content',
				),
			),
		);

		/**
		 * Filters the kind to microformat mapping.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $kind_formats Microformat definitions.
		 */
		$this->kind_formats = apply_filters( 'reactions_indieweb_kind_formats', $this->kind_formats );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Filter the post content wrapper.
		add_filter( 'post_class', array( $this, 'add_post_classes' ), 10, 3 );

		// Filter rendered blocks for mf2 classes.
		add_filter( 'render_block', array( $this, 'filter_block_output' ), 10, 3 );

		// Add hidden mf2 data elements.
		add_filter( 'the_content', array( $this, 'add_hidden_mf2_data' ), 99 );
	}

	/**
	 * Add microformat classes to post wrapper.
	 *
	 * @param array<string> $classes   Post classes.
	 * @param array<string> $class     Additional classes.
	 * @param int           $post_id   Post ID.
	 * @return array<string> Modified classes.
	 */
	public function add_post_classes( array $classes, array $class, int $post_id ): array {
		$kind = $this->get_post_kind( $post_id );

		if ( ! $kind || ! isset( $this->kind_formats[ $kind ] ) ) {
			// Default to h-entry for posts without a kind.
			$classes[] = 'h-entry';
			return $classes;
		}

		// Add root microformat classes.
		$root_classes = $this->kind_formats[ $kind ]['root'] ?? array( 'h-entry' );
		$classes      = array_merge( $classes, $root_classes );

		// Add kind-specific class.
		$classes[] = 'kind-' . $kind;

		return array_unique( $classes );
	}

	/**
	 * Filter block output to add microformat classes.
	 *
	 * @param string   $block_content Rendered block content.
	 * @param array    $block         Block data.
	 * @param \WP_Block $instance     Block instance.
	 * @return string Modified block content.
	 */
	public function filter_block_output( string $block_content, array $block, \WP_Block $instance ): string {
		// Skip empty content.
		if ( empty( $block_content ) ) {
			return $block_content;
		}

		// Skip IndieBlocks blocks - they already have proper mf2.
		if ( $this->is_indieblocks_block( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		// Get post context.
		$post_id = $instance->context['postId'] ?? get_the_ID();

		if ( ! $post_id ) {
			return $block_content;
		}

		$kind = $this->get_post_kind( $post_id );

		if ( ! $kind || ! isset( $this->kind_formats[ $kind ] ) ) {
			return $block_content;
		}

		// Check for bound blocks and add appropriate mf2 classes.
		$block_content = $this->add_binding_mf2_classes( $block_content, $block, $kind );

		return $block_content;
	}

	/**
	 * Add mf2 classes based on block bindings.
	 *
	 * @param string $content Block content.
	 * @param array  $block   Block data.
	 * @param string $kind    Post kind.
	 * @return string Modified content.
	 */
	private function add_binding_mf2_classes( string $content, array $block, string $kind ): string {
		$bindings = $block['attrs']['metadata']['bindings'] ?? array();

		if ( empty( $bindings ) ) {
			return $content;
		}

		// Map binding keys to mf2 properties.
		$binding_to_mf2 = array(
			'cite_name'        => 'name',
			'cite_url'         => 'url',
			'cite_author'      => 'author',
			'cite_photo'       => 'photo',
			'cite_summary'     => 'content',
			'rsvp_status'      => 'rsvp',
			'checkin_name'     => 'name',
			'listen_track'     => 'name',
			'listen_artist'    => 'author',
			'listen_cover'     => 'photo',
			'watch_title'      => 'name',
			'watch_poster'     => 'photo',
			'read_title'       => 'name',
			'read_author'      => 'author',
			'read_cover'       => 'photo',
			'read_isbn'        => 'uid',
			'event_start'      => 'start',
			'event_end'        => 'end',
			'event_location'   => 'location',
			'review_rating'    => 'rating',
			'review_item_name' => 'item',
		);

		$kind_properties = $this->kind_formats[ $kind ]['properties'] ?? array();

		foreach ( $bindings as $attr => $binding_data ) {
			$source = $binding_data['source'] ?? '';

			if ( Block_Bindings::SOURCE_NAME !== $source ) {
				continue;
			}

			$key = $binding_data['args']['key'] ?? '';

			if ( ! isset( $binding_to_mf2[ $key ] ) ) {
				continue;
			}

			$property = $binding_to_mf2[ $key ];

			if ( ! isset( $kind_properties[ $property ] ) ) {
				continue;
			}

			$mf2_class = $kind_properties[ $property ];

			// Add the mf2 class to the element.
			$content = $this->inject_class( $content, $mf2_class );
		}

		return $content;
	}

	/**
	 * Inject a class into the first element of HTML content.
	 *
	 * @param string $html       HTML content.
	 * @param string $class_name Class to add.
	 * @return string Modified HTML.
	 */
	private function inject_class( string $html, string $class_name ): string {
		// Use regex to add class to first element.
		$pattern = '/^(<[a-z][a-z0-9]*\s*)([^>]*)(>)/i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $class_name ) {
				$tag        = $matches[1];
				$attributes = $matches[2];
				$close      = $matches[3];

				// Check if class attribute exists.
				if ( preg_match( '/class\s*=\s*["\']([^"\']*)["\']/', $attributes, $class_match ) ) {
					// Add to existing class.
					$existing_classes = $class_match[1];

					// Don't add if already present.
					if ( strpos( $existing_classes, $class_name ) !== false ) {
						return $matches[0];
					}

					$new_classes = $existing_classes . ' ' . $class_name;
					$attributes  = preg_replace(
						'/class\s*=\s*["\'][^"\']*["\']/',
						'class="' . esc_attr( $new_classes ) . '"',
						$attributes
					);
				} else {
					// Add new class attribute.
					$attributes = trim( $attributes ) . ' class="' . esc_attr( $class_name ) . '"';
				}

				return $tag . $attributes . $close;
			},
			$html,
			1
		);
	}

	/**
	 * Add hidden microformat data elements to content.
	 *
	 * Adds data elements for metadata that needs to be in mf2 but isn't visible.
	 *
	 * @param string $content Post content.
	 * @return string Modified content.
	 */
	public function add_hidden_mf2_data( string $content ): string {
		$post_id = get_the_ID();

		if ( ! $post_id || ! is_singular() ) {
			return $content;
		}

		$kind = $this->get_post_kind( $post_id );

		if ( ! $kind ) {
			return $content;
		}

		$hidden_data = '';
		$prefix      = Meta_Fields::PREFIX;

		// Add kind-specific hidden data.
		switch ( $kind ) {
			case 'rsvp':
				$rsvp_status = get_post_meta( $post_id, $prefix . 'rsvp_status', true );
				if ( $rsvp_status ) {
					$hidden_data .= sprintf(
						'<data class="p-rsvp" value="%s"></data>',
						esc_attr( $rsvp_status )
					);
				}
				break;

			case 'checkin':
				$lat = get_post_meta( $post_id, $prefix . 'geo_latitude', true );
				$lng = get_post_meta( $post_id, $prefix . 'geo_longitude', true );
				if ( $lat && $lng ) {
					$hidden_data .= sprintf(
						'<data class="p-geo h-geo" value="geo:%s,%s">' .
						'<data class="p-latitude" value="%s"></data>' .
						'<data class="p-longitude" value="%s"></data>' .
						'</data>',
						esc_attr( $lat ),
						esc_attr( $lng ),
						esc_attr( $lat ),
						esc_attr( $lng )
					);
				}
				break;

			case 'review':
				$rating = get_post_meta( $post_id, $prefix . 'review_rating', true );
				$best   = get_post_meta( $post_id, $prefix . 'review_best', true ) ?: 5;
				if ( $rating ) {
					$hidden_data .= sprintf(
						'<data class="p-rating" value="%s"></data>' .
						'<data class="p-best" value="%s"></data>',
						esc_attr( $rating ),
						esc_attr( $best )
					);
				}
				break;

			case 'event':
				$start = get_post_meta( $post_id, $prefix . 'event_start', true );
				$end   = get_post_meta( $post_id, $prefix . 'event_end', true );
				if ( $start ) {
					$hidden_data .= sprintf(
						'<time class="dt-start" datetime="%s"></time>',
						esc_attr( $start )
					);
				}
				if ( $end ) {
					$hidden_data .= sprintf(
						'<time class="dt-end" datetime="%s"></time>',
						esc_attr( $end )
					);
				}
				break;
		}

		if ( ! empty( $hidden_data ) ) {
			$content .= sprintf(
				'<div class="reactions-indieweb-mf2-data" hidden>%s</div>',
				$hidden_data
			);
		}

		return $content;
	}

	/**
	 * Get the kind slug for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Kind slug or null.
	 */
	private function get_post_kind( int $post_id ): ?string {
		$terms = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return $terms[0]->slug;
	}

	/**
	 * Check if a block is from IndieBlocks.
	 *
	 * @param string $block_name Block name.
	 * @return bool True if IndieBlocks block.
	 */
	private function is_indieblocks_block( string $block_name ): bool {
		return in_array( $block_name, $this->indieblocks_blocks, true );
	}

	/**
	 * Get microformat data for a kind.
	 *
	 * @param string $kind Kind slug.
	 * @return array<string, mixed>|null Microformat data or null.
	 */
	public function get_kind_format( string $kind ): ?array {
		return $this->kind_formats[ $kind ] ?? null;
	}

	/**
	 * Get all kind format definitions.
	 *
	 * @return array<string, array<string, mixed>> All format definitions.
	 */
	public function get_all_formats(): array {
		return $this->kind_formats;
	}
}
