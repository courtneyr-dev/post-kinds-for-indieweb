<?php
/**
 * A stand-in for the parts of WP Recipe Maker that Post Kinds reads.
 *
 * The integration suite doesn't load the real plugin. These two classes
 * mirror WP Recipe Maker 10.8.5: a recipe is a `wprm_recipe` post, its
 * values are post meta on that post (`wprm_servings`, `wprm_total_time`,
 * `wprm_parent_post_id`), its picture is the recipe post's thumbnail and
 * its courses are `wprm_course` terms on the recipe post, not on the post
 * that embeds it.
 *
 * @package PKIW
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals

if ( ! class_exists( 'WPRM_Recipe' ) ) {
	class WPRM_Recipe {
		private int $id;

		public function __construct( int $id ) {
			$this->id = $id;
		}

		public function id() {
			return $this->id;
		}

		public function name() {
			return get_the_title( $this->id );
		}

		public function summary() {
			return (string) get_post_meta( $this->id, 'wprm_summary', true );
		}

		public function image_id() {
			return (int) get_post_thumbnail_id( $this->id );
		}

		public function servings() {
			return get_post_meta( $this->id, 'wprm_servings', true );
		}

		public function servings_unit() {
			return (string) get_post_meta( $this->id, 'wprm_servings_unit', true );
		}

		public function prep_time() {
			return get_post_meta( $this->id, 'wprm_prep_time', true );
		}

		public function cook_time() {
			return get_post_meta( $this->id, 'wprm_cook_time', true );
		}

		public function total_time() {
			return get_post_meta( $this->id, 'wprm_total_time', true );
		}

		public function parent_post_id() {
			return (int) get_post_meta( $this->id, 'wprm_parent_post_id', true );
		}

		public function tags( $taxonomy, $names_only = false ) {
			$terms = get_the_terms( $this->id, 'wprm_' . $taxonomy );
			$terms = is_array( $terms ) ? $terms : array();

			return $names_only ? wp_list_pluck( $terms, 'name' ) : $terms;
		}
	}
}

if ( ! class_exists( 'WPRM_Recipe_Manager' ) ) {
	class WPRM_Recipe_Manager {
		public static function get_recipe( $id ) {
			$post = get_post( $id );

			return $post && 'wprm_recipe' === $post->post_type ? new WPRM_Recipe( (int) $id ) : false;
		}

		public static function get_recipe_ids_from_post( $post_id = false ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array();
			}
			preg_match_all( '/\[wprm-recipe\s+id="?(\d+)"?\]/', $post->post_content, $matches );

			return array_map( 'intval', $matches[1] );
		}
	}
}

/**
 * Register the recipe post type and course taxonomy the way WP Recipe Maker does:
 * both private, the taxonomy attached to the recipe post type only.
 */
function pkiw_register_wprm_stand_in_types(): void {
	register_post_type( 'wprm_recipe', array( 'public' => false, 'supports' => array( 'title', 'thumbnail' ) ) );
	register_taxonomy(
		'wprm_course',
		'wprm_recipe',
		array(
			'hierarchical' => true,
			'public'       => false,
			'query_var'    => false,
			'rewrite'      => false,
		)
	);
}
