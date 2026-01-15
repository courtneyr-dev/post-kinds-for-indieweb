<?php
/**
 * Meta Boxes
 *
 * Custom meta boxes for post editing screens.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta boxes class.
 */
class Meta_Boxes {

	/**
	 * Admin instance.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Meta field configurations by post kind.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $meta_configs;

	/**
	 * Constructor.
	 *
	 * @param Admin $admin Admin instance.
	 */
	public function __construct( Admin $admin ) {
		$this->admin        = $admin;
		$this->meta_configs = $this->get_meta_configs();
	}

	/**
	 * Initialize meta boxes.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_meta_boxes' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Get meta field configurations.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function get_meta_configs(): array {
		return [
			'listen'   => [
				'track_title'    => [
					'label'    => __( 'Track Title', 'post-kinds-for-indieweb' ),
					'type'     => 'text',
					'required' => true,
				],
				'artist_name'    => [
					'label'    => __( 'Artist', 'post-kinds-for-indieweb' ),
					'type'     => 'text',
					'required' => true,
				],
				'album_title'    => [
					'label' => __( 'Album', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'release_date'   => [
					'label' => __( 'Release Date', 'post-kinds-for-indieweb' ),
					'type'  => 'date',
				],
				'musicbrainz_id' => [
					'label' => __( 'MusicBrainz ID', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code',
				],
				'listen_url'     => [
					'label'       => __( 'Listen URL', 'post-kinds-for-indieweb' ),
					'type'        => 'url',
					'placeholder' => 'https://...',
				],
				'rating'         => [
					'label' => __( 'Rating', 'post-kinds-for-indieweb' ),
					'type'  => 'rating',
					'max'   => 5,
				],
				'cover_image'    => [
					'label' => __( 'Cover Image', 'post-kinds-for-indieweb' ),
					'type'  => 'image',
				],
			],
			'watch'    => [
				'media_title'    => [
					'label'    => __( 'Title', 'post-kinds-for-indieweb' ),
					'type'     => 'text',
					'required' => true,
				],
				'media_type'     => [
					'label'   => __( 'Type', 'post-kinds-for-indieweb' ),
					'type'    => 'select',
					'options' => [
						'movie'   => __( 'Movie', 'post-kinds-for-indieweb' ),
						'tv'      => __( 'TV Show', 'post-kinds-for-indieweb' ),
						'episode' => __( 'TV Episode', 'post-kinds-for-indieweb' ),
					],
				],
				'show_title'     => [
					'label'      => __( 'Show Title', 'post-kinds-for-indieweb' ),
					'type'       => 'text',
					'depends_on' => 'media_type:episode',
				],
				'season_number'  => [
					'label'      => __( 'Season', 'post-kinds-for-indieweb' ),
					'type'       => 'number',
					'min'        => 1,
					'depends_on' => 'media_type:episode',
				],
				'episode_number' => [
					'label'      => __( 'Episode', 'post-kinds-for-indieweb' ),
					'type'       => 'number',
					'min'        => 1,
					'depends_on' => 'media_type:episode',
				],
				'release_year'   => [
					'label' => __( 'Year', 'post-kinds-for-indieweb' ),
					'type'  => 'number',
					'min'   => 1900,
					'max'   => 2100,
				],
				'director'       => [
					'label' => __( 'Director', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'tmdb_id'        => [
					'label' => __( 'TMDB ID', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code',
				],
				'imdb_id'        => [
					'label' => __( 'IMDb ID', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code',
				],
				'watch_url'      => [
					'label'       => __( 'Watch URL', 'post-kinds-for-indieweb' ),
					'type'        => 'url',
					'placeholder' => 'https://...',
				],
				'rating'         => [
					'label' => __( 'Rating', 'post-kinds-for-indieweb' ),
					'type'  => 'rating',
					'max'   => 5,
				],
				'rewatch'        => [
					'label' => __( 'Rewatch', 'post-kinds-for-indieweb' ),
					'type'  => 'checkbox',
				],
				'poster_image'   => [
					'label' => __( 'Poster Image', 'post-kinds-for-indieweb' ),
					'type'  => 'image',
				],
			],
			'read'     => [
				'book_title'       => [
					'label'    => __( 'Book Title', 'post-kinds-for-indieweb' ),
					'type'     => 'text',
					'required' => true,
				],
				'author_name'      => [
					'label'    => __( 'Author', 'post-kinds-for-indieweb' ),
					'type'     => 'text',
					'required' => true,
				],
				'isbn'             => [
					'label' => __( 'ISBN', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code',
				],
				'publisher'        => [
					'label' => __( 'Publisher', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'publish_date'     => [
					'label' => __( 'Publication Date', 'post-kinds-for-indieweb' ),
					'type'  => 'date',
				],
				'page_count'       => [
					'label' => __( 'Pages', 'post-kinds-for-indieweb' ),
					'type'  => 'number',
					'min'   => 1,
				],
				'read_status'      => [
					'label'   => __( 'Status', 'post-kinds-for-indieweb' ),
					'type'    => 'select',
					'options' => [
						'to-read'   => __( 'To Read', 'post-kinds-for-indieweb' ),
						'reading'   => __( 'Currently Reading', 'post-kinds-for-indieweb' ),
						'finished'  => __( 'Finished', 'post-kinds-for-indieweb' ),
						'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb' ),
					],
				],
				'progress_percent' => [
					'label' => __( 'Progress (%)', 'post-kinds-for-indieweb' ),
					'type'  => 'number',
					'min'   => 0,
					'max'   => 100,
				],
				'openlibrary_id'   => [
					'label' => __( 'Open Library ID', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code',
				],
				'rating'           => [
					'label' => __( 'Rating', 'post-kinds-for-indieweb' ),
					'type'  => 'rating',
					'max'   => 5,
				],
				'cover_image'      => [
					'label' => __( 'Cover Image', 'post-kinds-for-indieweb' ),
					'type'  => 'image',
				],
			],
			'checkin'  => [
				'venue_name'    => [
					'label'    => __( 'Venue Name', 'post-kinds-for-indieweb' ),
					'type'     => 'text',
					'required' => true,
				],
				'venue_address' => [
					'label' => __( 'Address', 'post-kinds-for-indieweb' ),
					'type'  => 'textarea',
					'rows'  => 2,
				],
				'venue_city'    => [
					'label' => __( 'City', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'venue_country' => [
					'label' => __( 'Country', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'latitude'      => [
					'label' => __( 'Latitude', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code small-text',
				],
				'longitude'     => [
					'label' => __( 'Longitude', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code small-text',
				],
				'foursquare_id' => [
					'label' => __( 'Foursquare ID', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
					'class' => 'code',
				],
				'venue_url'     => [
					'label'       => __( 'Venue URL', 'post-kinds-for-indieweb' ),
					'type'        => 'url',
					'placeholder' => 'https://...',
				],
			],
			'rsvp'     => [
				'event_name'     => [
					'label'    => __( 'Event Name', 'post-kinds-for-indieweb' ),
					'type'     => 'text',
					'required' => true,
				],
				'event_url'      => [
					'label'    => __( 'Event URL', 'post-kinds-for-indieweb' ),
					'type'     => 'url',
					'required' => true,
				],
				'rsvp_value'     => [
					'label'   => __( 'RSVP', 'post-kinds-for-indieweb' ),
					'type'    => 'select',
					'options' => [
						'yes'        => __( 'Yes', 'post-kinds-for-indieweb' ),
						'no'         => __( 'No', 'post-kinds-for-indieweb' ),
						'maybe'      => __( 'Maybe', 'post-kinds-for-indieweb' ),
						'interested' => __( 'Interested', 'post-kinds-for-indieweb' ),
					],
				],
				'event_start'    => [
					'label' => __( 'Start Date/Time', 'post-kinds-for-indieweb' ),
					'type'  => 'datetime-local',
				],
				'event_end'      => [
					'label' => __( 'End Date/Time', 'post-kinds-for-indieweb' ),
					'type'  => 'datetime-local',
				],
				'event_location' => [
					'label' => __( 'Location', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
			],
			'like'     => [
				'like_of'     => [
					'label'    => __( 'URL of Liked Content', 'post-kinds-for-indieweb' ),
					'type'     => 'url',
					'required' => true,
				],
				'cite_name'   => [
					'label' => __( 'Content Title', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'cite_author' => [
					'label' => __( 'Author', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
			],
			'repost'   => [
				'repost_of'   => [
					'label'    => __( 'URL of Original Content', 'post-kinds-for-indieweb' ),
					'type'     => 'url',
					'required' => true,
				],
				'cite_name'   => [
					'label' => __( 'Content Title', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'cite_author' => [
					'label' => __( 'Author', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
			],
			'bookmark' => [
				'bookmark_of'  => [
					'label'    => __( 'Bookmarked URL', 'post-kinds-for-indieweb' ),
					'type'     => 'url',
					'required' => true,
				],
				'cite_name'    => [
					'label' => __( 'Page Title', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'cite_author'  => [
					'label' => __( 'Author', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'cite_summary' => [
					'label' => __( 'Summary', 'post-kinds-for-indieweb' ),
					'type'  => 'textarea',
					'rows'  => 3,
				],
			],
			'reply'    => [
				'in_reply_to' => [
					'label'    => __( 'URL Replying To', 'post-kinds-for-indieweb' ),
					'type'     => 'url',
					'required' => true,
				],
				'cite_name'   => [
					'label' => __( 'Original Title', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
				'cite_author' => [
					'label' => __( 'Author', 'post-kinds-for-indieweb' ),
					'type'  => 'text',
				],
			],
		];
	}

	/**
	 * Register meta boxes.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function register_meta_boxes( string $post_type ): void {
		if ( 'post' !== $post_type ) {
			return;
		}

		// Skip classic meta boxes when block editor is active - sidebar handles this.
		if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type ) ) {
			return;
		}

		// Main reaction details meta box.
		add_meta_box(
			'post_kinds_indieweb_details',
			__( 'Reaction Details', 'post-kinds-for-indieweb' ),
			[ $this, 'render_details_meta_box' ],
			'post',
			'normal',
			'high'
		);

		// Media lookup sidebar.
		add_meta_box(
			'post_kinds_indieweb_lookup',
			__( 'Media Lookup', 'post-kinds-for-indieweb' ),
			[ $this, 'render_lookup_meta_box' ],
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render the details meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_details_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'post_kinds_indieweb_meta', 'post_kinds_indieweb_meta_nonce' );

		// Get current post kind.
		$kinds        = wp_get_object_terms( $post->ID, 'kind', [ 'fields' => 'slugs' ] );
		$current_kind = ! empty( $kinds ) ? $kinds[0] : '';

		?>
		<div class="post-kinds-meta-box">
			<!-- Post kind selector -->
			<div class="meta-field kind-selector">
				<label for="post_kinds_post_kind"><?php esc_html_e( 'Post Kind', 'post-kinds-for-indieweb' ); ?></label>
				<select name="post_kinds_post_kind" id="post_kinds_post_kind" class="widefat">
					<option value=""><?php esc_html_e( 'Select a post kind...', 'post-kinds-for-indieweb' ); ?></option>
					<?php foreach ( $this->meta_configs as $kind => $fields ) : ?>
						<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $current_kind, $kind ); ?>>
							<?php echo esc_html( ucfirst( $kind ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Dynamic fields container -->
			<?php foreach ( $this->meta_configs as $kind => $fields ) : ?>
				<div class="kind-fields" data-kind="<?php echo esc_attr( $kind ); ?>"
					style="<?php echo $current_kind === $kind ? '' : 'display: none;'; ?>">

					<div class="meta-fields-grid">
						<?php foreach ( $fields as $field_id => $field ) : ?>
							<?php $this->render_field( $post->ID, $kind, $field_id, $field ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<div class="no-kind-selected" <?php echo ! empty( $current_kind ) ? 'style="display: none;"' : ''; ?>>
				<p class="description">
					<?php esc_html_e( 'Select a post kind above to see the relevant fields.', 'post-kinds-for-indieweb' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single field.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param string               $kind     Post kind.
	 * @param string               $field_id Field identifier.
	 * @param array<string, mixed> $field    Field configuration.
	 * @return void
	 */
	private function render_field( int $post_id, string $kind, string $field_id, array $field ): void {
		$meta_key = "_postkind_indieweb_{$field_id}";
		$value    = get_post_meta( $post_id, $meta_key, true );
		$name     = "post_kinds_meta[{$kind}][{$field_id}]";
		$id       = "post_kinds_{$kind}_{$field_id}";
		$class    = $field['class'] ?? 'widefat';
		$required = ! empty( $field['required'] ) ? 'required' : '';

		// Handle conditional display.
		$wrapper_style = '';
		if ( ! empty( $field['depends_on'] ) ) {
			list( $dep_field, $dep_value ) = explode( ':', $field['depends_on'] );
			$dep_meta_key                  = "_postkind_indieweb_{$dep_field}";
			$dep_current                   = get_post_meta( $post_id, $dep_meta_key, true );
			if ( $dep_current !== $dep_value ) {
				$wrapper_style = 'display: none;';
			}
		}

		?>
		<div class="meta-field field-type-<?php echo esc_attr( $field['type'] ); ?>"
			data-field="<?php echo esc_attr( $field_id ); ?>"
			<?php echo ! empty( $field['depends_on'] ) ? 'data-depends-on="' . esc_attr( $field['depends_on'] ) . '"' : ''; ?>
			style="<?php echo esc_attr( $wrapper_style ); ?>">

			<label for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( ! empty( $field['required'] ) ) : ?>
					<span class="required">*</span>
				<?php endif; ?>
			</label>

			<?php
			switch ( $field['type'] ) {
				case 'text':
				case 'url':
				case 'email':
					printf(
						'<input type="%s" name="%s" id="%s" value="%s" class="%s" placeholder="%s" %s>',
						esc_attr( $field['type'] ),
						esc_attr( $name ),
						esc_attr( $id ),
						esc_attr( $value ),
						esc_attr( $class ),
						esc_attr( $field['placeholder'] ?? '' ),
						esc_attr( $required )
					);
					break;

				case 'number':
					printf(
						'<input type="number" name="%s" id="%s" value="%s" class="%s" min="%s" max="%s" %s>',
						esc_attr( $name ),
						esc_attr( $id ),
						esc_attr( $value ),
						esc_attr( $class ),
						esc_attr( $field['min'] ?? '' ),
						esc_attr( $field['max'] ?? '' ),
						esc_attr( $required )
					);
					break;

				case 'date':
					printf(
						'<input type="date" name="%s" id="%s" value="%s" class="%s" %s>',
						esc_attr( $name ),
						esc_attr( $id ),
						esc_attr( $value ),
						esc_attr( $class ),
						esc_attr( $required )
					);
					break;

				case 'datetime-local':
					printf(
						'<input type="datetime-local" name="%s" id="%s" value="%s" class="%s" %s>',
						esc_attr( $name ),
						esc_attr( $id ),
						esc_attr( $value ),
						esc_attr( $class ),
						esc_attr( $required )
					);
					break;

				case 'textarea':
					printf(
						'<textarea name="%s" id="%s" class="%s" rows="%d" %s>%s</textarea>',
						esc_attr( $name ),
						esc_attr( $id ),
						esc_attr( $class ),
						(int) ( $field['rows'] ?? 3 ),
						esc_attr( $required ),
						esc_textarea( $value )
					);
					break;

				case 'select':
					printf( '<select name="%s" id="%s" class="%s" %s>', esc_attr( $name ), esc_attr( $id ), esc_attr( $class ), esc_attr( $required ) );
					echo '<option value="">' . esc_html__( 'Select...', 'post-kinds-for-indieweb' ) . '</option>';
					foreach ( $field['options'] as $opt_value => $opt_label ) {
						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( $opt_value ),
							selected( $value, $opt_value, false ),
							esc_html( $opt_label )
						);
					}
					echo '</select>';
					break;

				case 'checkbox':
					printf(
						'<input type="checkbox" name="%s" id="%s" value="1" %s>',
						esc_attr( $name ),
						esc_attr( $id ),
						checked( $value, '1', false )
					);
					break;

				case 'rating':
					$max = $field['max'] ?? 5;
					echo '<div class="star-rating" data-max="' . esc_attr( $max ) . '">';
					printf(
						'<input type="hidden" name="%s" id="%s" value="%s" class="rating-value">',
						esc_attr( $name ),
						esc_attr( $id ),
						esc_attr( $value )
					);
					for ( $i = 1; $i <= $max; $i++ ) {
						$filled = ( (int) $value >= $i ) ? 'filled' : '';
						echo '<span class="star ' . esc_attr( $filled ) . '" data-value="' . esc_attr( $i ) . '">&#9733;</span>';
					}
					echo '<button type="button" class="button button-small clear-rating">' . esc_html__( 'Clear', 'post-kinds-for-indieweb' ) . '</button>';
					echo '</div>';
					break;

				case 'image':
					$image_url = '';
					if ( $value ) {
						if ( is_numeric( $value ) ) {
							$image_url = wp_get_attachment_image_url( $value, 'thumbnail' );
						} else {
							$image_url = $value;
						}
					}
					?>
					<div class="image-field">
						<div class="image-preview" <?php echo $image_url ? '' : 'style="display: none;"'; ?>>
							<img src="<?php echo esc_url( $image_url ); ?>" alt="">
						</div>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" class="image-value">
						<button type="button" class="button select-image">
							<?php esc_html_e( 'Select Image', 'post-kinds-for-indieweb' ); ?>
						</button>
						<button type="button" class="button remove-image" <?php echo $value ? '' : 'style="display: none;"'; ?>>
							<?php esc_html_e( 'Remove', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
					<?php
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the lookup meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_lookup_meta_box( \WP_Post $post ): void {
		?>
		<div class="post-kinds-lookup-box">
			<p class="description">
				<?php esc_html_e( 'Search for media to auto-fill details.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<div class="lookup-form">
				<select id="lookup-type" class="widefat">
					<option value="music"><?php esc_html_e( 'Music', 'post-kinds-for-indieweb' ); ?></option>
					<option value="movie"><?php esc_html_e( 'Movie', 'post-kinds-for-indieweb' ); ?></option>
					<option value="tv"><?php esc_html_e( 'TV Show', 'post-kinds-for-indieweb' ); ?></option>
					<option value="book"><?php esc_html_e( 'Book', 'post-kinds-for-indieweb' ); ?></option>
					<option value="podcast"><?php esc_html_e( 'Podcast', 'post-kinds-for-indieweb' ); ?></option>
				</select>

				<input type="text" id="lookup-query" class="widefat" placeholder="<?php esc_attr_e( 'Search...', 'post-kinds-for-indieweb' ); ?>">

				<button type="button" id="lookup-search" class="button widefat">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Search', 'post-kinds-for-indieweb' ); ?>
				</button>
			</div>

			<div id="lookup-results" class="lookup-results"></div>
		</div>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta_boxes( int $post_id, \WP_Post $post ): void {
		// Verify nonce.
		if ( ! isset( $_POST['post_kinds_indieweb_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['post_kinds_indieweb_meta_nonce'] ) ), 'post_kinds_indieweb_meta' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check post type.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Save post kind.
		if ( isset( $_POST['post_kinds_post_kind'] ) ) {
			$kind = sanitize_text_field( wp_unslash( $_POST['post_kinds_post_kind'] ) );
			if ( ! empty( $kind ) && taxonomy_exists( 'kind' ) ) {
				wp_set_object_terms( $post_id, $kind, 'kind' );
			}
		}

		// Save meta fields.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$meta_data = isset( $_POST['post_kinds_meta'] ) ? wp_unslash( $_POST['post_kinds_meta'] ) : [];

		if ( ! is_array( $meta_data ) ) {
			return;
		}

		foreach ( $meta_data as $kind => $fields ) {
			if ( ! isset( $this->meta_configs[ $kind ] ) ) {
				continue;
			}

			foreach ( $fields as $field_id => $value ) {
				if ( ! isset( $this->meta_configs[ $kind ][ $field_id ] ) ) {
					continue;
				}

				$meta_key = "_postkind_indieweb_{$field_id}";
				$field    = $this->meta_configs[ $kind ][ $field_id ];

				// Sanitize based on field type.
				switch ( $field['type'] ) {
					case 'url':
						$value = esc_url_raw( $value );
						break;
					case 'email':
						$value = sanitize_email( $value );
						break;
					case 'number':
					case 'rating':
						$value = absint( $value );
						break;
					case 'textarea':
						$value = sanitize_textarea_field( $value );
						break;
					case 'checkbox':
						$value = ! empty( $value ) ? '1' : '';
						break;
					default:
						$value = sanitize_text_field( $value );
				}

				if ( '' === $value ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, $value );
				}
			}
		}
	}

	/**
	 * Enqueue scripts.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
	}
}
