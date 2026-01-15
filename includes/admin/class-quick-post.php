<?php
/**
 * Quick Post
 *
 * Quick post creation interface for post kinds.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Admin;

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
		add_action( 'wp_ajax_postkind_indieweb_quick_post', [ $this, 'ajax_create_post' ] );
		add_action( 'wp_ajax_postkind_indieweb_quick_lookup', [ $this, 'ajax_quick_lookup' ] );
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
		<div class="wrap post-kinds-indieweb-quick-post">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Quickly create reaction posts by searching for media or entering details.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<div class="quick-post-container">
				<!-- Post Kind Selector -->
				<div class="kind-selector-tabs">
					<?php
					$quick_post_kinds = [
						'listen',
						'watch',
						'read',
						'play',
						'checkin',
						'eat',
						'drink',
						'like',
						'favorite',
						'bookmark',
						'reply',
						'rsvp',
						'jam',
						'wish',
						'mood',
						'acquisition',
						'recipe',
					];
					foreach ( $post_kinds as $kind => $config ) :
						if ( in_array( $kind, $quick_post_kinds, true ) ) :
							?>
							<button type="button"
									class="kind-tab"
									data-kind="<?php echo esc_attr( $kind ); ?>">
								<span class="dashicons <?php echo esc_attr( $config['icon'] ); ?>"></span>
								<?php echo esc_html( $config['label'] ); ?>
							</button>
							<?php
						endif;
					endforeach;
					?>
				</div>

				<!-- Quick Post Forms -->
				<div class="quick-post-forms">
					<?php $this->render_listen_form(); ?>
					<?php $this->render_watch_form(); ?>
					<?php $this->render_read_form(); ?>
					<?php $this->render_play_form(); ?>
					<?php $this->render_checkin_form(); ?>
					<?php $this->render_eat_form(); ?>
					<?php $this->render_drink_form(); ?>
					<?php $this->render_like_form(); ?>
					<?php $this->render_favorite_form(); ?>
					<?php $this->render_bookmark_form(); ?>
					<?php $this->render_reply_form(); ?>
					<?php $this->render_rsvp_form(); ?>
					<?php $this->render_jam_form(); ?>
					<?php $this->render_wish_form(); ?>
					<?php $this->render_mood_form(); ?>
					<?php $this->render_acquisition_form(); ?>
					<?php $this->render_recipe_form(); ?>
				</div>

				<!-- Recent Posts -->
				<div class="recent-posts-section">
					<h2><?php esc_html_e( 'Recent Reactions', 'post-kinds-for-indieweb' ); ?></h2>
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
			<h2><span class="dashicons dashicons-format-audio"></span> <?php esc_html_e( 'Quick Listen', 'post-kinds-for-indieweb' ); ?></h2>

			<div class="search-section">
				<div class="search-input-group">
					<input type="text" id="listen-search" class="widefat" placeholder="<?php esc_attr_e( 'Search for a song or album...', 'post-kinds-for-indieweb' ); ?>">
					<button type="button" class="button search-button" data-type="music">
						<span class="dashicons dashicons-search"></span>
					</button>
				</div>
				<div class="search-results" id="listen-results"></div>
			</div>

			<div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'post-kinds-for-indieweb' ); ?></span></div>

			<form class="quick-post-form" data-kind="listen">
				<div class="form-row">
					<div class="form-group">
						<label for="listen-track"><?php esc_html_e( 'Track Title', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="track_title" id="listen-track" class="widefat" required>
					</div>
					<div class="form-group">
						<label for="listen-artist"><?php esc_html_e( 'Artist', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="artist_name" id="listen-artist" class="widefat" required>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="listen-album"><?php esc_html_e( 'Album', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="album_title" id="listen-album" class="widefat">
					</div>
					<div class="form-group">
						<label for="listen-rating"><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'listen-rating' ); ?>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="listen-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
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
			<h2><span class="dashicons dashicons-video-alt2"></span> <?php esc_html_e( 'Quick Watch', 'post-kinds-for-indieweb' ); ?></h2>

			<div class="media-type-toggle">
				<button type="button" class="toggle-button active" data-subtype="movie">
					<?php esc_html_e( 'Movie', 'post-kinds-for-indieweb' ); ?>
				</button>
				<button type="button" class="toggle-button" data-subtype="tv">
					<?php esc_html_e( 'TV Show', 'post-kinds-for-indieweb' ); ?>
				</button>
			</div>

			<div class="search-section">
				<div class="search-input-group">
					<input type="text" id="watch-search" class="widefat" placeholder="<?php esc_attr_e( 'Search for a movie or TV show...', 'post-kinds-for-indieweb' ); ?>">
					<button type="button" class="button search-button" data-type="movie">
						<span class="dashicons dashicons-search"></span>
					</button>
				</div>
				<div class="search-results" id="watch-results"></div>
			</div>

			<div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'post-kinds-for-indieweb' ); ?></span></div>

			<form class="quick-post-form" data-kind="watch">
				<input type="hidden" name="media_type" value="movie">

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="watch-title"><?php esc_html_e( 'Title', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="media_title" id="watch-title" class="widefat" required>
					</div>
					<div class="form-group">
						<label for="watch-year"><?php esc_html_e( 'Year', 'post-kinds-for-indieweb' ); ?></label>
						<input type="number" name="release_year" id="watch-year" class="small-text" min="1900" max="2100">
					</div>
				</div>

				<div class="form-row tv-fields" style="display: none;">
					<div class="form-group">
						<label for="watch-season"><?php esc_html_e( 'Season', 'post-kinds-for-indieweb' ); ?></label>
						<input type="number" name="season_number" id="watch-season" class="small-text" min="1">
					</div>
					<div class="form-group">
						<label for="watch-episode"><?php esc_html_e( 'Episode', 'post-kinds-for-indieweb' ); ?></label>
						<input type="number" name="episode_number" id="watch-episode" class="small-text" min="1">
					</div>
					<div class="form-group">
						<label for="watch-episode-title"><?php esc_html_e( 'Episode Title', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="episode_title" id="watch-episode-title" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="watch-rating"><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'watch-rating' ); ?>
					</div>
					<div class="form-group">
						<label>
							<input type="checkbox" name="rewatch" value="1">
							<?php esc_html_e( 'Rewatch', 'post-kinds-for-indieweb' ); ?>
						</label>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="watch-content"><?php esc_html_e( 'Notes / Review', 'post-kinds-for-indieweb' ); ?></label>
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
			<h2><span class="dashicons dashicons-book"></span> <?php esc_html_e( 'Quick Read', 'post-kinds-for-indieweb' ); ?></h2>

			<div class="search-section">
				<div class="search-input-group">
					<input type="text" id="read-search" class="widefat" placeholder="<?php esc_attr_e( 'Search by title, author, or ISBN...', 'post-kinds-for-indieweb' ); ?>">
					<button type="button" class="button search-button" data-type="book">
						<span class="dashicons dashicons-search"></span>
					</button>
				</div>
				<div class="search-results" id="read-results"></div>
			</div>

			<div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'post-kinds-for-indieweb' ); ?></span></div>

			<form class="quick-post-form" data-kind="read">
				<div class="form-row">
					<div class="form-group flex-2">
						<label for="read-title"><?php esc_html_e( 'Book Title', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="book_title" id="read-title" class="widefat" required>
					</div>
					<div class="form-group">
						<label for="read-author"><?php esc_html_e( 'Author', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="author_name" id="read-author" class="widefat" required>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="read-status"><?php esc_html_e( 'Status', 'post-kinds-for-indieweb' ); ?></label>
						<select name="read_status" id="read-status" class="widefat">
							<option value="reading"><?php esc_html_e( 'Currently Reading', 'post-kinds-for-indieweb' ); ?></option>
							<option value="finished"><?php esc_html_e( 'Finished', 'post-kinds-for-indieweb' ); ?></option>
							<option value="to-read"><?php esc_html_e( 'To Read', 'post-kinds-for-indieweb' ); ?></option>
							<option value="abandoned"><?php esc_html_e( 'Abandoned', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
					<div class="form-group">
						<label for="read-progress"><?php esc_html_e( 'Progress', 'post-kinds-for-indieweb' ); ?></label>
						<input type="number" name="progress_percent" id="read-progress" class="small-text" min="0" max="100" placeholder="%">
					</div>
					<div class="form-group">
						<label for="read-rating"><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'read-rating' ); ?>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="read-content"><?php esc_html_e( 'Notes / Review', 'post-kinds-for-indieweb' ); ?></label>
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
			<h2><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Quick Checkin', 'post-kinds-for-indieweb' ); ?></h2>

			<div class="search-section">
				<div class="search-input-group">
					<input type="text" id="checkin-search" class="widefat" placeholder="<?php esc_attr_e( 'Search for a venue...', 'post-kinds-for-indieweb' ); ?>">
					<button type="button" class="button use-location-button">
						<span class="dashicons dashicons-location-alt"></span>
						<?php esc_html_e( 'Use My Location', 'post-kinds-for-indieweb' ); ?>
					</button>
					<button type="button" class="button search-button" data-type="venue">
						<span class="dashicons dashicons-search"></span>
					</button>
				</div>
				<div class="search-results" id="checkin-results"></div>
			</div>

			<div class="or-divider"><span><?php esc_html_e( 'or enter manually', 'post-kinds-for-indieweb' ); ?></span></div>

			<form class="quick-post-form" data-kind="checkin">
				<div class="form-row">
					<div class="form-group full-width">
						<label for="checkin-venue"><?php esc_html_e( 'Venue Name', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="venue_name" id="checkin-venue" class="widefat" required>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="checkin-address"><?php esc_html_e( 'Address', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="venue_address" id="checkin-address" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="checkin-city"><?php esc_html_e( 'City', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="venue_city" id="checkin-city" class="widefat">
					</div>
					<div class="form-group">
						<label for="checkin-country"><?php esc_html_e( 'Country', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="venue_country" id="checkin-country" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="checkin-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
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
			<h2><span class="dashicons dashicons-bookmark"></span> <?php esc_html_e( 'Quick Bookmark', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="bookmark">
				<div class="form-row">
					<div class="form-group full-width">
						<label for="bookmark-url"><?php esc_html_e( 'URL', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="url" name="bookmark_of" id="bookmark-url" class="widefat" required placeholder="https://...">
						<button type="button" class="button fetch-metadata-button">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Fetch Metadata', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="bookmark-title"><?php esc_html_e( 'Title', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="cite_name" id="bookmark-title" class="widefat">
					</div>
					<div class="form-group">
						<label for="bookmark-author"><?php esc_html_e( 'Author', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="cite_author" id="bookmark-author" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="bookmark-summary"><?php esc_html_e( 'Summary / Quote', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="cite_summary" id="bookmark-summary" rows="3" class="widefat"></textarea>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="bookmark-content"><?php esc_html_e( 'Your Notes', 'post-kinds-for-indieweb' ); ?></label>
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
			<h2><span class="dashicons dashicons-heart"></span> <?php esc_html_e( 'Quick Like', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="like">
				<div class="form-row">
					<div class="form-group full-width">
						<label for="like-url"><?php esc_html_e( 'URL of Content You Like', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="url" name="like_of" id="like-url" class="widefat" required placeholder="https://...">
						<button type="button" class="button fetch-metadata-button">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Fetch Metadata', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="like-title"><?php esc_html_e( 'Content Title', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="cite_name" id="like-title" class="widefat">
					</div>
					<div class="form-group">
						<label for="like-author"><?php esc_html_e( 'Author', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="cite_author" id="like-author" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="like-content"><?php esc_html_e( 'Your Notes', 'post-kinds-for-indieweb' ); ?></label>
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
			<h2><span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Quick Reply', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="reply">
				<div class="form-row">
					<div class="form-group full-width">
						<label for="reply-url"><?php esc_html_e( 'URL You Are Replying To', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="url" name="in_reply_to" id="reply-url" class="widefat" required placeholder="https://...">
						<button type="button" class="button fetch-metadata-button">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Fetch Metadata', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="reply-title"><?php esc_html_e( 'Original Title', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="cite_name" id="reply-title" class="widefat">
					</div>
					<div class="form-group">
						<label for="reply-author"><?php esc_html_e( 'Author', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="cite_author" id="reply-author" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="reply-content"><?php esc_html_e( 'Your Reply', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
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
			<h2><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Quick RSVP', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="rsvp">
				<div class="form-row">
					<div class="form-group full-width">
						<label for="rsvp-url"><?php esc_html_e( 'Event URL', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="url" name="event_url" id="rsvp-url" class="widefat" required placeholder="https://...">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="rsvp-event"><?php esc_html_e( 'Event Name', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="event_name" id="rsvp-event" class="widefat" required>
					</div>
					<div class="form-group">
						<label for="rsvp-value"><?php esc_html_e( 'Your RSVP', 'post-kinds-for-indieweb' ); ?></label>
						<select name="rsvp_value" id="rsvp-value" class="widefat">
							<option value="yes"><?php esc_html_e( 'Yes, attending', 'post-kinds-for-indieweb' ); ?></option>
							<option value="maybe"><?php esc_html_e( 'Maybe', 'post-kinds-for-indieweb' ); ?></option>
							<option value="interested"><?php esc_html_e( 'Interested', 'post-kinds-for-indieweb' ); ?></option>
							<option value="no"><?php esc_html_e( 'No, not attending', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="rsvp-start"><?php esc_html_e( 'Start Date/Time', 'post-kinds-for-indieweb' ); ?></label>
						<input type="datetime-local" name="event_start" id="rsvp-start" class="widefat">
					</div>
					<div class="form-group">
						<label for="rsvp-location"><?php esc_html_e( 'Location', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="event_location" id="rsvp-location" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="rsvp-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="rsvp-content" rows="2" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render play (game) quick post form.
	 *
	 * @return void
	 */
	private function render_play_form(): void {
		?>
		<div class="quick-form" data-kind="play" style="display: none;">
			<h2><span class="dashicons dashicons-games"></span> <?php esc_html_e( 'Quick Play', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="play">
				<div class="form-row">
					<div class="form-group flex-2">
						<label for="play-title"><?php esc_html_e( 'Game Title', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="play_title" id="play-title" class="widefat" required>
					</div>
					<div class="form-group">
						<label for="play-platform"><?php esc_html_e( 'Platform', 'post-kinds-for-indieweb' ); ?></label>
						<select name="play_platform" id="play-platform" class="widefat">
							<option value=""><?php esc_html_e( 'Select...', 'post-kinds-for-indieweb' ); ?></option>
							<option value="PC">PC</option>
							<option value="PlayStation 5">PlayStation 5</option>
							<option value="PlayStation 4">PlayStation 4</option>
							<option value="Xbox Series X/S">Xbox Series X/S</option>
							<option value="Xbox One">Xbox One</option>
							<option value="Nintendo Switch">Nintendo Switch</option>
							<option value="iOS">iOS</option>
							<option value="Android">Android</option>
							<option value="Board Game"><?php esc_html_e( 'Board Game', 'post-kinds-for-indieweb' ); ?></option>
							<option value="Card Game"><?php esc_html_e( 'Card Game', 'post-kinds-for-indieweb' ); ?></option>
							<option value="Tabletop RPG"><?php esc_html_e( 'Tabletop RPG', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="play-status"><?php esc_html_e( 'Status', 'post-kinds-for-indieweb' ); ?></label>
						<select name="play_status" id="play-status" class="widefat">
							<option value="playing"><?php esc_html_e( 'Playing', 'post-kinds-for-indieweb' ); ?></option>
							<option value="completed"><?php esc_html_e( 'Completed', 'post-kinds-for-indieweb' ); ?></option>
							<option value="abandoned"><?php esc_html_e( 'Abandoned', 'post-kinds-for-indieweb' ); ?></option>
							<option value="backlog"><?php esc_html_e( 'Backlog', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
					<div class="form-group">
						<label for="play-hours"><?php esc_html_e( 'Hours Played', 'post-kinds-for-indieweb' ); ?></label>
						<input type="number" name="play_hours" id="play-hours" class="widefat" min="0" step="0.5">
					</div>
					<div class="form-group">
						<label><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'play-rating' ); ?>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="play-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="play-content" rows="2" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render eat quick post form.
	 *
	 * @return void
	 */
	private function render_eat_form(): void {
		?>
		<div class="quick-form" data-kind="eat" style="display: none;">
			<h2><span class="dashicons dashicons-carrot"></span> <?php esc_html_e( 'Quick Eat', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="eat">
				<div class="form-row">
					<div class="form-group flex-2">
						<label for="eat-name"><?php esc_html_e( 'Food/Meal', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="eat_name" id="eat-name" class="widefat" required placeholder="<?php esc_attr_e( 'What did you eat?', 'post-kinds-for-indieweb' ); ?>">
					</div>
					<div class="form-group">
						<label for="eat-type"><?php esc_html_e( 'Meal Type', 'post-kinds-for-indieweb' ); ?></label>
						<select name="eat_type" id="eat-type" class="widefat">
							<option value=""><?php esc_html_e( 'Select...', 'post-kinds-for-indieweb' ); ?></option>
							<option value="breakfast"><?php esc_html_e( 'Breakfast', 'post-kinds-for-indieweb' ); ?></option>
							<option value="lunch"><?php esc_html_e( 'Lunch', 'post-kinds-for-indieweb' ); ?></option>
							<option value="dinner"><?php esc_html_e( 'Dinner', 'post-kinds-for-indieweb' ); ?></option>
							<option value="snack"><?php esc_html_e( 'Snack', 'post-kinds-for-indieweb' ); ?></option>
							<option value="dessert"><?php esc_html_e( 'Dessert', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="eat-restaurant"><?php esc_html_e( 'Restaurant/Location', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="eat_restaurant" id="eat-restaurant" class="widefat">
					</div>
					<div class="form-group">
						<label><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'eat-rating' ); ?>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="eat-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="eat-content" rows="2" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render drink quick post form.
	 *
	 * @return void
	 */
	private function render_drink_form(): void {
		?>
		<div class="quick-form" data-kind="drink" style="display: none;">
			<h2><span class="dashicons dashicons-coffee"></span> <?php esc_html_e( 'Quick Drink', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="drink">
				<div class="form-row">
					<div class="form-group flex-2">
						<label for="drink-name"><?php esc_html_e( 'Drink Name', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="drink_name" id="drink-name" class="widefat" required placeholder="<?php esc_attr_e( 'What are you drinking?', 'post-kinds-for-indieweb' ); ?>">
					</div>
					<div class="form-group">
						<label for="drink-type"><?php esc_html_e( 'Type', 'post-kinds-for-indieweb' ); ?></label>
						<select name="drink_type" id="drink-type" class="widefat">
							<option value=""><?php esc_html_e( 'Select...', 'post-kinds-for-indieweb' ); ?></option>
							<option value="coffee"><?php esc_html_e( 'Coffee', 'post-kinds-for-indieweb' ); ?></option>
							<option value="tea"><?php esc_html_e( 'Tea', 'post-kinds-for-indieweb' ); ?></option>
							<option value="beer"><?php esc_html_e( 'Beer', 'post-kinds-for-indieweb' ); ?></option>
							<option value="wine"><?php esc_html_e( 'Wine', 'post-kinds-for-indieweb' ); ?></option>
							<option value="cocktail"><?php esc_html_e( 'Cocktail', 'post-kinds-for-indieweb' ); ?></option>
							<option value="spirit"><?php esc_html_e( 'Spirit', 'post-kinds-for-indieweb' ); ?></option>
							<option value="soda"><?php esc_html_e( 'Soda', 'post-kinds-for-indieweb' ); ?></option>
							<option value="juice"><?php esc_html_e( 'Juice', 'post-kinds-for-indieweb' ); ?></option>
							<option value="water"><?php esc_html_e( 'Water', 'post-kinds-for-indieweb' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="drink-brewery"><?php esc_html_e( 'Brewery/Brand', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="drink_brewery" id="drink-brewery" class="widefat">
					</div>
					<div class="form-group">
						<label><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'drink-rating' ); ?>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="drink-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="drink-content" rows="2" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render favorite quick post form.
	 *
	 * @return void
	 */
	private function render_favorite_form(): void {
		?>
		<div class="quick-form" data-kind="favorite" style="display: none;">
			<h2><span class="dashicons dashicons-star-filled"></span> <?php esc_html_e( 'Quick Favorite', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="favorite">
				<div class="form-row">
					<div class="form-group full-width">
						<label for="favorite-url"><?php esc_html_e( 'URL', 'post-kinds-for-indieweb' ); ?></label>
						<input type="url" name="favorite_url" id="favorite-url" class="widefat" placeholder="https://...">
						<button type="button" class="button fetch-metadata-button">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Fetch Metadata', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="favorite-name"><?php esc_html_e( 'Name/Title', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="favorite_name" id="favorite-name" class="widefat" required>
					</div>
					<div class="form-group">
						<label><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'favorite-rating' ); ?>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="favorite-content"><?php esc_html_e( 'Why is this a favorite?', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="favorite-content" rows="3" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render jam quick post form.
	 *
	 * @return void
	 */
	private function render_jam_form(): void {
		?>
		<div class="quick-form" data-kind="jam" style="display: none;">
			<h2><span class="dashicons dashicons-playlist-audio"></span> <?php esc_html_e( 'Quick Jam', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="jam">
				<div class="form-row">
					<div class="form-group flex-2">
						<label for="jam-track"><?php esc_html_e( 'Track', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="jam_track" id="jam-track" class="widefat" required>
					</div>
					<div class="form-group">
						<label for="jam-artist"><?php esc_html_e( 'Artist', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="jam_artist" id="jam-artist" class="widefat">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="jam-album"><?php esc_html_e( 'Album', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="jam_album" id="jam-album" class="widefat">
					</div>
					<div class="form-group">
						<label for="jam-url"><?php esc_html_e( 'Link', 'post-kinds-for-indieweb' ); ?></label>
						<input type="url" name="jam_url" id="jam-url" class="widefat" placeholder="https://...">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="jam-content"><?php esc_html_e( 'Why is this your jam?', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="jam-content" rows="2" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render wish quick post form.
	 *
	 * @return void
	 */
	private function render_wish_form(): void {
		?>
		<div class="quick-form" data-kind="wish" style="display: none;">
			<h2><span class="dashicons dashicons-pressthis"></span> <?php esc_html_e( 'Quick Wish', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="wish">
				<div class="form-row">
					<div class="form-group flex-2">
						<label for="wish-name"><?php esc_html_e( 'Item Name', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="wish_name" id="wish-name" class="widefat" required placeholder="<?php esc_attr_e( 'What do you wish for?', 'post-kinds-for-indieweb' ); ?>">
					</div>
					<div class="form-group">
						<label for="wish-type"><?php esc_html_e( 'Type', 'post-kinds-for-indieweb' ); ?></label>
						<select name="wish_type" id="wish-type" class="widefat">
							<option value=""><?php esc_html_e( 'Select...', 'post-kinds-for-indieweb' ); ?></option>
							<option value="book"><?php esc_html_e( 'Book', 'post-kinds-for-indieweb' ); ?></option>
							<option value="movie"><?php esc_html_e( 'Movie/Show', 'post-kinds-for-indieweb' ); ?></option>
							<option value="game"><?php esc_html_e( 'Game', 'post-kinds-for-indieweb' ); ?></option>
							<option value="music"><?php esc_html_e( 'Music', 'post-kinds-for-indieweb' ); ?></option>
							<option value="product"><?php esc_html_e( 'Product', 'post-kinds-for-indieweb' ); ?></option>
							<option value="experience"><?php esc_html_e( 'Experience', 'post-kinds-for-indieweb' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="wish-url"><?php esc_html_e( 'URL', 'post-kinds-for-indieweb' ); ?></label>
						<input type="url" name="wish_url" id="wish-url" class="widefat" placeholder="https://...">
					</div>
					<div class="form-group">
						<label for="wish-priority"><?php esc_html_e( 'Priority', 'post-kinds-for-indieweb' ); ?></label>
						<select name="wish_priority" id="wish-priority" class="widefat">
							<option value="medium"><?php esc_html_e( 'Medium', 'post-kinds-for-indieweb' ); ?></option>
							<option value="high"><?php esc_html_e( 'High', 'post-kinds-for-indieweb' ); ?></option>
							<option value="low"><?php esc_html_e( 'Low', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="wish-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="wish-content" rows="2" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render mood quick post form.
	 *
	 * @return void
	 */
	private function render_mood_form(): void {
		?>
		<div class="quick-form" data-kind="mood" style="display: none;">
			<h2><span class="dashicons dashicons-smiley"></span> <?php esc_html_e( 'Quick Mood', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="mood">
				<div class="form-row">
					<div class="form-group">
						<label for="mood-emoji"><?php esc_html_e( 'Mood Emoji', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="mood_emoji" id="mood-emoji" class="widefat" maxlength="10" placeholder="😊">
					</div>
					<div class="form-group flex-2">
						<label for="mood-label"><?php esc_html_e( 'Mood Label', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="mood_label" id="mood-label" class="widefat" required placeholder="<?php esc_attr_e( 'How are you feeling?', 'post-kinds-for-indieweb' ); ?>">
					</div>
					<div class="form-group">
						<label for="mood-rating"><?php esc_html_e( 'Level (1-5)', 'post-kinds-for-indieweb' ); ?></label>
						<select name="mood_rating" id="mood-rating" class="widefat">
							<option value=""><?php esc_html_e( 'Select...', 'post-kinds-for-indieweb' ); ?></option>
							<option value="1">1 - <?php esc_html_e( 'Low', 'post-kinds-for-indieweb' ); ?></option>
							<option value="2">2</option>
							<option value="3">3 - <?php esc_html_e( 'Neutral', 'post-kinds-for-indieweb' ); ?></option>
							<option value="4">4</option>
							<option value="5">5 - <?php esc_html_e( 'High', 'post-kinds-for-indieweb' ); ?></option>
						</select>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="mood-content"><?php esc_html_e( 'What\'s on your mind?', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="mood-content" rows="3" class="widefat"></textarea>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render acquisition quick post form.
	 *
	 * @return void
	 */
	private function render_acquisition_form(): void {
		?>
		<div class="quick-form" data-kind="acquisition" style="display: none;">
			<h2><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'Quick Acquisition', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="acquisition">
				<div class="form-row">
					<div class="form-group full-width">
						<label for="acquisition-url"><?php esc_html_e( 'URL', 'post-kinds-for-indieweb' ); ?></label>
						<input type="url" name="acquisition_url" id="acquisition-url" class="widefat" placeholder="https://...">
						<button type="button" class="button fetch-metadata-button">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Fetch Metadata', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="acquisition-name"><?php esc_html_e( 'Item Name', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="acquisition_name" id="acquisition-name" class="widefat" required placeholder="<?php esc_attr_e( 'What did you get?', 'post-kinds-for-indieweb' ); ?>">
					</div>
					<div class="form-group">
						<label for="acquisition-price"><?php esc_html_e( 'Price', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="acquisition_price" id="acquisition-price" class="widefat" placeholder="$0.00">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="acquisition-content"><?php esc_html_e( 'Notes', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="acquisition-content" rows="2" class="widefat"></textarea>
					</div>
					<div class="form-group">
						<label><?php esc_html_e( 'Rating', 'post-kinds-for-indieweb' ); ?></label>
						<?php $this->render_rating_input( 'acquisition-rating' ); ?>
					</div>
				</div>

				<?php $this->render_form_actions(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render recipe quick post form.
	 *
	 * @return void
	 */
	private function render_recipe_form(): void {
		?>
		<div class="quick-form" data-kind="recipe" style="display: none;">
			<h2><span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Quick Recipe', 'post-kinds-for-indieweb' ); ?></h2>

			<form class="quick-post-form" data-kind="recipe">
				<p class="description">
					<?php esc_html_e( 'For full recipe features, use the block editor with WP Recipe Maker.', 'post-kinds-for-indieweb' ); ?>
				</p>

				<div class="form-row">
					<div class="form-group flex-2">
						<label for="recipe-name"><?php esc_html_e( 'Recipe Name', 'post-kinds-for-indieweb' ); ?> <span class="required">*</span></label>
						<input type="text" name="recipe_name" id="recipe-name" class="widefat" required>
					</div>
					<div class="form-group">
						<label for="recipe-yield"><?php esc_html_e( 'Yield/Servings', 'post-kinds-for-indieweb' ); ?></label>
						<input type="text" name="recipe_yield" id="recipe-yield" class="widefat" placeholder="<?php esc_attr_e( '4 servings', 'post-kinds-for-indieweb' ); ?>">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="recipe-url"><?php esc_html_e( 'Recipe Source URL', 'post-kinds-for-indieweb' ); ?></label>
						<input type="url" name="recipe_url" id="recipe-url" class="widefat" placeholder="https://...">
					</div>
				</div>

				<div class="form-row">
					<div class="form-group full-width">
						<label for="recipe-content"><?php esc_html_e( 'Brief Description', 'post-kinds-for-indieweb' ); ?></label>
						<textarea name="content" id="recipe-content" rows="3" class="widefat" placeholder="<?php esc_attr_e( 'Add ingredients and instructions in the full post editor...', 'post-kinds-for-indieweb' ); ?>"></textarea>
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
			<button type="button" class="button button-small clear-rating"><?php esc_html_e( 'Clear', 'post-kinds-for-indieweb' ); ?></button>
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
						<option value="publish"><?php esc_html_e( 'Publish', 'post-kinds-for-indieweb' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'post-kinds-for-indieweb' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'post-kinds-for-indieweb' ); ?></option>
					</select>
				</label>
			</div>
			<div class="submit-actions">
				<button type="button" class="button clear-form-button">
					<?php esc_html_e( 'Clear', 'post-kinds-for-indieweb' ); ?>
				</button>
				<button type="submit" class="button button-primary submit-quick-post">
					<span class="dashicons dashicons-edit"></span>
					<?php esc_html_e( 'Create Post', 'post-kinds-for-indieweb' ); ?>
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
		$recent = get_posts(
			[
				'numberposts' => 10,
				'post_type'   => 'post',
				'tax_query'   => [
					[
						'taxonomy' => 'kind',
						'operator' => 'EXISTS',
					],
				],
			]
		);

		if ( empty( $recent ) ) {
			echo '<p class="description">' . esc_html__( 'No reaction posts yet.', 'post-kinds-for-indieweb' ) . '</p>';
			return;
		}

		?>
		<ul class="recent-post-kinds-list">
			<?php foreach ( $recent as $post ) : ?>
				<?php
				$kinds = wp_get_object_terms( $post->ID, 'kind', [ 'fields' => 'slugs' ] );
				$kind  = ! empty( $kinds ) ? $kinds[0] : 'post';
				$icon  = $this->admin->get_post_kinds()[ $kind ]['icon'] ?? 'dashicons-admin-post';
				?>
				<li>
					<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
					<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
						<?php echo esc_html( $post->post_title ); ?>
					</a>
					<span class="post-date">
						<?php echo esc_html( human_time_diff( get_post_time( 'U', false, $post ), time() ) . ' ' . __( 'ago', 'post-kinds-for-indieweb' ) ); ?>
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
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$kind = isset( $_POST['kind'] ) ? sanitize_text_field( wp_unslash( $_POST['kind'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = isset( $_POST['data'] ) ? $this->sanitize_post_data( wp_unslash( $_POST['data'] ) ) : [];

		if ( empty( $kind ) ) {
			wp_send_json_error( [ 'message' => __( 'Post kind is required.', 'post-kinds-for-indieweb' ) ] );
		}

		$result = $this->create_reaction_post( $kind, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success(
			[
				'post_id'  => $result,
				'edit_url' => get_edit_post_link( $result, 'raw' ),
				'view_url' => get_permalink( $result ),
				'message'  => __( 'Post created successfully!', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Create a reaction post.
	 *
	 * @param string               $kind Post kind.
	 * @param array<string, mixed> $data Post data.
	 * @return int|\WP_Error Post ID or error.
	 */
	private function create_reaction_post( string $kind, array $data ) {
		$settings    = get_option( 'post_kinds_indieweb_settings', [] );
		$post_status = $data['post_status'] ?? ( $settings['default_post_status'] ?? 'publish' );

		// Build title based on kind.
		$title = $this->build_post_title( $kind, $data );

		// Build content.
		$content = $this->build_post_content( $kind, $data );

		$post_data = [
			'post_type'    => 'post',
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_content' => $content,
		];

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
				update_post_meta( $post_id, "_postkind_indieweb_{$field}", $data[ $field ] );
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

			case 'play':
				$title = $data['play_title'] ?? 'Unknown Game';
				if ( ! empty( $data['play_platform'] ) ) {
					$title .= ' (' . $data['play_platform'] . ')';
				}
				return $title;

			case 'checkin':
				return $data['venue_name'] ?? 'Checkin';

			case 'eat':
				$title = $data['eat_name'] ?? 'Meal';
				if ( ! empty( $data['eat_restaurant'] ) ) {
					$title .= ' at ' . $data['eat_restaurant'];
				}
				return $title;

			case 'drink':
				$title = $data['drink_name'] ?? 'Drink';
				if ( ! empty( $data['drink_brewery'] ) ) {
					$title .= ' by ' . $data['drink_brewery'];
				}
				return $title;

			case 'bookmark':
				return $data['cite_name'] ?? 'Bookmark';

			case 'like':
				return $data['cite_name'] ?? 'Like';

			case 'favorite':
				return $data['favorite_name'] ?? 'Favorite';

			case 'reply':
				return 'Re: ' . ( $data['cite_name'] ?? 'Reply' );

			case 'rsvp':
				return 'RSVP: ' . ( $data['event_name'] ?? 'Event' );

			case 'jam':
				$title = $data['jam_track'] ?? 'My Jam';
				if ( ! empty( $data['jam_artist'] ) ) {
					$title .= ' by ' . $data['jam_artist'];
				}
				return $title;

			case 'wish':
				return $data['wish_name'] ?? 'Wish';

			case 'mood':
				$title = $data['mood_label'] ?? 'Mood';
				if ( ! empty( $data['mood_emoji'] ) ) {
					$title = $data['mood_emoji'] . ' ' . $title;
				}
				return $title;

			case 'acquisition':
				return $data['acquisition_name'] ?? 'Acquisition';

			case 'recipe':
				return $data['recipe_name'] ?? 'Recipe';

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
		$content      = '';
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
		$fields = [
			'listen'      => [ 'track_title', 'artist_name', 'album_title', 'musicbrainz_id', 'cover_image', 'rating' ],
			'watch'       => [ 'media_title', 'media_type', 'release_year', 'tmdb_id', 'imdb_id', 'poster_image', 'rating', 'rewatch', 'season_number', 'episode_number' ],
			'read'        => [ 'book_title', 'author_name', 'isbn', 'openlibrary_id', 'cover_image', 'rating', 'read_status', 'progress_percent' ],
			'play'        => [ 'play_title', 'play_platform', 'play_status', 'play_hours', 'play_cover', 'play_bgg_id', 'play_rawg_id', 'rating' ],
			'checkin'     => [ 'venue_name', 'venue_address', 'venue_city', 'venue_country', 'latitude', 'longitude', 'foursquare_id' ],
			'eat'         => [ 'eat_name', 'eat_type', 'eat_restaurant', 'eat_photo', 'rating' ],
			'drink'       => [ 'drink_name', 'drink_type', 'drink_brewery', 'drink_photo', 'rating' ],
			'bookmark'    => [ 'bookmark_of', 'cite_name', 'cite_author', 'cite_summary' ],
			'like'        => [ 'like_of', 'cite_name', 'cite_author' ],
			'favorite'    => [ 'favorite_name', 'favorite_url', 'rating' ],
			'reply'       => [ 'in_reply_to', 'cite_name', 'cite_author' ],
			'rsvp'        => [ 'event_url', 'event_name', 'rsvp_value', 'event_start', 'event_location' ],
			'jam'         => [ 'jam_track', 'jam_artist', 'jam_album', 'jam_url', 'jam_cover' ],
			'wish'        => [ 'wish_name', 'wish_url', 'wish_type', 'wish_priority' ],
			'mood'        => [ 'mood_emoji', 'mood_label', 'mood_rating' ],
			'acquisition' => [ 'acquisition_name', 'acquisition_url', 'acquisition_price', 'acquisition_photo', 'rating' ],
			'recipe'      => [ 'recipe_name', 'recipe_yield', 'recipe_url', 'recipe_duration' ],
		];

		return $fields[ $kind ] ?? [];
	}

	/**
	 * Sanitize post data.
	 *
	 * @param mixed $data Raw data.
	 * @return array<string, mixed> Sanitized data.
	 */
	private function sanitize_post_data( $data ): array {
		if ( ! is_array( $data ) ) {
			return [];
		}

		$sanitized = [];

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );

			// URL fields.
			$url_fields = [
				'bookmark_of',
				'like_of',
				'in_reply_to',
				'event_url',
				'venue_url',
				'listen_url',
				'watch_url',
				'favorite_url',
				'jam_url',
				'wish_url',
				'acquisition_url',
				'recipe_url',
				'eat_photo',
				'drink_photo',
				'acquisition_photo',
				'play_cover',
				'jam_cover',
			];
			if ( in_array( $key, $url_fields, true ) ) {
				$sanitized[ $key ] = esc_url_raw( $value );
			} elseif ( 'content' === $key || 'cite_summary' === $key ) {
				$sanitized[ $key ] = sanitize_textarea_field( $value );
			} elseif ( in_array( $key, [ 'rating', 'progress_percent', 'release_year', 'season_number', 'episode_number', 'mood_rating' ], true ) ) {
				$sanitized[ $key ] = absint( $value );
			} elseif ( 'play_hours' === $key ) {
				$sanitized[ $key ] = floatval( $value );
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
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$type  = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

		if ( empty( $type ) || empty( $query ) ) {
			wp_send_json_error( [ 'message' => __( 'Type and query are required.', 'post-kinds-for-indieweb' ) ] );
		}

		// Use the admin lookup method.
		$results = $this->admin->ajax_lookup_media();
	}
}
