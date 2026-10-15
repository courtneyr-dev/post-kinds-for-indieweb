<?php
/**
 * Simple Location privacy gate for photo posts.
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
 * Removes precise Simple Location output from photo posts when privacy hides it.
 */
final class Photo_Location {

	/**
	 * The content map callback Simple Location registers.
	 *
	 * @var array{0:string,1:string}
	 */
	private const CONTENT_MAP = [ 'Geo_Data', 'content_map' ];

	/**
	 * The content place callback Simple Location registers.
	 *
	 * @var array{0:string,1:string}
	 */
	private const LOCATION_CONTENT = [ 'Geo_Data', 'location_content' ];

	/**
	 * The head meta callback Simple Location registers.
	 *
	 * @var array{0:string,1:string}
	 */
	private const META_TAGS = [ 'Geo_Base', 'meta_tags' ];

	/**
	 * The GeoRSS callback Simple Location registers.
	 *
	 * @var array{0:string,1:string}
	 */
	private const GEORSS_ITEM = [ 'Geo_Base', 'georss_item' ];

	/**
	 * Removed content callbacks for nested render passes.
	 *
	 * @var array<int, array{removed:array<int,array{callable:array{0:string,1:string},priority:int}>,place:string}>
	 */
	private static array $content_stack = [];

	/**
	 * Removed feed callbacks for nested feed item passes.
	 *
	 * @var bool[]
	 */
	private static array $feed_stack = [];

	/**
	 * Register the privacy gate hooks.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', [ $this, 'gate_content' ], 10 );
		add_filter( 'the_content', [ $this, 'restore_content' ], 13 );
		add_action( 'template_redirect', [ $this, 'gate_head' ] );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, [ $this, 'gate_feed_item' ], 9 );
			add_action( $hook, [ $this, 'restore_feed_item' ], 11 );
		}
		add_filter( 'json_feed_item', [ $this, 'gate_json_feed_item' ], 11, 2 );
	}

	/**
	 * Clear render-pass stacks.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$content_stack = [];
		self::$feed_stack    = [];
	}

	/**
	 * Remove Simple Location content callbacks that would reveal hidden details.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Post content.
	 * @return string Unchanged post content.
	 */
	public function gate_content( string $content ): string {
		$post    = get_post();
		$removed = [];
		$place   = '';

		if ( $post instanceof \WP_Post && $this->is_photo( $post ) ) {
			// A protected post hides every location field, like Kind_Facts::can_show().
			$visible = post_password_required( $post )
				? Meta_Fields::get_public_location_fields( 0 )
				: Meta_Fields::get_visible_location_fields( $post->ID );
			if ( ! $visible['map'] && remove_filter( 'the_content', self::CONTENT_MAP, 11 ) ) {
				$removed[] = [
					'callable' => self::CONTENT_MAP,
					'priority' => 11,
				];
			}
			if ( ! $visible['street'] && remove_filter( 'the_content', self::LOCATION_CONTENT, 12 ) ) {
				$removed[] = [
					'callable' => self::LOCATION_CONTENT,
					'priority' => 12,
				];
				$place     = $this->place_line( $post->ID, $visible );
			}
		}

		self::$content_stack[] = [
			'removed' => $removed,
			'place'   => $place,
		];

		return $content;
	}

	/**
	 * Restore the Simple Location content callbacks removed for this pass.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Post content.
	 * @return string Post content, with an approximate place line when needed.
	 */
	public function restore_content( string $content ): string {
		$entry = array_pop( self::$content_stack );
		if ( ! is_array( $entry ) ) {
			return $content;
		}

		foreach ( $entry['removed'] as $removed ) {
			add_filter( 'the_content', $removed['callable'], $removed['priority'] );
		}

		return $content . $entry['place'];
	}

	/**
	 * Remove Simple Location head meta when public privacy hides precise fields.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function gate_head(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post( get_queried_object_id() );
		if ( ! $post instanceof \WP_Post || ! $this->is_photo( $post ) ) {
			return;
		}

		$visible = Meta_Fields::get_public_location_fields( $post->ID );
		if ( post_password_required( $post ) || ! $visible['street'] || ! $visible['coordinates'] ) {
			remove_action( 'wp_head', self::META_TAGS, 10 );
		}
	}

	/**
	 * Remove GeoRSS output for this feed item when public privacy hides precise fields.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function gate_feed_item(): void {
		$removed = false;
		$post    = get_post();
		if ( $post instanceof \WP_Post && $this->is_photo( $post ) && $this->public_precise_hidden( $post->ID ) ) {
			$removed = remove_action( current_action(), self::GEORSS_ITEM, 10 );
		}

		self::$feed_stack[] = $removed;
	}

	/**
	 * Restore GeoRSS output for this feed item when this class removed it.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function restore_feed_item(): void {
		$removed = array_pop( self::$feed_stack );
		if ( true === $removed ) {
			add_action( current_action(), self::GEORSS_ITEM, 10 );
		}
	}

	/**
	 * Remove JSON Feed geo data from hidden photo locations.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $item JSON Feed item.
	 * @param mixed $post Feed post, or null.
	 * @return mixed Filtered item.
	 */
	public function gate_json_feed_item( $item, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post();
		if ( is_array( $item ) && $post instanceof \WP_Post && $this->is_photo( $post ) && $this->public_precise_hidden( $post->ID ) ) {
			unset( $item['geo'] );
		}

		return $item;
	}

	/**
	 * Whether the post is a photo.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool True when the post's kind is photo.
	 */
	private function is_photo( \WP_Post $post ): bool {
		return Photo_Archive::KIND === Kind_Facts::kind_of( $post );
	}

	/**
	 * Whether a password or public privacy hides either street or coordinates.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when precise location is hidden.
	 */
	private function public_precise_hidden( int $post_id ): bool {
		if ( post_password_required( $post_id ) ) {
			return true;
		}

		$visible = Meta_Fields::get_public_location_fields( $post_id );

		return ! $visible['street'] || ! $visible['coordinates'];
	}

	/**
	 * Build the approximate public place line from visible Simple Location text.
	 *
	 * @param int                                                                                                                                               $post_id Post ID.
	 * @param array{name:bool,locality:bool,region:bool,country:bool,street:bool,postal_code:bool,coordinates:bool,map:bool,url:bool,osm_id:bool,venue_id:bool} $visible Visible fields.
	 * @return string Place line HTML, or ''.
	 */
	private function place_line( int $post_id, array $visible ): string {
		$parts = [];
		foreach (
			[
				'name'     => 'geo_venue',
				'locality' => 'geo_locality',
				'region'   => 'geo_region',
				'country'  => 'geo_country_name',
			] as $tier => $meta_key
		) {
			if ( ! $visible[ $tier ] ) {
				continue;
			}
			$value = get_post_meta( $post_id, $meta_key, true );
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' !== $value ) {
				$parts[] = esc_html( $value );
			}
		}

		return [] === $parts ? '' : '<p class="pkiw-photo-place">' . implode( ', ', $parts ) . '</p>';
	}
}
