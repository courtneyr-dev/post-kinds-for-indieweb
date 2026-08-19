<?php
/**
 * Event Card Block - Server-side Render
 *
 * Renders an event announcement in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → date → location → summary), theme owns
 * paint via --pk-* custom properties. The card roots as an h-event with
 * p-name, dt-start, dt-end, p-location, and u-url.
 *
 * The event start date renders in a dedicated `.pk-event-date` element
 * directly under the title so a theme (e.g. courtneyr-child's gig-poster
 * crest) can make the EVENT date the big date — the post itself must never
 * be future-dated to fake this, since future-dated posts drop out of
 * queries.
 *
 * When calendarSource + calendarEventId point at an event in The Events
 * Calendar or My Calendar, that plugin's data (name, start, end, venue, url)
 * wins over the block's own attributes; when the calendar plugin is
 * inactive the attributes render as-is — never fatal.
 *
 * @package PKIW
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

use function PKIW\get_kind_icon_svg;
use function PKIW\get_kind_label;

$pkiw_event_name        = $attributes['eventName'] ?? '';
$pkiw_event_start       = $attributes['eventStart'] ?? '';
$pkiw_event_end         = $attributes['eventEnd'] ?? '';
$pkiw_event_location    = $attributes['eventLocation'] ?? '';
$pkiw_event_url         = $attributes['eventUrl'] ?? '';
$pkiw_event_description = $attributes['eventDescription'] ?? '';
$pkiw_event_image       = $attributes['eventImage'] ?? '';
$pkiw_event_image_alt   = $attributes['eventImageAlt'] ?? '';
$pkiw_rel               = $attributes['rel'] ?? '';

// Pull live event data from a calendar plugin when one is referenced.
// Calendar values win over the block's snapshot; missing values fall back
// to the attributes, and an inactive plugin changes nothing.
$pkiw_calendar_source = (string) ( $attributes['calendarSource'] ?? '' );
$pkiw_calendar_id     = (int) ( $attributes['calendarEventId'] ?? 0 );

if ( '' !== $pkiw_calendar_source && $pkiw_calendar_id > 0 && class_exists( '\\PKIW\\Integrations\\Calendar_Events' ) ) {
	$pkiw_calendar_event = \PKIW\Integrations\Calendar_Events::get_event( $pkiw_calendar_source, $pkiw_calendar_id );

	if ( is_array( $pkiw_calendar_event ) ) {
		$pkiw_event_name     = '' !== $pkiw_calendar_event['name'] ? $pkiw_calendar_event['name'] : $pkiw_event_name;
		$pkiw_event_start    = '' !== $pkiw_calendar_event['start'] ? $pkiw_calendar_event['start'] : $pkiw_event_start;
		$pkiw_event_end      = '' !== $pkiw_calendar_event['end'] ? $pkiw_calendar_event['end'] : $pkiw_event_end;
		$pkiw_event_location = '' !== $pkiw_calendar_event['location'] ? $pkiw_calendar_event['location'] : $pkiw_event_location;
		$pkiw_event_url      = '' !== $pkiw_calendar_event['url'] ? $pkiw_calendar_event['url'] : $pkiw_event_url;
	}
}

// Always include noopener noreferrer for security.
$pkiw_link_rel = $pkiw_rel ? 'noopener noreferrer ' . $pkiw_rel : 'noopener noreferrer';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-event h-event',
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

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'event' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Event', 'post-kinds-for-indieweb-in-block-themes' ), 'event', 'event-card' ) ); ?></p>

		<div class="pk-caption">
			<?php if ( $pkiw_event_name ) : ?>
				<h2 class="pk-title p-name">
					<?php if ( $pkiw_event_url ) : ?>
						<a class="u-url" href="<?php echo esc_url( $pkiw_event_url ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"><?php echo esc_html( $pkiw_event_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_event_name ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>

			<?php if ( $pkiw_event_start_iso ) : ?>
				<p class="pk-sub pk-event-date">
					<time class="dt-start" datetime="<?php echo esc_attr( $pkiw_event_start_iso ); ?>"><?php echo esc_html( $pkiw_event_range_disp ); ?></time>
					<?php if ( $pkiw_event_end_iso ) : ?>
						<data class="dt-end" value="<?php echo esc_attr( $pkiw_event_end_iso ); ?>" hidden></data>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_event_location ) : ?>
				<p class="pk-sub pk-event-location">
					<span class="p-location"><?php echo esc_html( $pkiw_event_location ); ?></span>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $pkiw_event_description ) : ?>
			<p class="p-summary"><?php echo esc_html( $pkiw_event_description ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_event_image ) : ?>
			<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_event_image ); ?>" alt="<?php echo esc_attr( $pkiw_event_image_alt ? $pkiw_event_image_alt : sprintf( /* translators: %s: event name */ __( '%s event', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_event_name ) ); ?>" loading="lazy" /></div>
		<?php endif; ?>
	</div>

	<?php if ( $pkiw_event_url && ! $pkiw_event_name ) : ?>
		<data class="u-url" value="<?php echo esc_attr( $pkiw_event_url ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
