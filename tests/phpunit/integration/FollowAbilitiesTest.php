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

	public function test_post_id_abilities_answer_hidden_governed_posts_like_a_missing_id_for_every_role(): void {
		$controls = $this->governed_status_controls();
		$eligible = $this->follow( 'Visible follow', 'https://visible-follow.example/' );
		$note     = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret', 'post_title' => 'Protected note control' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$roles = [
			'visitor'       => 0,
			'subscriber'    => self::factory()->user->create( [ 'role' => 'subscriber' ] ),
			'author'        => self::factory()->user->create( [ 'role' => 'author' ] ),
			'administrator' => self::factory()->user->create( [ 'role' => 'administrator' ] ),
		];

		foreach ( $roles as $role => $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( [ 'post-kinds/get-kind', 'post-kinds/get-post-meta', 'post-kinds/get-surface', 'post-kinds/get-public-location-fields' ] as $name ) {
				$ability = wp_get_ability( $name );
				$this->assertNotNull( $ability );
				$missing = $this->ability_signature( $ability->execute( [ 'post_id' => 99999999 ] ) );
				foreach ( $controls as $case => $post_id ) {
					$this->assertSame( $missing, $this->ability_signature( $ability->execute( [ 'post_id' => $post_id ] ) ), "$role $name $case must match a missing ID" );
				}
				if ( 0 === $user_id ) {
					$this->assertSame( $missing, $this->ability_signature( $ability->execute( [ 'post_id' => $eligible ] ) ) );
					$this->assertSame( $missing, $this->ability_signature( $ability->execute( [ 'post_id' => $note ] ) ) );
				} else {
					$this->assertSame( [ 'ok', 0 ], $this->ability_signature( $ability->execute( [ 'post_id' => $eligible ] ) ), "$role $name eligible follow positive control" );
					$note_expected = 'post-kinds/get-surface' === $name && 'administrator' !== $role ? [ 'forbidden', 403 ] : [ 'ok', 0 ];
					$this->assertSame( $note_expected, $this->ability_signature( $ability->execute( [ 'post_id' => $note ] ) ), "$role $name protected note keeps its current answer" );
				}
			}
		}
	}

	public function test_post_id_ability_rest_routes_match_missing_ids_for_hidden_governed_posts(): void {
		$controls = $this->governed_status_controls();
		$eligible = $this->follow( 'Visible follow', 'https://visible-follow.example/' );
		$note     = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret', 'post_title' => 'Protected note control' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$roles = [
			'visitor'       => 0,
			'subscriber'    => self::factory()->user->create( [ 'role' => 'subscriber' ] ),
			'author'        => self::factory()->user->create( [ 'role' => 'author' ] ),
			'administrator' => self::factory()->user->create( [ 'role' => 'administrator' ] ),
		];

		foreach ( $roles as $role => $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( [ 'post-kinds/get-kind', 'post-kinds/get-post-meta', 'post-kinds/get-surface', 'post-kinds/get-public-location-fields' ] as $name ) {
				$missing = $this->rest_signature( $name, 99999999 );
				foreach ( $controls as $case => $post_id ) {
					$this->assertSame( $missing, $this->rest_signature( $name, $post_id ), "$role $name $case REST response must match a missing ID" );
				}
				if ( 0 === $user_id ) {
					$this->assertSame( $missing, $this->rest_signature( $name, $eligible ) );
					$this->assertSame( $missing, $this->rest_signature( $name, $note ) );
				} else {
					$this->assertSame( [ 200, '' ], $this->rest_signature( $name, $eligible ), "$role $name eligible follow REST positive control" );
					$note_expected = 'post-kinds/get-surface' === $name && 'administrator' !== $role ? [ 403, 'forbidden' ] : [ 200, '' ];
					$this->assertSame( $note_expected, $this->rest_signature( $name, $note ), "$role $name protected note keeps its current REST answer" );
				}
			}
		}
	}

	public function test_stale_cached_identity_is_rederived_for_opml_ability_and_letters(): void {
		$post_id = $this->follow( 'Old Sentinel Name', 'https://old-sentinel.example/' );
		$cached  = Follow_Archive::index();
		update_post_meta( $post_id, '_pkiw_cite_name', 'New Name' );
		update_post_meta( $post_id, '_pkiw_cite_url', 'https://new.example/' );
		set_transient( Follow_Archive::INDEX_TRANSIENT, $cached, HOUR_IN_SECONDS );

		$served = Follow_Archive::served_index();
		$opml   = Follow_Archive::opml_response( 'GET' )['body'];
		$output = Follow_Abilities::instance()->execute_get_follow_index();
		$this->assertSame( [ 'N' ], array_keys( $served['letters'] ), 'Letters must be rebuilt from the fresh name.' );
		$this->assertSame( 'New Name', $output['follows'][0]['name'] );
		$this->assertSame( 'https://new.example/', $output['follows'][0]['url'] );
		$this->assertStringContainsString( 'New Name', $opml );
		$this->assertStringNotContainsString( 'Old Sentinel Name', wp_json_encode( [ $served, $output, $opml ] ), 'No public index reader may serve the stale cached name.' );
		$this->assertStringNotContainsString( 'old-sentinel.example', wp_json_encode( [ $served, $output, $opml ] ), 'No public index reader may serve the stale cached URL.' );
	}

	private function governed_status_controls(): array {
		$controls = [];
		foreach ( [ 'follow', 'tag' ] as $kind ) {
			foreach ( [ 'private', 'draft', 'pending', 'future', 'password', 'trash' ] as $status ) {
				$args = [ 'post_status' => $status, 'post_title' => "$kind $status sentinel" ];
				if ( 'future' === $status ) {
					$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
				} elseif ( 'password' === $status ) {
					$args['post_status']   = 'publish';
					$args['post_password'] = 'secret';
				} elseif ( 'trash' === $status ) {
					$args['post_status'] = 'publish';
				}
				$post_id = self::factory()->post->create( $args );
				wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
				if ( 'trash' === $status ) {
					wp_trash_post( $post_id );
				}
				$controls[ "$kind $status" ] = (int) $post_id;
			}
		}
		return $controls;
	}

	private function ability_signature( $result ): array {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return [ $result->get_error_code(), (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 ) ];
		}
		return [ 'ok', 0 ];
	}

	private function rest_signature( string $name, int $post_id ): array {
		$readonly = in_array( $name, [ 'post-kinds/get-surface', 'post-kinds/get-public-location-fields' ], true );
		$request  = new WP_REST_Request( $readonly ? 'GET' : 'POST', '/wp-abilities/v1/abilities/' . $name . '/run' );
		if ( $readonly ) {
			$request->set_query_params( [ 'input' => [ 'post_id' => $post_id ] ] );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( [ 'input' => [ 'post_id' => $post_id ] ] ) );
		}
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		return [ $response->get_status(), is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '' ];
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
