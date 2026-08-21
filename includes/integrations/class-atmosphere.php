<?php
/**
 * ATmosphere integration bootstrap.
 *
 * ATmosphere (wordpress.org/plugins/atmosphere) owns everything AT
 * Protocol: OAuth, DID/PDS resolution, record writes, blob uploads,
 * `/.well-known/site.standard.publication`, the verification link tags,
 * lifecycle synchronization, retries, and backfill. This module adds only
 * what ATmosphere cannot know — Post Kind eligibility policy, derived
 * titles, kind tags, and status surfaces — through ATmosphere's public
 * hooks. It never writes to the PDS, never stores connection state, and
 * never duplicates ATmosphere's settings.
 *
 * Degrades safely: when ATmosphere is missing or older than
 * MIN_VERSION, nothing registers beyond a capability-gated admin notice,
 * and every other Post Kinds feature is unaffected.
 *
 * @package PKIW
 * @since   1.6.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Feature_Flags;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ATmosphere integration coordinator.
 *
 * @since 1.6.0
 */
class Atmosphere {

	/**
	 * Lowest ATmosphere version whose public APIs this integration uses.
	 *
	 * 2.1.0 carries every hook the integration registers
	 * (atmosphere_transform_document since 1.0, the document metadata
	 * filters since 2.0.0, the atproto preview, per-post metas, Publisher
	 * and backfill). Document-only publishing
	 * (atmosphere_should_publish_bluesky_post) ships after 2.1.0 and is
	 * documented, not depended on.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const MIN_VERSION = '2.1.0';

	/**
	 * Whether hooks are registered (duplicate-registration guard).
	 *
	 * @since 1.6.0
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Eligibility policy, when wired.
	 *
	 * @since 1.6.0
	 *
	 * @var Atmosphere_Eligibility|null
	 */
	private ?Atmosphere_Eligibility $eligibility = null;

	/**
	 * Document enrichment, when wired.
	 *
	 * @since 1.6.0
	 *
	 * @var Atmosphere_Document_Map|null
	 */
	private ?Atmosphere_Document_Map $document_map = null;

	/**
	 * Register the integration when the dependency is available.
	 *
	 * Safe in every request context: registers filters and admin hooks
	 * only, performs no queries and no writes at registration time.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered || ! Feature_Flags::is_enabled( 'atmosphere_integration' ) ) {
			return;
		}

		$this->registered = true;

		if ( ! self::is_available() ) {
			add_action( 'admin_notices', [ $this, 'dependency_notice' ] );
			return;
		}

		$this->eligibility = new Atmosphere_Eligibility();
		$this->eligibility->register();

		$this->document_map = new Atmosphere_Document_Map();
		$this->document_map->register();

		$this->maybe_support_reaction_cpt();

		add_action( 'admin_init', [ $this, 'maybe_initialize_settings' ] );
		add_filter( 'manage_post_posts_columns', [ $this, 'add_status_column' ] );
		add_action( 'manage_post_posts_custom_column', [ $this, 'render_status_column' ], 10, 2 );
	}

	/**
	 * Remove everything register() added.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		$this->registered = false;

		remove_action( 'admin_notices', [ $this, 'dependency_notice' ] );
		remove_action( 'admin_init', [ $this, 'maybe_initialize_settings' ] );
		remove_filter( 'manage_post_posts_columns', [ $this, 'add_status_column' ] );
		remove_action( 'manage_post_posts_custom_column', [ $this, 'render_status_column' ], 10 );

		if ( $this->eligibility instanceof Atmosphere_Eligibility ) {
			$this->eligibility->unregister();
			$this->eligibility = null;
		}

		if ( $this->document_map instanceof Atmosphere_Document_Map ) {
			$this->document_map->unregister();
			$this->document_map = null;
		}
	}

	/**
	 * Whether a compatible ATmosphere is active in this request.
	 *
	 * @since 1.6.0
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return defined( 'ATMOSPHERE_VERSION' )
			&& version_compare( (string) ATMOSPHERE_VERSION, self::MIN_VERSION, '>=' );
	}

	/**
	 * Integration status for admin surfaces.
	 *
	 * @since 1.6.0
	 *
	 * @return array{active: bool, version: string|null, compatible: bool, connected: bool, needs_reauth: bool, settings_url: string}
	 */
	public static function status(): array {
		$active     = defined( 'ATMOSPHERE_VERSION' );
		$compatible = self::is_available();

		return [
			'active'       => $active,
			'version'      => $active ? (string) ATMOSPHERE_VERSION : null,
			'compatible'   => $compatible,
			'connected'    => $compatible && function_exists( '\\Atmosphere\\is_connected' ) && \Atmosphere\is_connected(),
			'needs_reauth' => $compatible && function_exists( '\\Atmosphere\\needs_reauth' ) && \Atmosphere\needs_reauth(),
			'settings_url' => $compatible && function_exists( '\\Atmosphere\\settings_url' )
				? \Atmosphere\settings_url()
				: admin_url( 'plugins.php' ),
		];
	}

	/**
	 * Tell administrators the dependency is missing or too old.
	 *
	 * Covers the paths WordPress's own plugin-dependency UI does not:
	 * a version below MIN_VERSION, and installs where enforcement was
	 * bypassed (WP-CLI, direct database changes).
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( defined( 'ATMOSPHERE_VERSION' ) ) {
			$message = sprintf(
				/* translators: 1: installed ATmosphere version, 2: minimum supported version. */
				__( 'Post Kinds for IndieWeb publishes to Standard.site through the ATmosphere plugin, but the active ATmosphere %1$s is older than the supported %2$s. Standard.site publishing stays off until ATmosphere is updated; everything else works normally.', 'post-kinds-for-indieweb-in-block-themes' ),
				(string) ATMOSPHERE_VERSION,
				self::MIN_VERSION
			);
		} else {
			$message = __( 'Post Kinds for IndieWeb publishes to Standard.site through the ATmosphere plugin, which is not active. Standard.site publishing stays off until ATmosphere is installed and activated; everything else works normally.', 'post-kinds-for-indieweb-in-block-themes' );
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Manage plugins', 'post-kinds-for-indieweb-in-block-themes' )
		);
	}

	/**
	 * Seed the eligibility setting once, preserving prior behavior.
	 *
	 * A site that ran ATmosphere before this integration has been
	 * publishing every kind; narrowing that silently on upgrade would
	 * change behavior the administrator already chose. If any post of a
	 * default-off kind carries a published document record, seed the
	 * setting with every kind (no behavior change); otherwise seed the
	 * recommended defaults.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function maybe_initialize_settings(): void {
		if ( false !== get_option( Atmosphere_Eligibility::OPTION ) ) {
			return;
		}

		$eligible = ( new Atmosphere_Eligibility() )->get_eligible_kinds();
		$all      = get_terms(
			[
				'taxonomy'   => 'kind',
				'hide_empty' => false,
				'fields'     => 'slugs',
			]
		);
		$all      = is_array( $all ) ? array_map( 'strval', $all ) : [];

		$default_off = array_values( array_diff( $all, $eligible ) );

		if ( ! empty( $default_off ) && $this->has_published_kind_document( $default_off ) ) {
			$eligible = $all;
		}

		update_option( Atmosphere_Eligibility::OPTION, [ 'eligible_kinds' => $eligible ], false );
	}

	/**
	 * Whether any post of the given kinds already has a document record.
	 *
	 * @since 1.6.0
	 *
	 * @param string[] $kinds Kind slugs.
	 * @return bool
	 */
	private function has_published_kind_document( array $kinds ): bool {
		$query = new \WP_Query(
			[
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time seeding heuristic, then never again.
				'meta_key'               => '_atmosphere_doc_uri',
				'meta_compare'           => 'EXISTS',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Same one-time heuristic.
				'tax_query'              => [
					[
						'taxonomy' => 'kind',
						'field'    => 'slug',
						'terms'    => $kinds,
					],
				],
			]
		);

		return ! empty( $query->posts );
	}

	/**
	 * Support the reaction CPT when import storage uses it.
	 *
	 * The support registry is name-keyed, so add_post_type_support() is
	 * safe to call before the type registers. Kinds stored there are
	 * consumption kinds, so the eligibility defaults keep them opt-in.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	private function maybe_support_reaction_cpt(): void {
		$settings = get_option( 'pkiw_settings', [] );
		$mode     = is_array( $settings ) ? ( $settings['import_storage_mode'] ?? 'standard' ) : 'standard';

		if ( 'cpt' === $mode ) {
			add_post_type_support( \PKIW\Post_Type::POST_TYPE, 'atmosphere' );
		}
	}

	/**
	 * Add the Standard.site column to the posts list.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_status_column( array $columns ): array {
		$columns['pkiw_atmosphere'] = __( 'Standard.site', 'post-kinds-for-indieweb-in-block-themes' );

		return $columns;
	}

	/**
	 * Render a post's Standard.site publish state.
	 *
	 * Reads only ATmosphere's own state — the documented per-post meta
	 * and the document URI its backfill treats as "synced" — so this
	 * column can never disagree with ATmosphere.
	 *
	 * @since 1.6.0
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_status_column( string $column, int $post_id ): void {
		if ( 'pkiw_atmosphere' !== $column ) {
			return;
		}

		$doc_uri = (string) get_post_meta( $post_id, \Atmosphere\Transformer\Document::META_URI, true );

		if ( '' !== $doc_uri ) {
			printf(
				'<span title="%s">%s</span>',
				esc_attr( $doc_uri ),
				esc_html__( 'Published', 'post-kinds-for-indieweb-in-block-themes' )
			);
			return;
		}

		if ( '1' === (string) get_post_meta( $post_id, 'atmosphere_disabled', true ) ) {
			echo esc_html__( 'Off', 'post-kinds-for-indieweb-in-block-themes' );
			return;
		}

		if ( 'publish' === get_post_status( $post_id ) ) {
			echo esc_html__( 'Pending', 'post-kinds-for-indieweb-in-block-themes' );
			return;
		}

		echo '&#8212;';
	}
}
