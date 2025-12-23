<?php
/**
 * Meta Boxes
 *
 * Custom meta boxes for post editing screens.
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

namespace ReactionsForIndieWeb\Admin;

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
        $this->admin = $admin;
        $this->meta_configs = $this->get_meta_configs();
    }

    /**
     * Initialize meta boxes.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta_boxes' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Get meta field configurations.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function get_meta_configs(): array {
        return array(
            'listen' => array(
                'track_title' => array(
                    'label'       => __( 'Track Title', 'reactions-indieweb' ),
                    'type'        => 'text',
                    'required'    => true,
                ),
                'artist_name' => array(
                    'label'       => __( 'Artist', 'reactions-indieweb' ),
                    'type'        => 'text',
                    'required'    => true,
                ),
                'album_title' => array(
                    'label' => __( 'Album', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'release_date' => array(
                    'label' => __( 'Release Date', 'reactions-indieweb' ),
                    'type'  => 'date',
                ),
                'musicbrainz_id' => array(
                    'label' => __( 'MusicBrainz ID', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code',
                ),
                'listen_url' => array(
                    'label'       => __( 'Listen URL', 'reactions-indieweb' ),
                    'type'        => 'url',
                    'placeholder' => 'https://...',
                ),
                'rating' => array(
                    'label' => __( 'Rating', 'reactions-indieweb' ),
                    'type'  => 'rating',
                    'max'   => 5,
                ),
                'cover_image' => array(
                    'label' => __( 'Cover Image', 'reactions-indieweb' ),
                    'type'  => 'image',
                ),
            ),
            'watch' => array(
                'media_title' => array(
                    'label'    => __( 'Title', 'reactions-indieweb' ),
                    'type'     => 'text',
                    'required' => true,
                ),
                'media_type' => array(
                    'label'   => __( 'Type', 'reactions-indieweb' ),
                    'type'    => 'select',
                    'options' => array(
                        'movie'   => __( 'Movie', 'reactions-indieweb' ),
                        'tv'      => __( 'TV Show', 'reactions-indieweb' ),
                        'episode' => __( 'TV Episode', 'reactions-indieweb' ),
                    ),
                ),
                'show_title' => array(
                    'label'      => __( 'Show Title', 'reactions-indieweb' ),
                    'type'       => 'text',
                    'depends_on' => 'media_type:episode',
                ),
                'season_number' => array(
                    'label'      => __( 'Season', 'reactions-indieweb' ),
                    'type'       => 'number',
                    'min'        => 1,
                    'depends_on' => 'media_type:episode',
                ),
                'episode_number' => array(
                    'label'      => __( 'Episode', 'reactions-indieweb' ),
                    'type'       => 'number',
                    'min'        => 1,
                    'depends_on' => 'media_type:episode',
                ),
                'release_year' => array(
                    'label' => __( 'Year', 'reactions-indieweb' ),
                    'type'  => 'number',
                    'min'   => 1900,
                    'max'   => 2100,
                ),
                'director' => array(
                    'label' => __( 'Director', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'tmdb_id' => array(
                    'label' => __( 'TMDB ID', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code',
                ),
                'imdb_id' => array(
                    'label' => __( 'IMDb ID', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code',
                ),
                'watch_url' => array(
                    'label'       => __( 'Watch URL', 'reactions-indieweb' ),
                    'type'        => 'url',
                    'placeholder' => 'https://...',
                ),
                'rating' => array(
                    'label' => __( 'Rating', 'reactions-indieweb' ),
                    'type'  => 'rating',
                    'max'   => 5,
                ),
                'rewatch' => array(
                    'label' => __( 'Rewatch', 'reactions-indieweb' ),
                    'type'  => 'checkbox',
                ),
                'poster_image' => array(
                    'label' => __( 'Poster Image', 'reactions-indieweb' ),
                    'type'  => 'image',
                ),
            ),
            'read' => array(
                'book_title' => array(
                    'label'    => __( 'Book Title', 'reactions-indieweb' ),
                    'type'     => 'text',
                    'required' => true,
                ),
                'author_name' => array(
                    'label'    => __( 'Author', 'reactions-indieweb' ),
                    'type'     => 'text',
                    'required' => true,
                ),
                'isbn' => array(
                    'label' => __( 'ISBN', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code',
                ),
                'publisher' => array(
                    'label' => __( 'Publisher', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'publish_date' => array(
                    'label' => __( 'Publication Date', 'reactions-indieweb' ),
                    'type'  => 'date',
                ),
                'page_count' => array(
                    'label' => __( 'Pages', 'reactions-indieweb' ),
                    'type'  => 'number',
                    'min'   => 1,
                ),
                'read_status' => array(
                    'label'   => __( 'Status', 'reactions-indieweb' ),
                    'type'    => 'select',
                    'options' => array(
                        'to-read'   => __( 'To Read', 'reactions-indieweb' ),
                        'reading'   => __( 'Currently Reading', 'reactions-indieweb' ),
                        'finished'  => __( 'Finished', 'reactions-indieweb' ),
                        'abandoned' => __( 'Abandoned', 'reactions-indieweb' ),
                    ),
                ),
                'progress_percent' => array(
                    'label' => __( 'Progress (%)', 'reactions-indieweb' ),
                    'type'  => 'number',
                    'min'   => 0,
                    'max'   => 100,
                ),
                'openlibrary_id' => array(
                    'label' => __( 'Open Library ID', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code',
                ),
                'rating' => array(
                    'label' => __( 'Rating', 'reactions-indieweb' ),
                    'type'  => 'rating',
                    'max'   => 5,
                ),
                'cover_image' => array(
                    'label' => __( 'Cover Image', 'reactions-indieweb' ),
                    'type'  => 'image',
                ),
            ),
            'checkin' => array(
                'venue_name' => array(
                    'label'    => __( 'Venue Name', 'reactions-indieweb' ),
                    'type'     => 'text',
                    'required' => true,
                ),
                'venue_address' => array(
                    'label' => __( 'Address', 'reactions-indieweb' ),
                    'type'  => 'textarea',
                    'rows'  => 2,
                ),
                'venue_city' => array(
                    'label' => __( 'City', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'venue_country' => array(
                    'label' => __( 'Country', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'latitude' => array(
                    'label' => __( 'Latitude', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code small-text',
                ),
                'longitude' => array(
                    'label' => __( 'Longitude', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code small-text',
                ),
                'foursquare_id' => array(
                    'label' => __( 'Foursquare ID', 'reactions-indieweb' ),
                    'type'  => 'text',
                    'class' => 'code',
                ),
                'venue_url' => array(
                    'label'       => __( 'Venue URL', 'reactions-indieweb' ),
                    'type'        => 'url',
                    'placeholder' => 'https://...',
                ),
            ),
            'rsvp' => array(
                'event_name' => array(
                    'label'    => __( 'Event Name', 'reactions-indieweb' ),
                    'type'     => 'text',
                    'required' => true,
                ),
                'event_url' => array(
                    'label'    => __( 'Event URL', 'reactions-indieweb' ),
                    'type'     => 'url',
                    'required' => true,
                ),
                'rsvp_value' => array(
                    'label'   => __( 'RSVP', 'reactions-indieweb' ),
                    'type'    => 'select',
                    'options' => array(
                        'yes'        => __( 'Yes', 'reactions-indieweb' ),
                        'no'         => __( 'No', 'reactions-indieweb' ),
                        'maybe'      => __( 'Maybe', 'reactions-indieweb' ),
                        'interested' => __( 'Interested', 'reactions-indieweb' ),
                    ),
                ),
                'event_start' => array(
                    'label' => __( 'Start Date/Time', 'reactions-indieweb' ),
                    'type'  => 'datetime-local',
                ),
                'event_end' => array(
                    'label' => __( 'End Date/Time', 'reactions-indieweb' ),
                    'type'  => 'datetime-local',
                ),
                'event_location' => array(
                    'label' => __( 'Location', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
            ),
            'like' => array(
                'like_of' => array(
                    'label'    => __( 'URL of Liked Content', 'reactions-indieweb' ),
                    'type'     => 'url',
                    'required' => true,
                ),
                'cite_name' => array(
                    'label' => __( 'Content Title', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'cite_author' => array(
                    'label' => __( 'Author', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
            ),
            'repost' => array(
                'repost_of' => array(
                    'label'    => __( 'URL of Original Content', 'reactions-indieweb' ),
                    'type'     => 'url',
                    'required' => true,
                ),
                'cite_name' => array(
                    'label' => __( 'Content Title', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'cite_author' => array(
                    'label' => __( 'Author', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
            ),
            'bookmark' => array(
                'bookmark_of' => array(
                    'label'    => __( 'Bookmarked URL', 'reactions-indieweb' ),
                    'type'     => 'url',
                    'required' => true,
                ),
                'cite_name' => array(
                    'label' => __( 'Page Title', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'cite_author' => array(
                    'label' => __( 'Author', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'cite_summary' => array(
                    'label' => __( 'Summary', 'reactions-indieweb' ),
                    'type'  => 'textarea',
                    'rows'  => 3,
                ),
            ),
            'reply' => array(
                'in_reply_to' => array(
                    'label'    => __( 'URL Replying To', 'reactions-indieweb' ),
                    'type'     => 'url',
                    'required' => true,
                ),
                'cite_name' => array(
                    'label' => __( 'Original Title', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
                'cite_author' => array(
                    'label' => __( 'Author', 'reactions-indieweb' ),
                    'type'  => 'text',
                ),
            ),
        );
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

        // Main reaction details meta box.
        add_meta_box(
            'reactions_indieweb_details',
            __( 'Reaction Details', 'reactions-indieweb' ),
            array( $this, 'render_details_meta_box' ),
            'post',
            'normal',
            'high'
        );

        // Media lookup sidebar.
        add_meta_box(
            'reactions_indieweb_lookup',
            __( 'Media Lookup', 'reactions-indieweb' ),
            array( $this, 'render_lookup_meta_box' ),
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
        wp_nonce_field( 'reactions_indieweb_meta', 'reactions_indieweb_meta_nonce' );

        // Get current post kind.
        $kinds = wp_get_object_terms( $post->ID, 'kind', array( 'fields' => 'slugs' ) );
        $current_kind = ! empty( $kinds ) ? $kinds[0] : '';

        ?>
        <div class="reactions-meta-box">
            <!-- Post kind selector -->
            <div class="meta-field kind-selector">
                <label for="reactions_post_kind"><?php esc_html_e( 'Post Kind', 'reactions-indieweb' ); ?></label>
                <select name="reactions_post_kind" id="reactions_post_kind" class="widefat">
                    <option value=""><?php esc_html_e( 'Select a post kind...', 'reactions-indieweb' ); ?></option>
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
                    <?php esc_html_e( 'Select a post kind above to see the relevant fields.', 'reactions-indieweb' ); ?>
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
        $meta_key = "_reactions_indieweb_{$field_id}";
        $value    = get_post_meta( $post_id, $meta_key, true );
        $name     = "reactions_meta[{$kind}][{$field_id}]";
        $id       = "reactions_{$kind}_{$field_id}";
        $class    = $field['class'] ?? 'widefat';
        $required = ! empty( $field['required'] ) ? 'required' : '';

        // Handle conditional display.
        $wrapper_style = '';
        if ( ! empty( $field['depends_on'] ) ) {
            list( $dep_field, $dep_value ) = explode( ':', $field['depends_on'] );
            $dep_meta_key = "_reactions_indieweb_{$dep_field}";
            $dep_current  = get_post_meta( $post_id, $dep_meta_key, true );
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
                    echo '<option value="">' . esc_html__( 'Select...', 'reactions-indieweb' ) . '</option>';
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
                    echo '<button type="button" class="button button-small clear-rating">' . esc_html__( 'Clear', 'reactions-indieweb' ) . '</button>';
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
                            <?php esc_html_e( 'Select Image', 'reactions-indieweb' ); ?>
                        </button>
                        <button type="button" class="button remove-image" <?php echo $value ? '' : 'style="display: none;"'; ?>>
                            <?php esc_html_e( 'Remove', 'reactions-indieweb' ); ?>
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
        <div class="reactions-lookup-box">
            <p class="description">
                <?php esc_html_e( 'Search for media to auto-fill details.', 'reactions-indieweb' ); ?>
            </p>

            <div class="lookup-form">
                <select id="lookup-type" class="widefat">
                    <option value="music"><?php esc_html_e( 'Music', 'reactions-indieweb' ); ?></option>
                    <option value="movie"><?php esc_html_e( 'Movie', 'reactions-indieweb' ); ?></option>
                    <option value="tv"><?php esc_html_e( 'TV Show', 'reactions-indieweb' ); ?></option>
                    <option value="book"><?php esc_html_e( 'Book', 'reactions-indieweb' ); ?></option>
                    <option value="podcast"><?php esc_html_e( 'Podcast', 'reactions-indieweb' ); ?></option>
                </select>

                <input type="text" id="lookup-query" class="widefat" placeholder="<?php esc_attr_e( 'Search...', 'reactions-indieweb' ); ?>">

                <button type="button" id="lookup-search" class="button widefat">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( 'Search', 'reactions-indieweb' ); ?>
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
        if ( ! isset( $_POST['reactions_indieweb_meta_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['reactions_indieweb_meta_nonce'] ) ), 'reactions_indieweb_meta' ) ) {
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
        if ( isset( $_POST['reactions_post_kind'] ) ) {
            $kind = sanitize_text_field( wp_unslash( $_POST['reactions_post_kind'] ) );
            if ( ! empty( $kind ) && taxonomy_exists( 'kind' ) ) {
                wp_set_object_terms( $post_id, $kind, 'kind' );
            }
        }

        // Save meta fields.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $meta_data = isset( $_POST['reactions_meta'] ) ? wp_unslash( $_POST['reactions_meta'] ) : array();

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

                $meta_key = "_reactions_indieweb_{$field_id}";
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
        if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'post' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_media();
    }
}
