<?php
/** Follow ability coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Abilities\Follow_Abilities;
use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowAbilitiesTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
	}

	public function test_index_returns_public_rows_and_normalizes_letter_filter(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Mina' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://mina.example/' );
		$output = Follow_Abilities::instance()->execute_get_follow_index( [ 'letter' => 'm' ] );
		$this->assertSame( [ $id ], array_column( $output['follows'], 'post_id' ) );
		$this->assertSame( 'M', $output['letters'][0]['letter'], 'Removing normalization would make the lowercase filter empty.' );
		$this->assertSame( 1, $output['total'] );
	}

	public function test_registration_schema_and_logged_out_permission_contract(): void {
		$ability = wp_get_ability( 'post-kinds/get-follow-index' );
		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta()['mcp']['public'] ?? false );
		$schema = $ability->get_output_schema();
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertFalse( $schema['properties']['letters']['items']['additionalProperties'] );
		$this->assertFalse( $schema['properties']['follows']['items']['additionalProperties'] );
		$this->assertSame( [ 'post_id', 'name', 'host', 'url', 'initial', 'permalink' ], $schema['properties']['follows']['items']['required'] );

		wp_set_current_user( 0 );
		$this->assertFalse( $ability->check_permissions( [] ), 'The get-quote-index precedent requires the read capability.' );
	}

	public function test_real_output_validates_and_keeps_url_less_rows_and_accent_filters(): void {
		$accent = $this->follow( 'Élodie', 'https://elodie.example/' );
		$plain  = $this->follow( 'Farah', 'https://farah.example/' );
		$no_url = $this->follow( 'Nora', '' );
		$output = Follow_Abilities::instance()->execute_get_follow_index();
		$this->assertTrue( rest_validate_value_from_schema( $output, wp_get_ability( 'post-kinds/get-follow-index' )->get_output_schema() ) );
		$this->assertContains( $no_url, array_column( $output['follows'], 'post_id' ) );
		$rows = array_combine( array_column( $output['follows'], 'post_id' ), $output['follows'] );
		$this->assertSame( '', $rows[ $no_url ]['url'] );
		$this->assertSame( [ $plain ], array_column( Follow_Abilities::instance()->execute_get_follow_index( [ 'letter' => 'f' ] )['follows'], 'post_id' ) );
		$this->assertSame( [ $accent ], array_column( Follow_Abilities::instance()->execute_get_follow_index( [ 'letter' => 'é' ] )['follows'], 'post_id' ) );
	}

	private function follow( string $name, string $url ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', $name );
		if ( '' !== $url ) {
			update_post_meta( $id, '_pkiw_cite_url', $url );
		}
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
		return (int) $id;
	}
}
