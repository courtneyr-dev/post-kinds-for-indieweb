<?php
/**
 * Quick Post
 *
 * Quick post creation interface for reactions.
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

namespace ReactionsForIndieWeb\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Quick post class.
 */
class Quick_Post {

    /**
     * Admin instance.
     *
     * @var Admin
     */
    private Admin $admin;

    /**
     * Constructor.
     *
     * @param Admin $admin Admin instance.
     */
    public function __construct( Admin $admin ) {
        $this->admin = $admin;
    }

    /**
     * Initialize quick post.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_ajax_reactions_indieweb_quick_post', array( $this, 'ajax_create_post' ) );
        add_action( 'wp_ajax_reactions_indieweb_quick_lookup', array( $this, 'ajax_quick_lookup' ) );
    }

    /**
     * Render the quick post page.
     *
     * @return void
     */
    public function render(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        $post_kinds = $this->admin->get_post_kinds();

        ?>
        <div class="wrap reactions-indieweb-quick-post">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <p class="description">
                <?php esc_html_e( 'Quickly create reaction posts by searching for media or entering details.', 'reactions-indieweb' ); ?>
            </p>

            <div class="quick-post-container">
                <!-- Post Kind Selector -->
                <div class="kind-selector-tabs">
                    <?php foreach ( $post_kinds as $kind => $config ) : ?>
                        <?php if ( in_array( $kind, array( 'listen', 'watch', 'read', 'checkin', 'bookmark', 'like', 'reply', 'rsvp' ), true ) ) : ?>
                            <button type="button"
                                    class="kind-tab"
                                    data-kind="<?php echo esc_attr( $kind ); ?>">
                                <span class="dashicons <?php echo esc_attr( $config['icon'] ); ?>"></span>
                                <?php echo esc_html( $config['label'] ); ?>
                            </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <!-- Quick Post Forms -->
                <div class="quick-post-forms">
                    <?php $this->render_listen_form(); ?>
                    <?php $this->render_watch_form(); ?>
                    <?php $this->render_read_form(); ?>
                    <?php $this->render_checkin_form(); ?>
                    <?php $this->render_bookmark_form(); ?>
                    <?php $this->render_like_form(); ?>
                    <?php $this->render_reply_form(); ?>
                    <?php $this->render_rsvp_form(); ?>
                </div>

                <!-- Recent Posts -->
                <div class="recent-posts-section">
                    <h2><?php esc_html_e( 'Recent Reactions', 'reactions-indieweb' ); ?></h2>
                    <?php $this->render_recent_posts(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render listen quick post form.
     *
     * @return void
     */
    private function render_listen_form(): void {
        ?>
        <div class="quick-form" data-kind="listen" style="display: none;">
            <h2><span class="dashicons dashicons-format-audio"></span> <?php esc_html_e( 'Quick Listen', 'reactions-indieweb' ); ?></h2>

            <div class="search-section">
                <div class="search-input-group">
                    <input type="text" id="listen-search" class="widefat" placeholder="<?php esc_attr_e( 'Search for a song or album...', 'reactions-indieweb' ); ?>">
                    <button type="button" class="button search-button" data-type="music">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
                <div class="search-results" id="listen-results"></div>
            </div>

            <div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'reactions-indieweb' ); ?></span></div>

            <form class="quick-post-form" data-kind="listen">
                <div class="form-row">
                    <div class="form-group">
                        <label for="listen-track"><?php esc_html_e( 'Track Title', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="text" name="track_title" id="listen-track" class="widefat" required>
                    </div>
                    <div class="form-group">
                        <label for="listen-artist"><?php esc_html_e( 'Artist', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="text" name="artist_name" id="listen-artist" class="widefat" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="listen-album"><?php esc_html_e( 'Album', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="album_title" id="listen-album" class="widefat">
                    </div>
                    <div class="form-group">
                        <label for="listen-rating"><?php esc_html_e( 'Rating', 'reactions-indieweb' ); ?></label>
                        <?php $this->render_rating_input( 'listen-rating' ); ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="listen-content"><?php esc_html_e( 'Notes', 'reactions-indieweb' ); ?></label>
                        <textarea name="content" id="listen-content" rows="3" class="widefat"></textarea>
                    </div>
                </div>

                <input type="hidden" name="musicbrainz_id">
                <input type="hidden" name="cover_image">

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render watch quick post form.
     *
     * @return void
     */
    private function render_watch_form(): void {
        ?>
        <div class="quick-form" data-kind="watch" style="display: none;">
            <h2><span class="dashicons dashicons-video-alt2"></span> <?php esc_html_e( 'Quick Watch', 'reactions-indieweb' ); ?></h2>

            <div class="media-type-toggle">
                <button type="button" class="toggle-button active" data-subtype="movie">
                    <?php esc_html_e( 'Movie', 'reactions-indieweb' ); ?>
                </button>
                <button type="button" class="toggle-button" data-subtype="tv">
                    <?php esc_html_e( 'TV Show', 'reactions-indieweb' ); ?>
                </button>
            </div>

            <div class="search-section">
                <div class="search-input-group">
                    <input type="text" id="watch-search" class="widefat" placeholder="<?php esc_attr_e( 'Search for a movie or TV show...', 'reactions-indieweb' ); ?>">
                    <button type="button" class="button search-button" data-type="movie">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
                <div class="search-results" id="watch-results"></div>
            </div>

            <div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'reactions-indieweb' ); ?></span></div>

            <form class="quick-post-form" data-kind="watch">
                <input type="hidden" name="media_type" value="movie">

                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="watch-title"><?php esc_html_e( 'Title', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="text" name="media_title" id="watch-title" class="widefat" required>
                    </div>
                    <div class="form-group">
                        <label for="watch-year"><?php esc_html_e( 'Year', 'reactions-indieweb' ); ?></label>
                        <input type="number" name="release_year" id="watch-year" class="small-text" min="1900" max="2100">
                    </div>
                </div>

                <div class="form-row tv-fields" style="display: none;">
                    <div class="form-group">
                        <label for="watch-season"><?php esc_html_e( 'Season', 'reactions-indieweb' ); ?></label>
                        <input type="number" name="season_number" id="watch-season" class="small-text" min="1">
                    </div>
                    <div class="form-group">
                        <label for="watch-episode"><?php esc_html_e( 'Episode', 'reactions-indieweb' ); ?></label>
                        <input type="number" name="episode_number" id="watch-episode" class="small-text" min="1">
                    </div>
                    <div class="form-group">
                        <label for="watch-episode-title"><?php esc_html_e( 'Episode Title', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="episode_title" id="watch-episode-title" class="widefat">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="watch-rating"><?php esc_html_e( 'Rating', 'reactions-indieweb' ); ?></label>
                        <?php $this->render_rating_input( 'watch-rating' ); ?>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="rewatch" value="1">
                            <?php esc_html_e( 'Rewatch', 'reactions-indieweb' ); ?>
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="watch-content"><?php esc_html_e( 'Notes / Review', 'reactions-indieweb' ); ?></label>
                        <textarea name="content" id="watch-content" rows="3" class="widefat"></textarea>
                    </div>
                </div>

                <input type="hidden" name="tmdb_id">
                <input type="hidden" name="imdb_id">
                <input type="hidden" name="poster_image">

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render read quick post form.
     *
     * @return void
     */
    private function render_read_form(): void {
        ?>
        <div class="quick-form" data-kind="read" style="display: none;">
            <h2><span class="dashicons dashicons-book"></span> <?php esc_html_e( 'Quick Read', 'reactions-indieweb' ); ?></h2>

            <div class="search-section">
                <div class="search-input-group">
                    <input type="text" id="read-search" class="widefat" placeholder="<?php esc_attr_e( 'Search by title, author, or ISBN...', 'reactions-indieweb' ); ?>">
                    <button type="button" class="button search-button" data-type="book">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
                <div class="search-results" id="read-results"></div>
            </div>

            <div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'reactions-indieweb' ); ?></span></div>

            <form class="quick-post-form" data-kind="read">
                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="read-title"><?php esc_html_e( 'Book Title', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="text" name="book_title" id="read-title" class="widefat" required>
                    </div>
                    <div class="form-group">
                        <label for="read-author"><?php esc_html_e( 'Author', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="text" name="author_name" id="read-author" class="widefat" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="read-status"><?php esc_html_e( 'Status', 'reactions-indieweb' ); ?></label>
                        <select name="read_status" id="read-status" class="widefat">
                            <option value="reading"><?php esc_html_e( 'Currently Reading', 'reactions-indieweb' ); ?></option>
                            <option value="finished"><?php esc_html_e( 'Finished', 'reactions-indieweb' ); ?></option>
                            <option value="to-read"><?php esc_html_e( 'To Read', 'reactions-indieweb' ); ?></option>
                            <option value="abandoned"><?php esc_html_e( 'Abandoned', 'reactions-indieweb' ); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="read-progress"><?php esc_html_e( 'Progress', 'reactions-indieweb' ); ?></label>
                        <input type="number" name="progress_percent" id="read-progress" class="small-text" min="0" max="100" placeholder="%">
                    </div>
                    <div class="form-group">
                        <label for="read-rating"><?php esc_html_e( 'Rating', 'reactions-indieweb' ); ?></label>
                        <?php $this->render_rating_input( 'read-rating' ); ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="read-content"><?php esc_html_e( 'Notes / Review', 'reactions-indieweb' ); ?></label>
                        <textarea name="content" id="read-content" rows="3" class="widefat"></textarea>
                    </div>
                </div>

                <input type="hidden" name="isbn">
                <input type="hidden" name="openlibrary_id">
                <input type="hidden" name="cover_image">

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render checkin quick post form.
     *
     * @return void
     */
    private function render_checkin_form(): void {
        ?>
        <div class="quick-form" data-kind="checkin" style="display: none;">
            <h2><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Quick Checkin', 'reactions-indieweb' ); ?></h2>

            <div class="search-section">
                <div class="search-input-group">
                    <input type="text" id="checkin-search" class="widefat" placeholder="<?php esc_attr_e( 'Search for a venue...', 'reactions-indieweb' ); ?>">
                    <button type="button" class="button use-location-button">
                        <span class="dashicons dashicons-location-alt"></span>
                        <?php esc_html_e( 'Use My Location', 'reactions-indieweb' ); ?>
                    </button>
                    <button type="button" class="button search-button" data-type="venue">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                </div>
                <div class="search-results" id="checkin-results"></div>
            </div>

            <div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'reactions-indieweb' ); ?></span></div>

            <form class="quick-post-form" data-kind="checkin">
                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="checkin-venue"><?php esc_html_e( 'Venue Name', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="text" name="venue_name" id="checkin-venue" class="widefat" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="checkin-address"><?php esc_html_e( 'Address', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="venue_address" id="checkin-address" class="widefat">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="checkin-city"><?php esc_html_e( 'City', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="venue_city" id="checkin-city" class="widefat">
                    </div>
                    <div class="form-group">
                        <label for="checkin-country"><?php esc_html_e( 'Country', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="venue_country" id="checkin-country" class="widefat">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="checkin-content"><?php esc_html_e( 'Notes', 'reactions-indieweb' ); ?></label>
                        <textarea name="content" id="checkin-content" rows="3" class="widefat"></textarea>
                    </div>
                </div>

                <input type="hidden" name="latitude">
                <input type="hidden" name="longitude">
                <input type="hidden" name="foursquare_id">

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render bookmark quick post form.
     *
     * @return void
     */
    private function render_bookmark_form(): void {
        ?>
        <div class="quick-form" data-kind="bookmark" style="display: none;">
            <h2><span class="dashicons dashicons-bookmark"></span> <?php esc_html_e( 'Quick Bookmark', 'reactions-indieweb' ); ?></h2>

            <form class="quick-post-form" data-kind="bookmark">
                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="bookmark-url"><?php esc_html_e( 'URL', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="url" name="bookmark_of" id="bookmark-url" class="widefat" required placeholder="https://...">
                        <button type="button" class="button fetch-metadata-button">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Fetch Metadata', 'reactions-indieweb' ); ?>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="bookmark-title"><?php esc_html_e( 'Title', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="cite_name" id="bookmark-title" class="widefat">
                    </div>
                    <div class="form-group">
                        <label for="bookmark-author"><?php esc_html_e( 'Author', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="cite_author" id="bookmark-author" class="widefat">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="bookmark-summary"><?php esc_html_e( 'Summary / Quote', 'reactions-indieweb' ); ?></label>
                        <textarea name="cite_summary" id="bookmark-summary" rows="3" class="widefat"></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="bookmark-content"><?php esc_html_e( 'Your Notes', 'reactions-indieweb' ); ?></label>
                        <textarea name="content" id="bookmark-content" rows="3" class="widefat"></textarea>
                    </div>
                </div>

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render like quick post form.
     *
     * @return void
     */
    private function render_like_form(): void {
        ?>
        <div class="quick-form" data-kind="like" style="display: none;">
            <h2><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'Quick Like', 'reactions-indieweb' ); ?></h2>

            <form class="quick-post-form" data-kind="like">
                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="like-url"><?php esc_html_e( 'URL of Content You Like', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="url" name="like_of" id="like-url" class="widefat" required placeholder="https://...">
                        <button type="button" class="button fetch-metadata-button">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Fetch Metadata', 'reactions-indieweb' ); ?>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="like-title"><?php esc_html_e( 'Content Title', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="cite_name" id="like-title" class="widefat">
                    </div>
                    <div class="form-group">
                        <label for="like-author"><?php esc_html_e( 'Author', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="cite_author" id="like-author" class="widefat">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="like-content"><?php esc_html_e( 'Your Notes', 'reactions-indieweb' ); ?></label>
                        <textarea name="content" id="like-content" rows="2" class="widefat"></textarea>
                    </div>
                </div>

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render reply quick post form.
     *
     * @return void
     */
    private function render_reply_form(): void {
        ?>
        <div class="quick-form" data-kind="reply" style="display: none;">
            <h2><span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Quick Reply', 'reactions-indieweb' ); ?></h2>

            <form class="quick-post-form" data-kind="reply">
                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="reply-url"><?php esc_html_e( 'URL You Are Replying To', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="url" name="in_reply_to" id="reply-url" class="widefat" required placeholder="https://...">
                        <button type="button" class="button fetch-metadata-button">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Fetch Metadata', 'reactions-indieweb' ); ?>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="reply-title"><?php esc_html_e( 'Original Title', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="cite_name" id="reply-title" class="widefat">
                    </div>
                    <div class="form-group">
                        <label for="reply-author"><?php esc_html_e( 'Author', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="cite_author" id="reply-author" class="widefat">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="reply-content"><?php esc_html_e( 'Your Reply', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <textarea name="content" id="reply-content" rows="5" class="widefat" required></textarea>
                    </div>
                </div>

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render RSVP quick post form.
     *
     * @return void
     */
    private function render_rsvp_form(): void {
        ?>
        <div class="quick-form" data-kind="rsvp" style="display: none;">
            <h2><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Quick RSVP', 'reactions-indieweb' ); ?></h2>

            <form class="quick-post-form" data-kind="rsvp">
                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="rsvp-url"><?php esc_html_e( 'Event URL', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="url" name="event_url" id="rsvp-url" class="widefat" required placeholder="https://...">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="rsvp-event"><?php esc_html_e( 'Event Name', 'reactions-indieweb' ); ?> <span class="required">*</span></label>
                        <input type="text" name="event_name" id="rsvp-event" class="widefat" required>
                    </div>
                    <div class="form-group">
                        <label for="rsvp-value"><?php esc_html_e( 'Your RSVP', 'reactions-indieweb' ); ?></label>
                        <select name="rsvp_value" id="rsvp-value" class="widefat">
                            <option value="yes"><?php esc_html_e( 'Yes, attending', 'reactions-indieweb' ); ?></option>
                            <option value="maybe"><?php esc_html_e( 'Maybe', 'reactions-indieweb' ); ?></option>
                            <option value="interested"><?php esc_html_e( 'Interested', 'reactions-indieweb' ); ?></option>
                            <option value="no"><?php esc_html_e( 'No, not attending', 'reactions-indieweb' ); ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="rsvp-start"><?php esc_html_e( 'Start Date/Time', 'reactions-indieweb' ); ?></label>
                        <input type="datetime-local" name="event_start" id="rsvp-start" class="widefat">
                    </div>
                    <div class="form-group">
                        <label for="rsvp-location"><?php esc_html_e( 'Location', 'reactions-indieweb' ); ?></label>
                        <input type="text" name="event_location" id="rsvp-location" class="widefat">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="rsvp-content"><?php esc_html_e( 'Notes', 'reactions-indieweb' ); ?></label>
                        <textarea name="content" id="rsvp-content" rows="2" class="widefat"></textarea>
                    </div>
                </div>

                <?php $this->render_form_actions(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render rating input.
     *
     * @param string $id Input ID.
     * @return void
     */
    private function render_rating_input( string $id ): void {
        ?>
        <div class="star-rating-input" id="<?php echo esc_attr( $id ); ?>-wrapper">
            <input type="hidden" name="rating" id="<?php echo esc_attr( $id ); ?>" value="">
            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                <span class="star" data-value="<?php echo esc_attr( $i ); ?>">&#9733;</span>
            <?php endfor; ?>
            <button type="button" class="button button-small clear-rating"><?php esc_html_e( 'Clear', 'reactions-indieweb' ); ?></button>
        </div>
        <?php
    }

    /**
     * Render form actions.
     *
     * @return void
     */
    private function render_form_actions(): void {
        ?>
        <div class="form-actions">
            <div class="post-options">
                <label>
                    <select name="post_status" class="post-status-select">
                        <option value="publish"><?php esc_html_e( 'Publish', 'reactions-indieweb' ); ?></option>
                        <option value="draft"><?php esc_html_e( 'Draft', 'reactions-indieweb' ); ?></option>
                        <option value="private"><?php esc_html_e( 'Private', 'reactions-indieweb' ); ?></option>
                    </select>
                </label>
            </div>
            <div class="submit-actions">
                <button type="button" class="button clear-form-button">
                    <?php esc_html_e( 'Clear', 'reactions-indieweb' ); ?>
                </button>
                <button type="submit" class="button button-primary submit-quick-post">
                    <span class="dashicons dashicons-edit"></span>
                    <?php esc_html_e( 'Create Post', 'reactions-indieweb' ); ?>
                </button>
            </div>
        </div>
        <div class="form-feedback"></div>
        <?php
    }

    /**
     * Render recent posts.
     *
     * @return void
     */
    private function render_recent_posts(): void {
        $recent = get_posts( array(
            'numberposts' => 10,
            'post_type'   => 'post',
            'tax_query'   => array(
                array(
                    'taxonomy' => 'kind',
                    'operator' => 'EXISTS',
                ),
            ),
        ) );

        if ( empty( $recent ) ) {
            echo '<p class="description">' . esc_html__( 'No reaction posts yet.', 'reactions-indieweb' ) . '</p>';
            return;
        }

        ?>
        <ul class="recent-reactions-list">
            <?php foreach ( $recent as $post ) : ?>
                <?php
                $kinds = wp_get_object_terms( $post->ID, 'kind', array( 'fields' => 'slugs' ) );
                $kind  = ! empty( $kinds ) ? $kinds[0] : 'post';
                $icon  = $this->admin->get_post_kinds()[ $kind ]['icon'] ?? 'dashicons-admin-post';
                ?>
                <li>
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                    <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
                        <?php echo esc_html( $post->post_title ); ?>
                    </a>
                    <span class="post-date">
                        <?php echo esc_html( human_time_diff( get_post_time( 'U', false, $post ), time() ) . ' ' . __( 'ago', 'reactions-indieweb' ) ); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * AJAX handler: Create post.
     *
     * @return void
     */
    public function ajax_create_post(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $kind = isset( $_POST['kind'] ) ? sanitize_text_field( wp_unslash( $_POST['kind'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $data = isset( $_POST['data'] ) ? $this->sanitize_post_data( wp_unslash( $_POST['data'] ) ) : array();

        if ( empty( $kind ) ) {
            wp_send_json_error( array( 'message' => __( 'Post kind is required.', 'reactions-indieweb' ) ) );
        }

        $result = $this->create_reaction_post( $kind, $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'post_id'  => $result,
            'edit_url' => get_edit_post_link( $result, 'raw' ),
            'view_url' => get_permalink( $result ),
            'message'  => __( 'Post created successfully!', 'reactions-indieweb' ),
        ) );
    }

    /**
     * Create a reaction post.
     *
     * @param string               $kind Post kind.
     * @param array<string, mixed> $data Post data.
     * @return int|\WP_Error Post ID or error.
     */
    private function create_reaction_post( string $kind, array $data ) {
        $settings = get_option( 'reactions_indieweb_settings', array() );
        $post_status = $data['post_status'] ?? ( $settings['default_post_status'] ?? 'publish' );

        // Build title based on kind.
        $title = $this->build_post_title( $kind, $data );

        // Build content.
        $content = $this->build_post_content( $kind, $data );

        $post_data = array(
            'post_type'    => 'post',
            'post_status'  => $post_status,
            'post_title'   => $title,
            'post_content' => $content,
        );

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Set post kind.
        if ( taxonomy_exists( 'kind' ) ) {
            wp_set_object_terms( $post_id, $kind, 'kind' );
        }

        // Save meta fields.
        $meta_fields = $this->get_meta_fields_for_kind( $kind );
        foreach ( $meta_fields as $field ) {
            if ( isset( $data[ $field ] ) && '' !== $data[ $field ] ) {
                update_post_meta( $post_id, "_reactions_indieweb_{$field}", $data[ $field ] );
            }
        }

        return $post_id;
    }

    /**
     * Build post title based on kind.
     *
     * @param string               $kind Post kind.
     * @param array<string, mixed> $data Post data.
     * @return string Post title.
     */
    private function build_post_title( string $kind, array $data ): string {
        switch ( $kind ) {
            case 'listen':
                $title = $data['track_title'] ?? 'Unknown Track';
                if ( ! empty( $data['artist_name'] ) ) {
                    $title .= ' by ' . $data['artist_name'];
                }
                return $title;

            case 'watch':
                $title = $data['media_title'] ?? 'Unknown';
                if ( ! empty( $data['release_year'] ) ) {
                    $title .= ' (' . $data['release_year'] . ')';
                }
                return $title;

            case 'read':
                $title = $data['book_title'] ?? 'Unknown Book';
                if ( ! empty( $data['author_name'] ) ) {
                    $title .= ' by ' . $data['author_name'];
                }
                return $title;

            case 'checkin':
                return $data['venue_name'] ?? 'Checkin';

            case 'bookmark':
                return $data['cite_name'] ?? 'Bookmark';

            case 'like':
                return $data['cite_name'] ?? 'Like';

            case 'reply':
                return 'Re: ' . ( $data['cite_name'] ?? 'Reply' );

            case 'rsvp':
                return 'RSVP: ' . ( $data['event_name'] ?? 'Event' );

            default:
                return 'Reaction';
        }
    }

    /**
     * Build post content based on kind.
     *
     * @param string               $kind Post kind.
     * @param array<string, mixed> $data Post data.
     * @return string Post content.
     */
    private function build_post_content( string $kind, array $data ): string {
        $content = '';
        $user_content = $data['content'] ?? '';

        if ( ! empty( $user_content ) ) {
            $content = '<!-- wp:paragraph --><p>' . esc_html( $user_content ) . '</p><!-- /wp:paragraph -->';
        }

        return $content;
    }

    /**
     * Get meta fields for a kind.
     *
     * @param string $kind Post kind.
     * @return array<int, string> Meta field names.
     */
    private function get_meta_fields_for_kind( string $kind ): array {
        $fields = array(
            'listen'   => array( 'track_title', 'artist_name', 'album_title', 'musicbrainz_id', 'cover_image', 'rating' ),
            'watch'    => array( 'media_title', 'media_type', 'release_year', 'tmdb_id', 'imdb_id', 'poster_image', 'rating', 'rewatch', 'season_number', 'episode_number' ),
            'read'     => array( 'book_title', 'author_name', 'isbn', 'openlibrary_id', 'cover_image', 'rating', 'read_status', 'progress_percent' ),
            'checkin'  => array( 'venue_name', 'venue_address', 'venue_city', 'venue_country', 'latitude', 'longitude', 'foursquare_id' ),
            'bookmark' => array( 'bookmark_of', 'cite_name', 'cite_author', 'cite_summary' ),
            'like'     => array( 'like_of', 'cite_name', 'cite_author' ),
            'reply'    => array( 'in_reply_to', 'cite_name', 'cite_author' ),
            'rsvp'     => array( 'event_url', 'event_name', 'rsvp_value', 'event_start', 'event_location' ),
        );

        return $fields[ $kind ] ?? array();
    }

    /**
     * Sanitize post data.
     *
     * @param mixed $data Raw data.
     * @return array<string, mixed> Sanitized data.
     */
    private function sanitize_post_data( $data ): array {
        if ( ! is_array( $data ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $data as $key => $value ) {
            $key = sanitize_key( $key );

            // URL fields.
            if ( in_array( $key, array( 'bookmark_of', 'like_of', 'in_reply_to', 'event_url', 'venue_url', 'listen_url', 'watch_url' ), true ) ) {
                $sanitized[ $key ] = esc_url_raw( $value );
            } elseif ( 'content' === $key || 'cite_summary' === $key ) {
                $sanitized[ $key ] = sanitize_textarea_field( $value );
            } elseif ( in_array( $key, array( 'rating', 'progress_percent', 'release_year', 'season_number', 'episode_number' ), true ) ) {
                $sanitized[ $key ] = absint( $value );
            } else {
                $sanitized[ $key ] = sanitize_text_field( $value );
            }
        }

        return $sanitized;
    }

    /**
     * AJAX handler: Quick lookup.
     *
     * @return void
     */
    public function ajax_quick_lookup(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $type  = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        if ( empty( $type ) || empty( $query ) ) {
            wp_send_json_error( array( 'message' => __( 'Type and query are required.', 'reactions-indieweb' ) ) );
        }

        // Use the admin lookup method.
        $results = $this->admin->ajax_lookup_media();
    }
}
