<?php
/**
 * RSVP Card Block - Server-side Render
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

$pkiw_event_name        = $attributes['eventName'] ?? '';
$pkiw_event_url         = $attributes['eventUrl'] ?? '';
$pkiw_event_start       = $attributes['eventStart'] ?? '';
$pkiw_event_end         = $attributes['eventEnd'] ?? '';
$pkiw_event_location    = $attributes['eventLocation'] ?? '';
$pkiw_event_description = $attributes['eventDescription'] ?? '';
$pkiw_rsvp_status       = $attributes['rsvpStatus'] ?? 'yes';
$pkiw_rsvp_note         = $attributes['rsvpNote'] ?? '';
$pkiw_rsvp_at           = $attributes['rsvpAt'] ?? '';
$pkiw_event_image       = $attributes['eventImage'] ?? '';
$pkiw_event_image_alt   = $attributes['eventImageAlt'] ?? '';
$pkiw_rel               = $attributes['rel'] ?? '';
$pkiw_layout            = $attributes['layout'] ?? 'horizontal';

$pkiw_status_icons  = [
	'yes'        => '✅',
	'no'         => '❌',
	'maybe'      => '🤔',
	'interested' => '👀',
	'remote'     => '💻',
];
$pkiw_status_labels = [
	'yes'        => __( 'Going', 'post-kinds-for-indieweb' ),
	'no'         => __( 'Not Going', 'post-kinds-for-indieweb' ),
	'maybe'      => __( 'Maybe', 'post-kinds-for-indieweb' ),
	'interested' => __( 'Interested', 'post-kinds-for-indieweb' ),
	'remote'     => __( 'Attending Remotely', 'post-kinds-for-indieweb' ),
];
$pkiw_icon          = $pkiw_status_icons[ $pkiw_rsvp_status ] ?? $pkiw_status_icons['yes'];
$pkiw_label         = $pkiw_status_labels[ $pkiw_rsvp_status ] ?? $pkiw_status_labels['yes'];

// Always include noopener noreferrer for security.
$pkiw_link_rel = $pkiw_rel ? 'noopener noreferrer ' . $pkiw_rel : 'noopener noreferrer';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => sprintf( 'rsvp-card layout-%s rsvp-%s', esc_attr( $pkiw_layout ), esc_attr( $pkiw_rsvp_status ) ),
	]
);

// Format event date range.
$pkiw_event_start_iso  = '';
$pkiw_event_end_iso    = '';
$pkiw_event_range_disp = '';
if ( $pkiw_event_start ) {
	$pkiw_start_ts = strtotime( $pkiw_event_start );
	if ( $pkiw_start_ts ) {
		$pkiw_event_start_iso = gmdate( 'c', $pkiw_start_ts );
		$pkiw_date_fmt        = get_option( 'date_format' );
		$pkiw_time_fmt        = get_option( 'time_format' );
		$pkiw_start_disp      = wp_date( $pkiw_date_fmt . ' ' . $pkiw_time_fmt, $pkiw_start_ts );
		$pkiw_end_ts          = $pkiw_event_end ? strtotime( $pkiw_event_end ) : 0;

		if ( $pkiw_end_ts ) {
			$pkiw_event_end_iso = gmdate( 'c', $pkiw_end_ts );
			if ( gmdate( 'Y-m-d', $pkiw_start_ts ) === gmdate( 'Y-m-d', $pkiw_end_ts ) ) {
				$pkiw_end_disp         = wp_date( $pkiw_time_fmt, $pkiw_end_ts );
				$pkiw_event_range_disp = $pkiw_start_disp . ' – ' . $pkiw_end_disp;
			} else {
				$pkiw_end_disp         = wp_date( $pkiw_date_fmt . ' ' . $pkiw_time_fmt, $pkiw_end_ts );
				$pkiw_event_range_disp = $pkiw_start_disp . ' – ' . $pkiw_end_disp;
			}
		} else {
			$pkiw_event_range_disp = $pkiw_start_disp;
		}
	}
}

// Format RSVP timestamp.
$pkiw_rsvp_iso     = '';
$pkiw_rsvp_display = '';
if ( $pkiw_rsvp_at ) {
	$pkiw_ts = strtotime( $pkiw_rsvp_at );
	if ( $pkiw_ts ) {
		$pkiw_rsvp_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_rsvp_display = wp_date( get_option( 'date_format' ), $pkiw_ts );
	}
}

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-entry">
		<?php if ( $pkiw_event_image ) : ?>
			<div class="post-kinds-card__media">
				<img
					src="<?php echo esc_url( $pkiw_event_image ); ?>"
					alt="<?php echo esc_attr( $pkiw_event_image_alt ? $pkiw_event_image_alt : sprintf( /* translators: %s: event name */ __( '%s event', 'post-kinds-for-indieweb' ), $pkiw_event_name ) ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<span class="post-kinds-card__badge post-kinds-card__badge--<?php echo esc_attr( $pkiw_rsvp_status ); ?>">
				<span class="post-kinds-card__badge-icon" aria-hidden="true"><?php echo esc_html( $pkiw_icon ); ?></span>
				<data class="p-rsvp" value="<?php echo esc_attr( $pkiw_rsvp_status ); ?>"><?php echo esc_html( $pkiw_label ); ?></data>
			</span>

			<div class="post-kinds-card__event p-in-reply-to h-event">
				<?php if ( $pkiw_event_name ) : ?>
					<h3 class="post-kinds-card__title">
						<?php if ( $pkiw_event_url ) : ?>
							<a href="<?php echo esc_url( $pkiw_event_url ); ?>" class="p-name u-url" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"><?php echo esc_html( $pkiw_event_name ); ?></a>
						<?php else : ?>
							<span class="p-name"><?php echo esc_html( $pkiw_event_name ); ?></span>
						<?php endif; ?>
					</h3>
				<?php endif; ?>

				<?php if ( $pkiw_event_start_iso ) : ?>
					<div class="post-kinds-card__meta-row">
						<span class="post-kinds-card__meta-icon" aria-hidden="true">📅</span>
						<time class="dt-start" datetime="<?php echo esc_attr( $pkiw_event_start_iso ); ?>"><?php echo esc_html( $pkiw_event_range_disp ); ?></time>
						<?php if ( $pkiw_event_end_iso ) : ?>
							<data class="dt-end" value="<?php echo esc_attr( $pkiw_event_end_iso ); ?>" hidden></data>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $pkiw_event_location ) : ?>
					<div class="post-kinds-card__meta-row">
						<span class="post-kinds-card__meta-icon" aria-hidden="true">📍</span>
						<span class="p-location"><?php echo esc_html( $pkiw_event_location ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $pkiw_event_description ) : ?>
					<p class="post-kinds-card__meta p-summary"><?php echo esc_html( $pkiw_event_description ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $pkiw_rsvp_note ) : ?>
				<div class="post-kinds-card__notes p-content">
					<p><?php echo wp_kses_post( $pkiw_rsvp_note ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_rsvp_iso ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( $pkiw_rsvp_iso ); ?>">
					<?php
					printf(
						/* translators: %s: date the RSVP was made */
						esc_html__( 'RSVPed %s', 'post-kinds-for-indieweb' ),
						esc_html( $pkiw_rsvp_display )
					);
					?>
				</time>
			<?php endif; ?>
		</div>

		<?php if ( $pkiw_event_url ) : ?>
			<data class="u-in-reply-to" value="<?php echo esc_attr( $pkiw_event_url ); ?>" hidden></data>
		<?php endif; ?>
	</div>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
