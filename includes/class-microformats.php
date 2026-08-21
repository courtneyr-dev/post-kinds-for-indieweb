<?php
/**
 * Microformats2 Output Filter
 *
 * Filters rendered block output to inject microformats2 classes based on post kind.
 *
 * @package PKIW
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PKIW;

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
	private array $kind_formats = [];

	/**
	 * Post IDs whose wrapper already went through the post_class filter
	 * during this request. A theme wrapper renders before its inner
	 * content, so by the time the_content runs for a post, its presence
	 * here means the theme supplied the h-entry root itself.
	 *
	 * @var array<int, true>
	 */
	private array $post_class_seen = [];

	/**
	 * IndieBlocks block names to skip (they already have mf2).
	 *
	 * @var array<string>
	 */
	private array $indieblocks_blocks = [
		'indieblocks/bookmark',
		'indieblocks/like',
		'indieblocks/reply',
		'indieblocks/repost',
		'indieblocks/context',
		'indieblocks/facepile',
		'indieblocks/location',
		'indieblocks/syndication',
		'indieblocks/link-preview',
	];

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
		$this->kind_formats = [
			'note'      => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'article'   => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'title'   => 'p-name',
					'content' => 'e-content',
					'date'    => 'dt-published',
					'author'  => 'p-author h-card',
				],
			],
			'reply'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'content'  => 'e-content',
					'date'     => 'dt-published',
					'reply-to' => 'u-in-reply-to',
					'cite'     => 'h-cite',
				],
			],
			'like'      => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'like-of' => 'u-like-of',
					'date'    => 'dt-published',
				],
			],
			'repost'    => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'repost-of' => 'u-repost-of',
					'cite'      => 'h-cite',
					'date'      => 'dt-published',
				],
			],
			'bookmark'  => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'bookmark-of' => 'u-bookmark-of',
					'cite'        => 'h-cite',
					'content'     => 'e-content',
					'date'        => 'dt-published',
				],
			],
			'rsvp'      => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'rsvp'     => 'p-rsvp',
					'reply-to' => 'u-in-reply-to',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				],
			],
			'checkin'   => [
				'root'       => [ 'h-entry' ],
				'properties' => [
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
				],
			],
			'listen'    => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'listen-of' => 'u-listen-of',
					'cite'      => 'h-cite',
					'name'      => 'p-name',
					'author'    => 'p-author h-card',
					'photo'     => 'u-photo',
					'content'   => 'e-content',
					'date'      => 'dt-published',
				],
			],
			'watch'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'watch-of' => 'u-watch-of',
					'cite'     => 'h-cite',
					'name'     => 'p-name',
					'photo'    => 'u-photo',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				],
			],
			'read'      => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'read-of' => 'u-read-of',
					'cite'    => 'h-cite',
					'name'    => 'p-name',
					'author'  => 'p-author',
					'uid'     => 'u-uid',
					'photo'   => 'u-photo',
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'event'     => [
				'root'       => [ 'h-event' ],
				'properties' => [
					'name'     => 'p-name',
					'start'    => 'dt-start',
					'end'      => 'dt-end',
					'location' => 'p-location',
					'content'  => 'e-content p-description',
					'url'      => 'u-url',
				],
			],
			'photo'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'photo'   => 'u-photo',
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'video'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'video'   => 'u-video',
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'review'    => [
				'root'       => [ 'h-review' ],
				'properties' => [
					'item'    => 'p-item h-product',
					'name'    => 'p-name',
					'rating'  => 'p-rating',
					'best'    => 'p-best',
					'url'     => 'u-url',
					'photo'   => 'u-photo',
					'content' => 'e-content p-description',
					'date'    => 'dt-published',
				],
			],
			'recipe'    => [
				'root'       => [ 'h-recipe' ],
				'properties' => [
					'name'         => 'p-name',
					'photo'        => 'u-photo',
					'author'       => 'p-author h-card',
					'yield'        => 'p-yield',
					'duration'     => 'dt-duration',
					'ingredient'   => 'p-ingredient',
					'instructions' => 'e-instructions',
					'content'      => 'e-content',
				],
			],
			'audio'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'audio'   => 'u-audio',
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'quote'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'quotation-of' => 'u-quotation-of',
					'cite'         => 'h-cite',
					'content'      => 'e-content',
					'date'         => 'dt-published',
				],
			],
			'tag'       => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'tag-of'   => 'u-tag-of',
					'category' => 'p-category',
					'date'     => 'dt-published',
				],
			],
			'weather'   => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'weather' => 'p-weather',
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'exercise'  => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'exercise' => 'p-exercise',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				],
			],
			'trip'      => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'trip'    => 'p-trip',
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'itinerary' => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'itinerary' => 'p-itinerary',
					'content'   => 'e-content',
					'date'      => 'dt-published',
				],
			],
			'follow'    => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'follow-of' => 'u-follow-of',
					'cite'      => 'h-cite',
					'date'      => 'dt-published',
				],
			],
			// An issue is a reply to a source-code repository, so it reuses
			// in-reply-to (matching classic Post Kinds) rather than a
			// dedicated issue-of property.
			'issue'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'reply-to' => 'u-in-reply-to',
					'cite'     => 'h-cite',
					'name'     => 'p-name',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				],
			],
			'question'  => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'question' => 'p-question',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				],
			],
			'sleep'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'sleep'   => 'p-sleep',
					'content' => 'e-content',
					'date'    => 'dt-published',
				],
			],
			'craft'     => [
				'root'       => [ 'h-entry' ],
				'properties' => [
					'craft-of' => 'u-craft-of',
					'content'  => 'e-content',
					'date'     => 'dt-published',
				],
			],
		];

		/**
		 * Filters the kind to microformat mapping.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $kind_formats Microformat definitions.
		 */
		$this->kind_formats = apply_filters( 'pkiw_kind_formats', $this->kind_formats );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Filter the post content wrapper.
		add_filter( 'post_class', [ $this, 'add_post_classes' ], 10, 3 );

		// Filter rendered blocks for mf2 classes.
		add_filter( 'render_block', [ $this, 'filter_block_output' ], 10, 3 );

		// Add hidden mf2 data elements.
		add_filter( 'the_content', [ $this, 'add_hidden_mf2_data' ], 99 );

		// After the hidden data (99) so it lands inside the wrapper.
		add_filter( 'the_content', [ $this, 'wrap_singular_content' ], 100 );
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
		$this->post_class_seen[ $post_id ] = true;

		$kind = $this->get_post_kind( $post_id );

		if ( ! $kind || ! isset( $this->kind_formats[ $kind ] ) ) {
			// Default to h-entry for posts without a kind.
			$classes[] = 'h-entry';
			return $classes;
		}

		// Add root microformat classes.
		$root_classes = $this->kind_formats[ $kind ]['root'] ?? [ 'h-entry' ];
		$classes      = array_merge( $classes, $root_classes );

		// Add kind-specific class.
		$classes[] = 'kind-' . $kind;

		return array_unique( $classes );
	}

	/**
	 * Filter block output to add microformat classes.
	 *
	 * @param string    $block_content Rendered block content.
	 * @param array     $block         Block data.
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
		$bindings = $block['attrs']['metadata']['bindings'] ?? [];

		if ( empty( $bindings ) ) {
			return $content;
		}

		// Map binding keys to mf2 properties.
		$binding_to_mf2 = [
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
		];

		$kind_properties = $this->kind_formats[ $kind ]['properties'] ?? [];

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
	 * Wrap singular content in an h-entry when the theme supplied none.
	 *
	 * The kind properties ride markup inside the content (the card's
	 * h-cite, the hidden mf2 data), and they only reach a consuming
	 * parser if an h-entry root wraps them. That root normally comes
	 * from the post_class filter — but a block theme whose single
	 * template wraps the post in a plain Group (Twenty Twenty-Five,
	 * Twenty Twenty-Four) never calls post_class(), the card parses as
	 * a top-level orphan h-cite, and a webmention receiver fetching the
	 * permalink cannot tell what kind of post it is. See GH issue 142.
	 *
	 * Wraps only the queried post on singular requests, only for posts
	 * with a kind, and only when post_class() has not already run for
	 * that post this request.
	 *
	 * @param string $content Post content.
	 * @return string Content, wrapped when the theme supplied no h-entry.
	 */
	public function wrap_singular_content( string $content ): string {
		$post_id = get_the_ID();

		if ( ! $post_id || ! is_singular() || get_queried_object_id() !== $post_id ) {
			return $content;
		}

		// The theme already produced an h-entry wrapper via post_class().
		if ( isset( $this->post_class_seen[ $post_id ] ) ) {
			return $content;
		}

		// the_content can be applied more than once per request.
		if ( str_contains( $content, 'pkiw-singular-entry' ) ) {
			return $content;
		}

		$kind = $this->get_post_kind( $post_id );

		if ( ! $kind ) {
			return $content;
		}

		// Mirror add_post_classes(): kinds without a format definition
		// still root as h-entry — their cards emit properties too.
		$root_classes = $this->kind_formats[ $kind ]['root'] ?? [ 'h-entry' ];
		$classes      = array_merge( $root_classes, [ 'kind-' . $kind, 'pkiw-singular-entry' ] );

		// Entry-level essentials so the h-entry stands alone for parsers.
		$entry_meta = sprintf(
			'<a class="u-url" href="%s" hidden></a><time class="dt-published" datetime="%s" hidden></time>',
			esc_url( get_permalink( $post_id ) ),
			esc_attr( (string) get_the_date( 'c', $post_id ) )
		);

		// Only name the entry where the kind's vocabulary has a name —
		// notes and responses are intentionally title-less in mf2.
		$properties = $this->kind_formats[ $kind ]['properties'] ?? [];
		if ( in_array( 'p-name', $properties, true ) ) {
			$title = get_the_title( $post_id );
			if ( '' !== $title ) {
				$entry_meta .= sprintf(
					'<span class="p-name" hidden>%s</span>',
					esc_html( $title )
				);
			}
		}

		return sprintf(
			'<div class="%s">%s%s</div>',
			esc_attr( implode( ' ', $classes ) ),
			$content,
			$entry_meta
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
				$privacy  = get_post_meta( $post_id, $prefix . 'geo_privacy', true ) ?: 'approximate';
				$lat      = get_post_meta( $post_id, $prefix . 'geo_latitude', true );
				$lng      = get_post_meta( $post_id, $prefix . 'geo_longitude', true );
				$locality = get_post_meta( $post_id, $prefix . 'checkin_locality', true );
				$region   = get_post_meta( $post_id, $prefix . 'checkin_region', true );
				$country  = get_post_meta( $post_id, $prefix . 'checkin_country', true );
				$name     = get_post_meta( $post_id, $prefix . 'checkin_name', true );

				// Build checkin h-card with privacy awareness.
				$checkin_card = '<span class="p-checkin h-card">';

				// Venue name (always shown if not private).
				if ( 'private' !== $privacy && $name ) {
					$checkin_card .= sprintf(
						'<span class="p-name">%s</span>',
						esc_html( $name )
					);
				}

				// Address data based on privacy level.
				if ( 'private' !== $privacy ) {
					$checkin_card .= '<span class="p-adr h-adr">';

					// Only show street address for public.
					if ( 'public' === $privacy ) {
						$address = get_post_meta( $post_id, $prefix . 'checkin_address', true );
						if ( $address ) {
							$checkin_card .= sprintf(
								'<span class="p-street-address">%s</span>',
								esc_html( $address )
							);
						}
					}

					// Locality, region, country shown for public and approximate.
					if ( $locality ) {
						$checkin_card .= sprintf(
							'<span class="p-locality">%s</span>',
							esc_html( $locality )
						);
					}
					if ( $region ) {
						$checkin_card .= sprintf(
							'<span class="p-region">%s</span>',
							esc_html( $region )
						);
					}
					if ( $country ) {
						$checkin_card .= sprintf(
							'<span class="p-country-name">%s</span>',
							esc_html( $country )
						);
					}

					$checkin_card .= '</span>'; // close p-adr.
				}

				// Geo coordinates only for public privacy.
				if ( 'public' === $privacy && $lat && $lng ) {
					$checkin_card .= sprintf(
						'<span class="p-geo h-geo">' .
						'<data class="p-latitude" value="%s"></data>' .
						'<data class="p-longitude" value="%s"></data>' .
						'</span>',
						esc_attr( $lat ),
						esc_attr( $lng )
					);
				}

				$checkin_card .= '</span>'; // close p-checkin.

				if ( 'private' !== $privacy ) {
					$hidden_data .= $checkin_card;
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
				'<div class="post-kinds-indieweb-mf2-data" hidden>%s</div>',
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
