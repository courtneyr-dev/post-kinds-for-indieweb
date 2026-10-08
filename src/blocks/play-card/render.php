<?php
/**
 * Play Card Block - Server-side Render
 *
 * Renders the play card in the two-layer pk-card system: plugin owns
 * structure, theme owns paint via --pk-* custom properties. The card root
 * is the entry's `u-play-of` h-cite.
 *
 * A card that play_group_of_attrs() files as a board game prints the
 * tabletop, marked `pk-card--tabletop` and `data-pkiw-play-group="board"`:
 * the title, a box (figure.pk-box), a facts list (dl.pk-facts), a links
 * row (div.pk-links) and a score pad (section.pk-scorepad), in that DOM
 * order. Every other card prints the shared structure: badge → label →
 * title → sub → rating → media → note → meta.
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

$pkiw_title        = (string) ( $attributes['title'] ?? '' );
$pkiw_platform     = (string) ( $attributes['platform'] ?? '' );
$pkiw_cover        = (string) ( $attributes['cover'] ?? '' );
$pkiw_cover_alt    = (string) ( $attributes['coverAlt'] ?? '' );
$pkiw_status       = (string) ( $attributes['status'] ?? '' );
$pkiw_hours_played = isset( $attributes['hoursPlayed'] ) ? (float) $attributes['hoursPlayed'] : 0.0;
$pkiw_rating       = (float) ( $attributes['rating'] ?? 0 );
$pkiw_played_at    = (string) ( $attributes['playedAt'] ?? '' );
$pkiw_review       = (string) ( $attributes['review'] ?? '' );
$pkiw_game_url     = \PKIW\web_url( (string) ( $attributes['gameUrl'] ?? '' ) );
$pkiw_bgg_id       = (string) ( $attributes['bggId'] ?? '' );
$pkiw_rawg_id      = (string) ( $attributes['rawgId'] ?? '' );
$pkiw_steam_id     = (string) ( $attributes['steamId'] ?? '' );
$pkiw_official_url = (string) ( $attributes['officialUrl'] ?? '' );
$pkiw_purchase_url = (string) ( $attributes['purchaseUrl'] ?? '' );

// The same labels the play facts give a theme, so card and theme print one text.
$pkiw_status_label   = '' !== $pkiw_status ? ( \PKIW\play_status_labels()[ $pkiw_status ] ?? $pkiw_status ) : '';
$pkiw_hours_label    = \PKIW\play_hours_label( $pkiw_hours_played );
$pkiw_game_url_label = \PKIW\play_game_url_label( $pkiw_game_url );
$pkiw_tabletop       = 'board' === \PKIW\play_group_of_attrs( $attributes );

[ $pkiw_played_iso, $pkiw_played_display ] = \PKIW\card_calendar_date( $pkiw_played_at );

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	$pkiw_tabletop
		? [
			'class'                => 'pk-card k-play h-cite u-play-of pk-card--tabletop',
			'data-pkiw-play-group' => 'board',
		]
		: [ 'class' => 'pk-card k-play h-cite u-play-of' ]
);

$pkiw_uids_html = '';
if ( $pkiw_bgg_id ) {
	$pkiw_uids_html .= '<data class="u-uid" value="' . esc_attr( 'https://boardgamegeek.com/boardgame/' . $pkiw_bgg_id ) . '" hidden></data>';
}
if ( $pkiw_rawg_id ) {
	$pkiw_uids_html .= '<data class="u-uid" value="' . esc_attr( 'https://rawg.io/games/' . $pkiw_rawg_id ) . '" hidden></data>';
}
if ( $pkiw_steam_id ) {
	$pkiw_uids_html .= '<data class="u-uid" value="' . esc_attr( 'https://store.steampowered.com/app/' . $pkiw_steam_id ) . '" hidden></data>';
}

if ( $pkiw_tabletop ) {
	$pkiw_post = get_post();
	$pkiw_post = $pkiw_post instanceof WP_Post ? $pkiw_post : null;

	// The template prints the post title as the page's H1 on the post's own
	// single, and the editor previews this card for the post it sits in.
	// There a card title that repeats the post title stays as hidden data.
	$pkiw_rest_route  = isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof WP ? (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ) : '';
	$pkiw_own_view    = null !== $pkiw_post && (
		'/wp/v2/block-renderer/post-kinds-indieweb/play-card' === untrailingslashit( $pkiw_rest_route )
		|| ( is_singular() && get_queried_object_id() === $pkiw_post->ID )
	);
	$pkiw_plain       = static fn( string $text ): string => trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$pkiw_title_plain = $pkiw_plain( $pkiw_title );
	$pkiw_title_html  = '';
	if ( '' !== $pkiw_title_plain ) {
		$pkiw_title_html = $pkiw_own_view && $pkiw_title_plain === $pkiw_plain( $pkiw_post->post_title )
			? '<data class="p-name" value="' . esc_attr( $pkiw_title ) . '" hidden></data>'
			: '<h2 class="pk-title p-name">' . esc_html( $pkiw_title ) . '</h2>';
	}

	// The box: the post's picture, featured image first, else the card's cover.
	$pkiw_name      = '' !== $pkiw_title_plain ? $pkiw_title_plain : ( null !== $pkiw_post ? $pkiw_plain( get_the_title( $pkiw_post ) ) : '' );
	$pkiw_box_alt   = '' !== trim( $pkiw_cover_alt )
		? $pkiw_cover_alt
		: ( '' !== $pkiw_name ? sprintf( /* translators: %s: game title */ __( 'Box art for %s', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_name ) : '' );
	$pkiw_play_post = null !== $pkiw_post && 'play' === \PKIW\Kind_Facts::kind_of( $pkiw_post );
	$pkiw_picture   = $pkiw_play_post ? \PKIW\kind_picture( $pkiw_post->ID ) : null;
	$pkiw_box_html  = '';
	if ( null !== $pkiw_picture && '' !== $pkiw_picture['url'] ) {
		if ( $pkiw_picture['attachment_id'] > 0 ) {
			$pkiw_box_html = wp_get_attachment_image(
				$pkiw_picture['attachment_id'],
				'full',
				false,
				[
					'class' => 'pk-box__image u-photo',
					'alt'   => $pkiw_box_alt,
				]
			);
		}
		if ( '' === $pkiw_box_html ) {
			$pkiw_box_html = '<img class="pk-box__image u-photo" src="' . esc_url( $pkiw_picture['url'] ) . '" alt="' . esc_attr( $pkiw_box_alt ) . '" loading="lazy" />';
		}
	} elseif ( '' !== $pkiw_cover && ( ! $pkiw_play_post || \PKIW\Kind_Facts::can_show( $pkiw_post ) ) ) {
		// kind_picture() found nothing for a post a visitor may see, or there
		// is no play post to ask, as in a pattern.
		$pkiw_box_html = '<img class="pk-box__image u-photo" src="' . esc_url( $pkiw_cover ) . '" alt="' . esc_attr( $pkiw_box_alt ) . '" loading="lazy" />';
	}

	$pkiw_facts = [];
	if ( '' !== trim( $pkiw_platform ) ) {
		$pkiw_facts[] = [ 'pk-play-platform', __( 'Platform', 'post-kinds-for-indieweb-in-block-themes' ), esc_html( $pkiw_platform ) ];
	}
	if ( '' !== $pkiw_status_label ) {
		$pkiw_facts[] = [ 'pk-play-status', __( 'Status', 'post-kinds-for-indieweb-in-block-themes' ), esc_html( $pkiw_status_label ) ];
	}
	if ( '' !== $pkiw_played_iso ) {
		$pkiw_facts[] = [ 'pk-play-played', __( 'Played', 'post-kinds-for-indieweb-in-block-themes' ), '<time class="dt-published" datetime="' . esc_attr( $pkiw_played_iso ) . '">' . esc_html( $pkiw_played_display ) . '</time>' ];
	}
	if ( '' !== $pkiw_hours_label ) {
		$pkiw_facts[] = [ 'pk-play-hours', __( 'Hours', 'post-kinds-for-indieweb-in-block-themes' ), esc_html( $pkiw_hours_label ) ];
	}

	$pkiw_links = [];
	if ( '' !== $pkiw_game_url ) {
		$pkiw_links[] = [ 'pk-link u-url', $pkiw_game_url, $pkiw_game_url_label ];
	}
	if ( $pkiw_official_url ) {
		$pkiw_links[] = [ 'pk-link', $pkiw_official_url, __( 'Official Site', 'post-kinds-for-indieweb-in-block-themes' ) ];
	}
	if ( $pkiw_purchase_url ) {
		$pkiw_links[] = [ 'pk-link pk-link--buy', $pkiw_purchase_url, __( 'Buy', 'post-kinds-for-indieweb-in-block-themes' ) ];
	}

	$pkiw_rating_label = \PKIW\card_rating_label( $pkiw_rating );
	$pkiw_has_review   = '' !== trim( $pkiw_review );

	ob_start();
	?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $pkiw_title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
	<?php if ( '' !== $pkiw_box_html ) : ?>
		<figure class="pk-box"><?php echo $pkiw_box_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() output, or an <img> escaped above. ?></figure>
	<?php endif; ?>
	<?php if ( $pkiw_facts ) : ?>
		<dl class="pk-facts">
			<?php foreach ( $pkiw_facts as [ $pkiw_fact_class, $pkiw_fact_term, $pkiw_fact_html ] ) : ?>
				<div class="pk-fact <?php echo esc_attr( $pkiw_fact_class ); ?>"><dt><?php echo esc_html( $pkiw_fact_term ); ?></dt><dd><?php echo $pkiw_fact_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></dd></div>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
	<?php if ( $pkiw_links ) : ?>
		<div class="pk-links">
			<?php foreach ( $pkiw_links as [ $pkiw_link_class, $pkiw_link_url, $pkiw_link_label ] ) : ?>
				<a class="<?php echo esc_attr( $pkiw_link_class ); ?>" href="<?php echo esc_url( $pkiw_link_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_link_label ); ?><?php echo \PKIW\pkiw_new_tab_hint(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<?php if ( '' !== $pkiw_rating_label || $pkiw_has_review ) : ?>
		<section class="pk-scorepad">
			<?php if ( '' !== $pkiw_rating_label ) : ?>
				<p class="pk-scorepad__rating"><?php echo esc_html( $pkiw_rating_label ); ?></p>
				<?php echo \PKIW\card_rating_html( $pkiw_rating, 5, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<?php if ( $pkiw_has_review ) : ?>
				<h2 class="pk-scorepad__heading"><?php esc_html_e( 'Review', 'post-kinds-for-indieweb-in-block-themes' ); ?></h2>
				<div class="pk-scorepad__review p-content"><?php echo wp_kses_post( $pkiw_review ); ?></div>
			<?php endif; ?>
		</section>
	<?php endif; ?>
	<?php echo $pkiw_uids_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
</article>
	<?php
	echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Play', 'post-kinds-for-indieweb-in-block-themes' ), 'play', 'play-card' ) ); ?></span>

		<div class="pk-caption">
			<?php if ( $pkiw_title ) : ?>
				<h2 class="pk-title p-name">
					<?php if ( $pkiw_game_url ) : ?>
						<a class="u-url" href="<?php echo esc_url( $pkiw_game_url ); ?>" target="_blank" rel="noopener noreferrer"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_title ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>

			<?php if ( $pkiw_status_label || $pkiw_platform || '' !== $pkiw_hours_label ) : ?>
				<p class="pk-sub">
					<?php if ( $pkiw_status_label ) : ?>
						<span class="pk-play-status"><?php echo esc_html( $pkiw_status_label ); ?></span>
					<?php endif; ?>
					<?php
					if ( $pkiw_status_label && $pkiw_platform ) :
						?>
						&mdash; <?php endif; ?>
					<?php if ( $pkiw_platform ) : ?>
						<span class="pk-play-platform"><?php echo esc_html( $pkiw_platform ); ?></span>
					<?php endif; ?>
					<?php
					if ( ( $pkiw_status_label || $pkiw_platform ) && '' !== $pkiw_hours_label ) :
						?>
						&bull; <?php endif; ?>
					<?php if ( '' !== $pkiw_hours_label ) : ?>
						<span class="pk-play-hours"><?php echo esc_html( $pkiw_hours_label ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<?php echo \PKIW\card_rating_html( $pkiw_rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ( $pkiw_cover ) : ?>
			<div class="pk-media">
				<img class="pk-thumb--poster u-photo" src="<?php echo esc_url( $pkiw_cover ); ?>" alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : sprintf( /* translators: %s: game title */ __( 'Box art for %s', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_title ? $pkiw_title : get_the_title() ) ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_review ) : ?>
			<div class="pk-note p-content"><?php echo wp_kses_post( $pkiw_review ); ?></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_game_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_game_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_game_url_label ); ?><?php echo \PKIW\pkiw_new_tab_hint(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php endif; ?>
			<?php
			if ( $pkiw_game_url && $pkiw_official_url ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_official_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_official_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official Site', 'post-kinds-for-indieweb-in-block-themes' ); ?><?php echo \PKIW\pkiw_new_tab_hint(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php endif; ?>
			<?php
			if ( ( $pkiw_game_url || $pkiw_official_url ) && $pkiw_purchase_url ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_purchase_url ) : ?>
				<a class="pk-link pk-link--buy" href="<?php echo esc_url( $pkiw_purchase_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Buy', 'post-kinds-for-indieweb-in-block-themes' ); ?><?php echo \PKIW\pkiw_new_tab_hint(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php endif; ?>
			<?php
			if ( ( $pkiw_game_url || $pkiw_official_url || $pkiw_purchase_url ) && $pkiw_played_iso ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_played_iso ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_played_iso ); ?>"><?php echo esc_html( $pkiw_played_display ); ?></time>
			<?php endif; ?>
		</div>
	</div>

	<?php echo $pkiw_uids_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
