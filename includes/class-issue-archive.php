<?php
/**
 * Issue facts, repository archive lanes, singular facts and editor fields.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the public issue archive contract.
 *
 * @since 1.9.0
 */
final class Issue_Archive {

	/** Kind slug. */
	public const KIND = 'issue';

	/** Group source ID. */
	public const SOURCE = 'issue_repo';

	/** Stored issue status meta key. */
	public const STATUS_META = '_pkiw_issue_status';

	/** Stored issue number meta key. */
	public const NUMBER_META = '_pkiw_issue_number';

	/** Editor panel script handle. */
	public const SCRIPT_HANDLE = 'pkiw-issue-fields-editor';

	/**
	 * Status order inside each repository.
	 *
	 * @var string[]
	 */
	public const STATUSES = [ 'open', 'closed' ];

	/**
	 * Register issue facts, grouping, content and editor behavior.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		Grouped_Archive::register_source( self::source(), self::KIND );
		Grouped_Archive::register_section_body( self::SOURCE, [ self::class, 'lanes' ] );

		add_filter( 'pkiw_archive_sections_groups', [ self::class, 'sections_groups' ] );
		add_filter( 'pkiw_stream_card_kind_cite', [ self::class, 'cite_map' ] );
		add_filter( 'the_content', [ self::class, 'decorate_content' ], 99 );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue_editor_panel' ] );
	}

	/**
	 * Human-readable issue status.
	 *
	 * @since 1.9.0
	 *
	 * @param string $status Stored status.
	 * @return string Label, or ''.
	 */
	public static function status_label( string $status ): string {
		return match ( $status ) {
			'open'   => __( 'Open', 'post-kinds-for-indieweb-in-block-themes' ),
			'closed' => __( 'Closed', 'post-kinds-for-indieweb-in-block-themes' ),
			default  => '',
		};
	}

	/**
	 * Public facts shared by the single and Stream card.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Issue facts, or [] when unavailable.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$titled = '' !== trim( (string) $post->post_title );
		$name   = $titled ? visitor_post_title( $post ) : untitled_name( $post, false );

		$repo_url_raw  = get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'cite_url', true );
		$repo_url      = is_string( $repo_url_raw ) ? web_url( $repo_url_raw ) : '';
		$repo_name_raw = get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'cite_name', true );
		$repo_name     = is_string( $repo_name_raw ) ? trim( wp_strip_all_tags( $repo_name_raw ) ) : '';

		$number = self::number( get_metadata_raw( 'post', $post_id, self::NUMBER_META, true ) );
		$status = self::status( get_metadata_raw( 'post', $post_id, self::STATUS_META, true ) );
		$date   = card_calendar_date( (string) $post->post_date );

		return [
			'name'          => $name,
			'name_is_title' => $titled && ! post_title_is_hidden( $post ),
			'kind_label'    => get_kind_label( __( 'Issue', 'post-kinds-for-indieweb-in-block-themes' ), self::KIND, 'issue' ),
			'repo_url'      => $repo_url,
			'repo_name'     => $repo_name,
			'repo_label'    => '' !== $repo_name ? $repo_name : $repo_url,
			'number'        => $number,
			'number_text'   => '' !== $number ? '#' . $number : '',
			'status'        => $status,
			'status_label'  => self::status_label( $status ),
			'date_iso'      => (string) get_the_date( 'c', $post ),
			'date_display'  => $date[1],
			'has_facts'     => '' !== ( '' !== $repo_name ? $repo_name : $repo_url ) || '' !== $number || '' !== $status,
		];
	}

	/**
	 * Repository group source with workflow order inside each rack.
	 *
	 * @since 1.9.0
	 *
	 * @return Meta_Source
	 */
	public static function source(): Meta_Source {
		return new Meta_Source(
			self::SOURCE,
			[ Meta_Fields::PREFIX . 'cite_url' ],
			[],
			Label_Mode::map( static fn(): array => self::repo_labels(), 'verbatim' ),
			'',
			true,
			[],
			[
				'key'   => self::STATUS_META,
				'order' => self::STATUSES,
			]
		);
	}

	/**
	 * Repository labels found on the current grouped page.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, string> Lowercase URL to stored repository name.
	 */
	public static function repo_labels(): array {
		$labels = [];
		foreach ( Grouped_Archive::current_page_ids() as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || '' !== (string) $post->post_password || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
				continue;
			}

			$url_raw  = get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'cite_url', true );
			$name_raw = get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'cite_name', true );
			$url      = is_string( $url_raw ) ? mb_strtolower( trim( $url_raw ) ) : '';
			$name     = is_string( $name_raw ) ? trim( wp_strip_all_tags( $name_raw ) ) : '';
			if ( '' !== $url && '' !== $name && ! isset( $labels[ $url ] ) ) {
				$labels[ $url ] = $name;
			}
		}

		return $labels;
	}

	/**
	 * Add issue workflow labels to the archive editor data.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $groups Existing maps.
	 * @return array<string, mixed>
	 */
	public static function sections_groups( $groups ): array {
		$groups               = is_array( $groups ) ? $groups : [];
		$groups[ self::KIND ] = [
			'open'   => self::status_label( 'open' ),
			'closed' => self::status_label( 'closed' ),
		];

		return $groups;
	}

	/**
	 * Render workflow lanes inside one repository rack.
	 *
	 * @since 1.9.0
	 *
	 * @param Archive_Group        $group    Repository group. Unused.
	 * @param array<int, string>   $items    Engine list items keyed by post ID.
	 * @param array<string, mixed> $settings Normalized entry settings.
	 * @return string Lane HTML, or ''.
	 */
	public static function lanes( Archive_Group $group, array $items, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Shared callback signature.
		if ( [] === $items ) {
			return '';
		}

		$lanes = [
			'open'   => [],
			'closed' => [],
			'none'   => [],
		];
		foreach ( $items as $post_id => $item ) {
			$post   = get_post( $post_id );
			$status = $post instanceof \WP_Post && '' === (string) $post->post_password
				? self::status( get_metadata_raw( 'post', (int) $post_id, self::STATUS_META, true ) )
				: '';
			$lanes[ '' !== $status ? $status : 'none' ][ $post_id ] = $item;
		}

		$level  = max( 2, min( 4, (int) ( $settings['level'] ?? 2 ) ) ) + 1;
		$labels = is_array( $settings['group_labels'] ?? null ) ? $settings['group_labels'] : [];
		$out    = '';
		foreach ( [ 'open', 'closed', 'none' ] as $status ) {
			if ( empty( $lanes[ $status ] ) ) {
				continue;
			}
			if ( 'none' === $status ) {
				$label = trim( (string) ( $settings['label'] ?? '' ) );
				$label = '' !== $label ? $label : __( 'Other', 'post-kinds-for-indieweb-in-block-themes' );
			} else {
				$label = trim( (string) ( $labels[ $status ] ?? '' ) );
				$label = '' !== $label ? $label : self::status_label( $status );
			}

			$out .= '<div class="pkiw-issue-lane" data-pkiw-status="' . esc_attr( $status ) . '">';
			$out .= '<h' . $level . ' class="pkiw-issue-lane__heading">' . esc_html( $label ) . '</h' . $level . '>';
			$out .= '<ul class="pkiw-issue-lane__items">' . implode( '', $lanes[ $status ] ) . '</ul></div>';
		}

		return '<div class="pkiw-issue-lanes">' . $out . '</div>';
	}

	/**
	 * Add the issue Stream citation callback.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $map Existing map.
	 * @return array<string, callable>
	 */
	public static function cite_map( $map ): array {
		$map               = is_array( $map ) ? $map : [];
		$map[ self::KIND ] = [ self::class, 'cite' ];

		return $map;
	}

	/**
	 * Hidden repository citation for a generic Stream card.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $facts Issue facts.
	 * @param \WP_Post             $post  Post. Unused.
	 * @return string Hidden h-cite HTML, or ''.
	 */
	public static function cite( array $facts, \WP_Post $post ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Map callback signature.
		if ( [] === $facts || ( '' === ( $facts['repo_url'] ?? '' ) && '' === ( $facts['repo_name'] ?? '' ) ) ) {
			return '';
		}

		return hidden_cite_html(
			'u-in-reply-to',
			[
				'u-url'  => $facts['repo_url'] ?? '',
				'p-name' => $facts['repo_name'] ?? '',
			]
		);
	}

	/**
	 * Prepend issue facts to a public singular post body.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Rendered post content.
	 * @return string Decorated or unchanged content.
	 */
	public static function decorate_content( string $content ): string {
		$post = get_post();
		if (
			! is_singular( 'post' )
			|| ! $post instanceof \WP_Post
			|| get_the_ID() !== get_queried_object_id()
			|| is_feed()
			|| null !== get_query_var( 'atproto', null )
			|| '' !== (string) get_query_var( 'markdown_request', '' )
			|| 'markdown' === get_query_var( 'format', '' )
			|| ( function_exists( '\\Activitypub\\is_activitypub_request' ) && \Activitypub\is_activitypub_request() )
			|| doing_filter( 'get_excerpt' )
			|| doing_filter( 'get_the_excerpt' )
			|| doing_action( 'wp_head' )
			|| post_password_required( $post )
			|| ! has_term( self::KIND, Taxonomy::TAXONOMY, $post )
			|| self::has_class( $content, 'pkiw-issue-facts' )
		) {
			return $content;
		}

		$facts = self::facts( (int) $post->ID );
		if ( empty( $facts['has_facts'] ) ) {
			return $content;
		}

		$rows = '';
		if ( '' !== $facts['repo_label'] ) {
			if ( '' !== $facts['repo_url'] ) {
				$value = '<span class="h-cite u-in-reply-to"><a class="u-url p-name" href="' . esc_url( $facts['repo_url'] ) . '">' . esc_html( $facts['repo_label'] ) . '</a></span>';
			} else {
				$value = '<span class="pkiw-issue-facts__repo">' . esc_html( $facts['repo_label'] ) . '</span>';
			}
			$rows .= self::fact_row( __( 'Repository', 'post-kinds-for-indieweb-in-block-themes' ), $value );
		}
		if ( '' !== $facts['number_text'] ) {
			$rows .= self::fact_row( __( 'Issue', 'post-kinds-for-indieweb-in-block-themes' ), esc_html( $facts['number_text'] ) );
		}
		if ( '' !== $facts['status_label'] ) {
			$status = '<span class="pkiw-issue-status pkiw-issue-status--' . esc_attr( $facts['status'] ) . '">' . esc_html( $facts['status_label'] ) . '</span>';
			$rows  .= self::fact_row( __( 'Status', 'post-kinds-for-indieweb-in-block-themes' ), $status );
		}
		if ( '' !== $facts['date_iso'] && '' !== $facts['date_display'] ) {
			$date  = '<time class="dt-published" datetime="' . esc_attr( $facts['date_iso'] ) . '">' . esc_html( $facts['date_display'] ) . '</time>';
			$rows .= self::fact_row( __( 'Date', 'post-kinds-for-indieweb-in-block-themes' ), $date );
		}

		$body = self::has_class( $content, 'e-content' )
			? $content
			: '<div class="pkiw-issue-body e-content">' . $content . '</div>';

		return '<div class="pkiw-issue-facts"><dl class="pkiw-issue-facts__list">' . $rows . '</dl></div>' . $body;
	}

	/**
	 * Enqueue the issue document panel on post editors that support kinds.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function enqueue_editor_panel(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || empty( $screen->post_type ) || ! is_object_in_taxonomy( $screen->post_type, Taxonomy::TAXONOMY ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			\PKIW_URL . 'assets/js/issue-fields-editor.js',
			[ 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'post-kinds-for-indieweb-in-block-themes' );

		$term    = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		$term_id = $term instanceof \WP_Term ? (int) $term->term_id : 0;
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.pkiwIssueFields = ' . wp_json_encode( [ 'termId' => $term_id ] ) . ';',
			'before'
		);
	}

	/**
	 * One definition-list row with already escaped value markup.
	 *
	 * @param string $label Row label.
	 * @param string $value Escaped value markup.
	 * @return string Row HTML.
	 */
	private static function fact_row( string $label, string $value ): string {
		return '<div class="pkiw-issue-facts__row"><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
	}

	/**
	 * Whether HTML contains a class token.
	 *
	 * @param string $html       HTML fragment.
	 * @param string $class_name Class token.
	 * @return bool
	 */
	private static function has_class( string $html, string $class_name ): bool {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			if ( $processor->has_class( $class_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a stored issue number.
	 *
	 * @param mixed $value Raw metadata.
	 * @return string
	 */
	private static function number( mixed $value ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( str_starts_with( $value, '#' ) ) {
			$value = trim( substr( $value, 1 ) );
		}

		return 1 === preg_match( '/^[0-9]{1,9}$/', $value ) ? $value : '';
	}

	/**
	 * Normalize a stored issue status.
	 *
	 * @param mixed $value Raw metadata.
	 * @return string
	 */
	private static function status( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, self::STATUSES, true ) ? $value : '';
	}
}
