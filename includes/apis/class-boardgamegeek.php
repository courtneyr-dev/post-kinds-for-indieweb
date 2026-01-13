<?php
/**
 * BoardGameGeek API Integration
 *
 * Provides integration with BoardGameGeek's XML API2 for board game
 * and video game lookups.
 *
 * @package ReactionsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\APIs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BoardGameGeek API class.
 *
 * Handles communication with BoardGameGeek's XML API2 for searching
 * and retrieving game metadata.
 *
 * @since 1.1.0
 */
class BoardGameGeek extends API_Base {

	/**
	 * API name for identification.
	 *
	 * @var string
	 */
	protected string $api_name = 'bgg';

	/**
	 * Base URL for the API.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://boardgamegeek.com/xmlapi2/';

	/**
	 * Rate limit - BGG recommends 5 seconds between requests.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.2;

	/**
	 * Cache duration - 1 week for game data.
	 *
	 * @var int
	 */
	protected int $cache_duration = WEEK_IN_SECONDS;

	/**
	 * Game types supported.
	 *
	 * @var array<string, string>
	 */
	private array $game_types = array(
		'boardgame'      => 'Board Games',
		'boardgameexpansion' => 'Board Game Expansions',
		'videogame'      => 'Video Games',
		'rpgitem'        => 'RPG Items',
	);

	/**
	 * API token for authentication.
	 *
	 * @var string
	 */
	private string $api_token = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		// Get token from API credentials storage.
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
		$this->api_token = $credentials['bgg']['api_token'] ?? '';
	}

	/**
	 * Test API connection.
	 *
	 * @return bool True if connection successful.
	 */
	public function test_connection(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}
		$response = $this->get( 'search', array( 'query' => 'Catan', 'type' => 'boardgame' ) );
		return ! is_wp_error( $response ) && ! empty( $response );
	}

	/**
	 * Check if API is configured.
	 *
	 * @return bool True if API token is set.
	 */
	public function is_configured(): bool {
		return ! empty( $this->api_token );
	}

	/**
	 * Search for games.
	 *
	 * @param string $query Search query.
	 * @param string $type  Game type (boardgame, videogame, etc.).
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, string $type = 'boardgame' ): array {
		if ( empty( $query ) ) {
			return array();
		}

		$cache_key = $this->get_cache_key( 'search_' . $query . '_' . $type );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$response = $this->get(
			'search',
			array(
				'query' => $query,
				'type'  => $type,
			)
		);

		if ( is_wp_error( $response ) || empty( $response ) ) {
			return array();
		}

		$results = $this->parse_search_results( $response );
		$this->set_cache( $cache_key, $results );

		return $results;
	}

	/**
	 * Get game by ID.
	 *
	 * @param string $id   Game ID.
	 * @param bool   $stats Include statistics.
	 * @return array<string, mixed>|null Game data or null.
	 */
	public function get_by_id( string $id, bool $stats = true ): ?array {
		if ( empty( $id ) ) {
			return null;
		}

		$cache_key = $this->get_cache_key( 'thing_' . $id . '_' . ( $stats ? '1' : '0' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$params = array( 'id' => $id );
		if ( $stats ) {
			$params['stats'] = 1;
		}

		$response = $this->get( 'thing', $params );

		if ( is_wp_error( $response ) || empty( $response ) ) {
			return null;
		}

		$result = $this->parse_thing_result( $response );

		if ( $result ) {
			$this->set_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Normalize result to standard format.
	 *
	 * @param array<string, mixed> $item Raw item from API.
	 * @return array<string, mixed> Normalized result.
	 */
	public function normalize_result( array $item ): array {
		return array(
			'id'          => $item['id'] ?? '',
			'title'       => $item['name'] ?? '',
			'year'        => $item['year'] ?? '',
			'cover'       => $item['image'] ?? $item['thumbnail'] ?? '',
			'thumbnail'   => $item['thumbnail'] ?? '',
			'description' => $item['description'] ?? '',
			'rating'      => $item['rating'] ?? 0,
			'rating_count'=> $item['rating_count'] ?? 0,
			'type'        => $item['type'] ?? 'boardgame',
			'designers'   => $item['designers'] ?? array(),
			'publishers'  => $item['publishers'] ?? array(),
			'min_players' => $item['min_players'] ?? 0,
			'max_players' => $item['max_players'] ?? 0,
			'play_time'   => $item['play_time'] ?? 0,
			'categories'  => $item['categories'] ?? array(),
			'mechanics'   => $item['mechanics'] ?? array(),
			'url'         => $this->get_game_url( $item['id'] ?? '', $item['type'] ?? 'boardgame' ),
			'source'      => 'bgg',
		);
	}

	/**
	 * Get BGG game URL.
	 *
	 * @param string $id   Game ID.
	 * @param string $type Game type.
	 * @return string Game URL.
	 */
	private function get_game_url( string $id, string $type = 'boardgame' ): string {
		if ( empty( $id ) ) {
			return '';
		}

		$base = 'videogame' === $type
			? 'https://videogamegeek.com/videogame/'
			: 'https://boardgamegeek.com/boardgame/';

		return $base . $id;
	}

	/**
	 * Override get method to handle XML responses.
	 *
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $params   Query parameters.
	 * @return string|array|\WP_Error Response data or error.
	 */
	public function get( string $endpoint, array $params = array() ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error(
				'bgg_not_configured',
				__( 'BoardGameGeek API token is not configured. Add your token in Settings > Reactions.', 'reactions-for-indieweb' )
			);
		}

		$url = $this->base_url . $endpoint;

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$this->respect_rate_limit();

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => $this->timeout,
				'user-agent' => $this->user_agent,
				'headers'    => array(
					'Authorization' => 'Bearer ' . $this->api_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// BGG returns 202 when request is queued - need to retry.
		if ( 202 === $code ) {
			sleep( 2 );
			return $this->get( $endpoint, $params );
		}

		// 401 means token is invalid or missing.
		if ( 401 === $code ) {
			return new \WP_Error(
				'bgg_auth_error',
				__( 'BoardGameGeek API authentication failed. Please check your API token.', 'reactions-for-indieweb' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'bgg_api_error',
				sprintf( 'BGG API returned status %d', $code )
			);
		}

		return $body;
	}

	/**
	 * Parse search results from XML.
	 *
	 * @param string $xml XML response.
	 * @return array<int, array<string, mixed>> Parsed results.
	 */
	private function parse_search_results( string $xml ): array {
		$results = array();

		libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $xml );

		if ( false === $doc ) {
			return $results;
		}

		foreach ( $doc->item as $item ) {
			$attrs = $item->attributes();
			$id    = (string) $attrs->id;
			$type  = (string) $attrs->type;

			$name = '';
			$year = '';

			// Get primary name.
			foreach ( $item->name as $name_elem ) {
				$name_attrs = $name_elem->attributes();
				if ( 'primary' === (string) $name_attrs->type ) {
					$name = (string) $name_attrs->value;
					break;
				}
			}

			// Fallback to first name.
			if ( empty( $name ) && isset( $item->name[0] ) ) {
				$name_attrs = $item->name[0]->attributes();
				$name = (string) $name_attrs->value;
			}

			// Get year.
			if ( isset( $item->yearpublished ) ) {
				$year_attrs = $item->yearpublished->attributes();
				$year = (string) $year_attrs->value;
			}

			if ( ! empty( $id ) && ! empty( $name ) ) {
				$results[] = array(
					'id'   => $id,
					'name' => $name,
					'year' => $year,
					'type' => $type,
				);
			}
		}

		return $results;
	}

	/**
	 * Parse thing (game details) result from XML.
	 *
	 * @param string $xml XML response.
	 * @return array<string, mixed>|null Parsed result or null.
	 */
	private function parse_thing_result( string $xml ): ?array {
		libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $xml );

		if ( false === $doc || ! isset( $doc->item[0] ) ) {
			return null;
		}

		$item  = $doc->item[0];
		$attrs = $item->attributes();

		$result = array(
			'id'          => (string) $attrs->id,
			'type'        => (string) $attrs->type,
			'name'        => '',
			'year'        => '',
			'description' => '',
			'image'       => '',
			'thumbnail'   => '',
			'min_players' => 0,
			'max_players' => 0,
			'play_time'   => 0,
			'rating'      => 0,
			'rating_count'=> 0,
			'designers'   => array(),
			'publishers'  => array(),
			'categories'  => array(),
			'mechanics'   => array(),
		);

		// Get primary name.
		foreach ( $item->name as $name_elem ) {
			$name_attrs = $name_elem->attributes();
			if ( 'primary' === (string) $name_attrs->type ) {
				$result['name'] = (string) $name_attrs->value;
				break;
			}
		}

		// Year published.
		if ( isset( $item->yearpublished ) ) {
			$year_attrs = $item->yearpublished->attributes();
			$result['year'] = (string) $year_attrs->value;
		}

		// Description.
		if ( isset( $item->description ) ) {
			$result['description'] = html_entity_decode( (string) $item->description, ENT_QUOTES | ENT_HTML5 );
		}

		// Images.
		if ( isset( $item->image ) ) {
			$result['image'] = (string) $item->image;
		}
		if ( isset( $item->thumbnail ) ) {
			$result['thumbnail'] = (string) $item->thumbnail;
		}

		// Player counts (for board games).
		if ( isset( $item->minplayers ) ) {
			$mp_attrs = $item->minplayers->attributes();
			$result['min_players'] = (int) $mp_attrs->value;
		}
		if ( isset( $item->maxplayers ) ) {
			$mp_attrs = $item->maxplayers->attributes();
			$result['max_players'] = (int) $mp_attrs->value;
		}

		// Play time.
		if ( isset( $item->playingtime ) ) {
			$pt_attrs = $item->playingtime->attributes();
			$result['play_time'] = (int) $pt_attrs->value;
		}

		// Statistics.
		if ( isset( $item->statistics->ratings ) ) {
			$ratings = $item->statistics->ratings;

			if ( isset( $ratings->average ) ) {
				$avg_attrs = $ratings->average->attributes();
				$result['rating'] = round( (float) $avg_attrs->value, 1 );
			}
			if ( isset( $ratings->usersrated ) ) {
				$ur_attrs = $ratings->usersrated->attributes();
				$result['rating_count'] = (int) $ur_attrs->value;
			}
		}

		// Links (designers, publishers, categories, mechanics).
		foreach ( $item->link as $link ) {
			$link_attrs = $link->attributes();
			$link_type  = (string) $link_attrs->type;
			$link_value = (string) $link_attrs->value;

			switch ( $link_type ) {
				case 'boardgamedesigner':
					$result['designers'][] = $link_value;
					break;
				case 'boardgamepublisher':
					$result['publishers'][] = $link_value;
					break;
				case 'boardgamecategory':
					$result['categories'][] = $link_value;
					break;
				case 'boardgamemechanic':
					$result['mechanics'][] = $link_value;
					break;
			}
		}

		return $result;
	}

	/**
	 * Get documentation URL.
	 *
	 * @return string Documentation URL.
	 */
	public function get_docs_url(): string {
		return 'https://boardgamegeek.com/wiki/page/BGG_XML_API2';
	}

	/**
	 * Get available game types.
	 *
	 * @return array<string, string> Game types.
	 */
	public function get_game_types(): array {
		return $this->game_types;
	}

	/**
	 * Get required configuration fields.
	 *
	 * @return array<string, array<string, mixed>> Configuration fields.
	 */
	public function get_config_fields(): array {
		return array(
			'bgg_api_token' => array(
				'label'       => __( 'BoardGameGeek API Token', 'reactions-for-indieweb' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: Link to BGG applications page */
					__( 'Get your token from %s', 'reactions-for-indieweb' ),
					'<a href="https://boardgamegeek.com/applications" target="_blank">boardgamegeek.com/applications</a>'
				),
			),
		);
	}
}
