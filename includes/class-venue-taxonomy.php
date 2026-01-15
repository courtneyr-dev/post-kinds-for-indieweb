<?php
/**
 * Venue Taxonomy
 *
 * Registers the venue taxonomy for check-ins with custom term meta fields.
 *
 * @package PostKindsForIndieWeb
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Venue Taxonomy class.
 *
 * @since 1.2.0
 */
class Venue_Taxonomy {

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	public const TAXONOMY = 'venue';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		add_action( 'init', [ $this, 'register_term_meta' ] );
		add_action( 'venue_add_form_fields', [ $this, 'add_venue_fields' ] );
		add_action( 'venue_edit_form_fields', [ $this, 'edit_venue_fields' ] );
		add_action( 'created_venue', [ $this, 'save_venue_meta' ] );
		add_action( 'edited_venue', [ $this, 'save_venue_meta' ] );
		add_filter( 'manage_edit-venue_columns', [ $this, 'add_columns' ] );
		add_filter( 'manage_venue_custom_column', [ $this, 'render_column' ], 10, 3 );
	}

	/**
	 * Register the venue taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		$post_types = [ 'post' ];

		// Add checkin CPT if enabled.
		if ( Checkin_Post_Type::is_enabled() ) {
			$post_types[] = Checkin_Post_Type::POST_TYPE;
		}

		$labels = [
			'name'                       => _x( 'Venues', 'Taxonomy general name', 'post-kinds-for-indieweb' ),
			'singular_name'              => _x( 'Venue', 'Taxonomy singular name', 'post-kinds-for-indieweb' ),
			'search_items'               => __( 'Search Venues', 'post-kinds-for-indieweb' ),
			'popular_items'              => __( 'Popular Venues', 'post-kinds-for-indieweb' ),
			'all_items'                  => __( 'All Venues', 'post-kinds-for-indieweb' ),
			'parent_item'                => null,
			'parent_item_colon'          => null,
			'edit_item'                  => __( 'Edit Venue', 'post-kinds-for-indieweb' ),
			'update_item'                => __( 'Update Venue', 'post-kinds-for-indieweb' ),
			'add_new_item'               => __( 'Add New Venue', 'post-kinds-for-indieweb' ),
			'new_item_name'              => __( 'New Venue Name', 'post-kinds-for-indieweb' ),
			'separate_items_with_commas' => __( 'Separate venues with commas', 'post-kinds-for-indieweb' ),
			'add_or_remove_items'        => __( 'Add or remove venues', 'post-kinds-for-indieweb' ),
			'choose_from_most_used'      => __( 'Choose from the most used venues', 'post-kinds-for-indieweb' ),
			'not_found'                  => __( 'No venues found.', 'post-kinds-for-indieweb' ),
			'menu_name'                  => __( 'Venues', 'post-kinds-for-indieweb' ),
			'back_to_items'              => __( '&larr; Back to Venues', 'post-kinds-for-indieweb' ),
		];

		$args = [
			'labels'            => $labels,
			'hierarchical'      => false,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => true,
			'show_tagcloud'     => true,
			'show_in_rest'      => true,
			'rewrite'           => [
				'slug'       => 'venue',
				'with_front' => false,
			],
		];

		register_taxonomy( self::TAXONOMY, $post_types, $args );
	}

	/**
	 * Register term meta fields.
	 *
	 * @return void
	 */
	public function register_term_meta(): void {
		$meta_fields = [
			'latitude'      => [
				'type'        => 'number',
				'description' => __( 'Venue latitude coordinate', 'post-kinds-for-indieweb' ),
			],
			'longitude'     => [
				'type'        => 'number',
				'description' => __( 'Venue longitude coordinate', 'post-kinds-for-indieweb' ),
			],
			'address'       => [
				'type'        => 'string',
				'description' => __( 'Street address', 'post-kinds-for-indieweb' ),
			],
			'city'          => [
				'type'        => 'string',
				'description' => __( 'City', 'post-kinds-for-indieweb' ),
			],
			'region'        => [
				'type'        => 'string',
				'description' => __( 'State/Region', 'post-kinds-for-indieweb' ),
			],
			'postal_code'   => [
				'type'        => 'string',
				'description' => __( 'Postal code', 'post-kinds-for-indieweb' ),
			],
			'country'       => [
				'type'        => 'string',
				'description' => __( 'Country', 'post-kinds-for-indieweb' ),
			],
			'foursquare_id' => [
				'type'        => 'string',
				'description' => __( 'Foursquare venue ID', 'post-kinds-for-indieweb' ),
			],
			'osm_id'        => [
				'type'        => 'string',
				'description' => __( 'OpenStreetMap ID', 'post-kinds-for-indieweb' ),
			],
			'url'           => [
				'type'        => 'string',
				'description' => __( 'Venue website URL', 'post-kinds-for-indieweb' ),
			],
			'phone'         => [
				'type'        => 'string',
				'description' => __( 'Venue phone number', 'post-kinds-for-indieweb' ),
			],
		];

		foreach ( $meta_fields as $key => $args ) {
			register_term_meta(
				self::TAXONOMY,
				$key,
				[
					'type'              => $args['type'],
					'description'       => $args['description'],
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'number' === $args['type'] ? 'floatval' : 'sanitize_text_field',
				]
			);
		}
	}

	/**
	 * Add venue fields to the add term form.
	 *
	 * @return void
	 */
	public function add_venue_fields(): void {
		?>
		<div class="form-field">
			<label for="venue_address"><?php esc_html_e( 'Address', 'post-kinds-for-indieweb' ); ?></label>
			<input type="text" name="venue_address" id="venue_address" />
			<p class="description"><?php esc_html_e( 'Street address of the venue.', 'post-kinds-for-indieweb' ); ?></p>
		</div>

		<div class="form-field">
			<label for="venue_city"><?php esc_html_e( 'City', 'post-kinds-for-indieweb' ); ?></label>
			<input type="text" name="venue_city" id="venue_city" />
		</div>

		<div class="form-field">
			<label for="venue_region"><?php esc_html_e( 'State/Region', 'post-kinds-for-indieweb' ); ?></label>
			<input type="text" name="venue_region" id="venue_region" />
		</div>

		<div class="form-field">
			<label for="venue_postal_code"><?php esc_html_e( 'Postal Code', 'post-kinds-for-indieweb' ); ?></label>
			<input type="text" name="venue_postal_code" id="venue_postal_code" />
		</div>

		<div class="form-field">
			<label for="venue_country"><?php esc_html_e( 'Country', 'post-kinds-for-indieweb' ); ?></label>
			<input type="text" name="venue_country" id="venue_country" />
		</div>

		<div class="form-field">
			<label for="venue_latitude"><?php esc_html_e( 'Latitude', 'post-kinds-for-indieweb' ); ?></label>
			<input type="number" step="any" name="venue_latitude" id="venue_latitude" />
			<p class="description"><?php esc_html_e( 'GPS latitude coordinate (e.g., 40.7128).', 'post-kinds-for-indieweb' ); ?></p>
		</div>

		<div class="form-field">
			<label for="venue_longitude"><?php esc_html_e( 'Longitude', 'post-kinds-for-indieweb' ); ?></label>
			<input type="number" step="any" name="venue_longitude" id="venue_longitude" />
			<p class="description"><?php esc_html_e( 'GPS longitude coordinate (e.g., -74.0060).', 'post-kinds-for-indieweb' ); ?></p>
		</div>

		<div class="form-field">
			<label for="venue_url"><?php esc_html_e( 'Website', 'post-kinds-for-indieweb' ); ?></label>
			<input type="url" name="venue_url" id="venue_url" />
			<p class="description"><?php esc_html_e( 'Venue website URL.', 'post-kinds-for-indieweb' ); ?></p>
		</div>

		<div class="form-field">
			<label for="venue_phone"><?php esc_html_e( 'Phone', 'post-kinds-for-indieweb' ); ?></label>
			<input type="tel" name="venue_phone" id="venue_phone" />
		</div>
		<?php
	}

	/**
	 * Add venue fields to the edit term form.
	 *
	 * @param \WP_Term $term Term object.
	 * @return void
	 */
	public function edit_venue_fields( \WP_Term $term ): void {
		$address     = get_term_meta( $term->term_id, 'address', true );
		$city        = get_term_meta( $term->term_id, 'city', true );
		$region      = get_term_meta( $term->term_id, 'region', true );
		$postal_code = get_term_meta( $term->term_id, 'postal_code', true );
		$country     = get_term_meta( $term->term_id, 'country', true );
		$latitude    = get_term_meta( $term->term_id, 'latitude', true );
		$longitude   = get_term_meta( $term->term_id, 'longitude', true );
		$url         = get_term_meta( $term->term_id, 'url', true );
		$phone       = get_term_meta( $term->term_id, 'phone', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="venue_address"><?php esc_html_e( 'Address', 'post-kinds-for-indieweb' ); ?></label></th>
			<td>
				<input type="text" name="venue_address" id="venue_address" value="<?php echo esc_attr( $address ); ?>" />
				<p class="description"><?php esc_html_e( 'Street address of the venue.', 'post-kinds-for-indieweb' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_city"><?php esc_html_e( 'City', 'post-kinds-for-indieweb' ); ?></label></th>
			<td><input type="text" name="venue_city" id="venue_city" value="<?php echo esc_attr( $city ); ?>" /></td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_region"><?php esc_html_e( 'State/Region', 'post-kinds-for-indieweb' ); ?></label></th>
			<td><input type="text" name="venue_region" id="venue_region" value="<?php echo esc_attr( $region ); ?>" /></td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_postal_code"><?php esc_html_e( 'Postal Code', 'post-kinds-for-indieweb' ); ?></label></th>
			<td><input type="text" name="venue_postal_code" id="venue_postal_code" value="<?php echo esc_attr( $postal_code ); ?>" /></td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_country"><?php esc_html_e( 'Country', 'post-kinds-for-indieweb' ); ?></label></th>
			<td><input type="text" name="venue_country" id="venue_country" value="<?php echo esc_attr( $country ); ?>" /></td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_latitude"><?php esc_html_e( 'Latitude', 'post-kinds-for-indieweb' ); ?></label></th>
			<td>
				<input type="number" step="any" name="venue_latitude" id="venue_latitude" value="<?php echo esc_attr( $latitude ); ?>" />
				<p class="description"><?php esc_html_e( 'GPS latitude coordinate.', 'post-kinds-for-indieweb' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_longitude"><?php esc_html_e( 'Longitude', 'post-kinds-for-indieweb' ); ?></label></th>
			<td>
				<input type="number" step="any" name="venue_longitude" id="venue_longitude" value="<?php echo esc_attr( $longitude ); ?>" />
				<p class="description"><?php esc_html_e( 'GPS longitude coordinate.', 'post-kinds-for-indieweb' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_url"><?php esc_html_e( 'Website', 'post-kinds-for-indieweb' ); ?></label></th>
			<td>
				<input type="url" name="venue_url" id="venue_url" value="<?php echo esc_url( $url ); ?>" />
				<p class="description"><?php esc_html_e( 'Venue website URL.', 'post-kinds-for-indieweb' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="venue_phone"><?php esc_html_e( 'Phone', 'post-kinds-for-indieweb' ); ?></label></th>
			<td><input type="tel" name="venue_phone" id="venue_phone" value="<?php echo esc_attr( $phone ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * Save venue meta when term is created or updated.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save_venue_meta( int $term_id ): void {
		// Verify nonce is checked by WordPress core for term operations.

		$fields = [
			'address'     => 'venue_address',
			'city'        => 'venue_city',
			'region'      => 'venue_region',
			'postal_code' => 'venue_postal_code',
			'country'     => 'venue_country',
			'latitude'    => 'venue_latitude',
			'longitude'   => 'venue_longitude',
			'url'         => 'venue_url',
			'phone'       => 'venue_phone',
		];

		foreach ( $fields as $meta_key => $post_key ) {
			if ( isset( $_POST[ $post_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

				// Handle numeric fields.
				if ( in_array( $meta_key, [ 'latitude', 'longitude' ], true ) ) {
					$value = '' !== $value ? floatval( $value ) : '';
				}

				// Handle URL field.
				if ( 'url' === $meta_key ) {
					$value = esc_url_raw( $value );
				}

				if ( '' !== $value ) {
					update_term_meta( $term_id, $meta_key, $value );
				} else {
					delete_term_meta( $term_id, $meta_key );
				}
			}
		}
	}

	/**
	 * Add custom columns to venue admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_columns( array $columns ): array {
		$new_columns = [];

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Add location column after name.
			if ( 'name' === $key ) {
				$new_columns['location'] = __( 'Location', 'post-kinds-for-indieweb' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $content     Column content.
	 * @param string $column_name Column name.
	 * @param int    $term_id     Term ID.
	 * @return string Column content.
	 */
	public function render_column( string $content, string $column_name, int $term_id ): string {
		if ( 'location' !== $column_name ) {
			return $content;
		}

		$city    = get_term_meta( $term_id, 'city', true );
		$country = get_term_meta( $term_id, 'country', true );

		$parts = array_filter( [ $city, $country ] );

		return ! empty( $parts ) ? esc_html( implode( ', ', $parts ) ) : '—';
	}

	/**
	 * Get venue by Foursquare ID.
	 *
	 * @param string $foursquare_id Foursquare venue ID.
	 * @return \WP_Term|null Term object or null.
	 */
	public static function get_by_foursquare_id( string $foursquare_id ): ?\WP_Term {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => 'foursquare_id',
						'value' => $foursquare_id,
					],
				],
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Create or get venue from location data.
	 *
	 * @param array $location_data Location data with name, address, city, etc.
	 * @return int|\WP_Error Term ID or error.
	 */
	public static function create_or_get( array $location_data ) {
		$name = $location_data['name'] ?? '';

		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'Venue name is required.', 'post-kinds-for-indieweb' ) );
		}

		// Check for existing venue by Foursquare ID.
		if ( ! empty( $location_data['foursquare_id'] ) ) {
			$existing = self::get_by_foursquare_id( $location_data['foursquare_id'] );
			if ( $existing ) {
				return $existing->term_id;
			}
		}

		// Check for existing venue by name.
		$existing = get_term_by( 'name', $name, self::TAXONOMY );
		if ( $existing ) {
			return $existing->term_id;
		}

		// Create new venue.
		$result = wp_insert_term( $name, self::TAXONOMY );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = $result['term_id'];

		// Save meta fields.
		$meta_fields = [ 'address', 'city', 'region', 'postal_code', 'country', 'latitude', 'longitude', 'foursquare_id', 'osm_id', 'url', 'phone' ];

		foreach ( $meta_fields as $field ) {
			if ( isset( $location_data[ $field ] ) && '' !== $location_data[ $field ] ) {
				update_term_meta( $term_id, $field, $location_data[ $field ] );
			}
		}

		return $term_id;
	}
}
