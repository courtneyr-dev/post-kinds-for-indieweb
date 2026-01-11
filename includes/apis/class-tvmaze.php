<?php
/**
 * TVmaze API Integration
 *
 * Provides TV show and episode data from TVmaze (no API key required).
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\APIs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TVmaze API class.
 *
 * @since 1.0.0
 */
class TVmaze extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'tvmaze';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.tvmaze.com/';

	/**
	 * Rate limit: 20 requests per 10 seconds (free tier).
	 * Premium tier has higher limits.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.5;

	/**
	 * Cache duration: 1 day.
	 *
	 * @var int
	 */
	protected int $cache_duration = DAY_IN_SECONDS;

	/**
	 * API key (optional, for premium access).
	 *
	 * @var string|null
	 */
	private ?string $api_key = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials    = get_option( 'reactions_indieweb_api_credentials', array() );
		$tvmaze_creds   = $credentials['tvmaze'] ?? array();
		$this->api_key  = ! empty( $tvmaze_creds['api_key'] ) ? $tvmaze_creds['api_key'] : null;

		// Premium tier has higher rate limits.
		if ( $this->api_key ) {
			$this->rate_limit = 0.1; // 10 requests per second for premium.
		}
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		$headers = parent::get_default_headers();

		if ( $this->api_key ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $this->api_key . ':' );
		}

		return $headers;
	}

	/**
	 * Check if API is configured with premium access.
	 *
	 * @return bool True if premium API key is set.
	 */
	public function is_configured(): bool {
		return ! empty( $this->api_key );
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		try {
			$this->get( 'shows/1' );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Search for TV shows.
	 *
	 * @param string $query Search query.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$cache_key = 'search_' . md5( $query );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'search/shows', array( 'q' => $query ) );

			$results = array();

			foreach ( $response as $item ) {
				if ( isset( $item['show'] ) ) {
					$result = $this->normalize_result( $item['show'] );
					$result['score'] = $item['score'] ?? 0;
					$results[] = $result;
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search failed', array( 'query' => $query, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Single search (returns best match).
	 *
	 * @param string $query Search query.
	 * @return array<string, mixed>|null Best match.
	 */
	public function single_search( string $query ): ?array {
		$cache_key = 'single_' . md5( $query );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'singlesearch/shows', array( 'q' => $query ) );

			$result = $this->normalize_result( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Single search failed', array( 'query' => $query, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get show by ID.
	 *
	 * @param string $id TVmaze show ID.
	 * @return array<string, mixed>|null Show data.
	 */
	public function get_by_id( string $id ): ?array {
		return $this->get_show( (int) $id );
	}

	/**
	 * Get show details.
	 *
	 * @param int  $id      TVmaze show ID.
	 * @param bool $embed   Whether to embed episodes and cast.
	 * @return array<string, mixed>|null Show data.
	 */
	public function get_show( int $id, bool $embed = false ): ?array {
		$cache_key = 'show_' . $id . ( $embed ? '_embed' : '' );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array();
			if ( $embed ) {
				$params['embed[]'] = array( 'episodes', 'cast', 'crew' );
			}

			$response = $this->get( "shows/{$id}", $params );

			$result = $this->normalize_show( $response, true );

			// Process embedded data.
			if ( isset( $response['_embedded'] ) ) {
				if ( isset( $response['_embedded']['episodes'] ) ) {
					$result['episodes'] = array_map(
						array( $this, 'normalize_episode' ),
						$response['_embedded']['episodes']
					);
				}

				if ( isset( $response['_embedded']['cast'] ) ) {
					$result['cast'] = $this->normalize_cast( $response['_embedded']['cast'] );
				}

				if ( isset( $response['_embedded']['crew'] ) ) {
					$result['crew'] = $this->normalize_crew( $response['_embedded']['crew'] );
				}
			}

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get show failed', array( 'id' => $id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get show by external ID.
	 *
	 * @param string $source Source: tvdb, thetvdb, imdb, tvrage.
	 * @param string $id     External ID.
	 * @return array<string, mixed>|null Show data.
	 */
	public function lookup( string $source, string $id ): ?array {
		$cache_key = "lookup_{$source}_{$id}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'lookup/shows', array( $source => $id ) );

			$result = $this->normalize_show( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Lookup failed', array( 'source' => $source, 'id' => $id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get show episodes.
	 *
	 * @param int $show_id TVmaze show ID.
	 * @return array<int, array<string, mixed>> Episodes.
	 */
	public function get_episodes( int $show_id ): array {
		$cache_key = 'episodes_' . $show_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/episodes" );

			$episodes = array_map( array( $this, 'normalize_episode' ), $response );

			$this->set_cache( $cache_key, $episodes );

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episodes failed', array( 'show_id' => $show_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get episodes by date.
	 *
	 * @param int    $show_id TVmaze show ID.
	 * @param string $date    Date in Y-m-d format.
	 * @return array<int, array<string, mixed>> Episodes.
	 */
	public function get_episodes_by_date( int $show_id, string $date ): array {
		$cache_key = "episodes_{$show_id}_{$date}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/episodesbydate", array( 'date' => $date ) );

			$episodes = array_map( array( $this, 'normalize_episode' ), $response );

			$this->set_cache( $cache_key, $episodes );

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episodes by date failed', array( 'show_id' => $show_id, 'date' => $date, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get seasons.
	 *
	 * @param int $show_id TVmaze show ID.
	 * @return array<int, array<string, mixed>> Seasons.
	 */
	public function get_seasons( int $show_id ): array {
		$cache_key = 'seasons_' . $show_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/seasons" );

			$seasons = array_map( array( $this, 'normalize_season' ), $response );

			$this->set_cache( $cache_key, $seasons );

			return $seasons;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get seasons failed', array( 'show_id' => $show_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get season episodes.
	 *
	 * @param int $season_id TVmaze season ID.
	 * @return array<int, array<string, mixed>> Episodes.
	 */
	public function get_season_episodes( int $season_id ): array {
		$cache_key = 'season_episodes_' . $season_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "seasons/{$season_id}/episodes" );

			$episodes = array_map( array( $this, 'normalize_episode' ), $response );

			$this->set_cache( $cache_key, $episodes );

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get season episodes failed', array( 'season_id' => $season_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get specific episode.
	 *
	 * @param int $show_id Show ID.
	 * @param int $season  Season number.
	 * @param int $episode Episode number.
	 * @return array<string, mixed>|null Episode data.
	 */
	public function get_episode( int $show_id, int $season, int $episode ): ?array {
		$cache_key = "episode_{$show_id}_s{$season}_e{$episode}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				"shows/{$show_id}/episodebynumber",
				array(
					'season' => $season,
					'number' => $episode,
				)
			);

			$result = $this->normalize_episode( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episode failed', array( 'show_id' => $show_id, 'season' => $season, 'episode' => $episode, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get episode by ID.
	 *
	 * @param int $episode_id TVmaze episode ID.
	 * @return array<string, mixed>|null Episode data.
	 */
	public function get_episode_by_id( int $episode_id ): ?array {
		$cache_key = 'episode_' . $episode_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "episodes/{$episode_id}" );

			$result = $this->normalize_episode( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episode by ID failed', array( 'episode_id' => $episode_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get show cast.
	 *
	 * @param int $show_id TVmaze show ID.
	 * @return array<int, array<string, mixed>> Cast members.
	 */
	public function get_cast( int $show_id ): array {
		$cache_key = 'cast_' . $show_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/cast" );

			$cast = $this->normalize_cast( $response );

			$this->set_cache( $cache_key, $cast );

			return $cast;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get cast failed', array( 'show_id' => $show_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get show crew.
	 *
	 * @param int $show_id TVmaze show ID.
	 * @return array<int, array<string, mixed>> Crew members.
	 */
	public function get_crew( int $show_id ): array {
		$cache_key = 'crew_' . $show_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/crew" );

			$crew = $this->normalize_crew( $response );

			$this->set_cache( $cache_key, $crew );

			return $crew;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get crew failed', array( 'show_id' => $show_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get schedule.
	 *
	 * @param string $country Country code (US, GB, etc).
	 * @param string $date    Date in Y-m-d format.
	 * @return array<int, array<string, mixed>> Scheduled episodes.
	 */
	public function get_schedule( string $country = 'US', string $date = '' ): array {
		$date = $date ?: gmdate( 'Y-m-d' );

		$cache_key = "schedule_{$country}_{$date}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'schedule',
				array(
					'country' => $country,
					'date'    => $date,
				)
			);

			$schedule = array();

			foreach ( $response as $item ) {
				$episode = $this->normalize_episode( $item );

				if ( isset( $item['show'] ) ) {
					$episode['show'] = $this->normalize_show( $item['show'] );
				}

				$schedule[] = $episode;
			}

			$this->set_cache( $cache_key, $schedule, HOUR_IN_SECONDS * 6 );

			return $schedule;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get schedule failed', array( 'country' => $country, 'date' => $date, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get web/streaming schedule.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return array<int, array<string, mixed>> Streaming episodes.
	 */
	public function get_web_schedule( string $date = '' ): array {
		$date = $date ?: gmdate( 'Y-m-d' );

		$cache_key = "web_schedule_{$date}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'schedule/web', array( 'date' => $date ) );

			$schedule = array();

			foreach ( $response as $item ) {
				$episode = $this->normalize_episode( $item );

				if ( isset( $item['_embedded']['show'] ) ) {
					$episode['show'] = $this->normalize_show( $item['_embedded']['show'] );
				}

				$schedule[] = $episode;
			}

			$this->set_cache( $cache_key, $schedule, HOUR_IN_SECONDS * 6 );

			return $schedule;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get web schedule failed', array( 'date' => $date, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get person details.
	 *
	 * @param int $person_id TVmaze person ID.
	 * @return array<string, mixed>|null Person data.
	 */
	public function get_person( int $person_id ): ?array {
		$cache_key = 'person_' . $person_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "people/{$person_id}" );

			$result = $this->normalize_person( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get person failed', array( 'person_id' => $person_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get person's show credits.
	 *
	 * @param int $person_id TVmaze person ID.
	 * @return array<int, array<string, mixed>> Credits.
	 */
	public function get_person_credits( int $person_id ): array {
		$cache_key = 'person_credits_' . $person_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "people/{$person_id}/castcredits", array( 'embed' => 'show' ) );

			$credits = array();

			foreach ( $response as $credit ) {
				$item = array(
					'self'      => $credit['self'] ?? false,
					'voice'     => $credit['voice'] ?? false,
				);

				if ( isset( $credit['_embedded']['show'] ) ) {
					$item['show'] = $this->normalize_show( $credit['_embedded']['show'] );
				}

				if ( isset( $credit['_embedded']['character'] ) ) {
					$item['character'] = $credit['_embedded']['character']['name'] ?? '';
				}

				$credits[] = $item;
			}

			$this->set_cache( $cache_key, $credits );

			return $credits;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get person credits failed', array( 'person_id' => $person_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get show updates.
	 *
	 * @param string $since Since parameter: day, week, month.
	 * @return array<int, int> Show IDs mapped to timestamps.
	 */
	public function get_updates( string $since = 'day' ): array {
		$cache_key = 'updates_' . $since;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'updates/shows', array( 'since' => $since ) );

			$this->set_cache( $cache_key, $response, HOUR_IN_SECONDS );

			return $response;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get updates failed', array( 'since' => $since, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Normalize search result.
	 *
	 * @param array<string, mixed> $raw_result Raw result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		return $this->normalize_show( $raw_result );
	}

	/**
	 * Normalize show data.
	 *
	 * @param array<string, mixed> $show     Show data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized show.
	 */
	private function normalize_show( array $show, bool $detailed = false ): array {
		$result = array(
			'id'             => $show['id'] ?? 0,
			'tvmaze_id'      => $show['id'] ?? 0,
			'url'            => $show['url'] ?? '',
			'title'          => $show['name'] ?? '',
			'type'           => 'tv',
			'language'       => $show['language'] ?? '',
			'genres'         => $show['genres'] ?? array(),
			'status'         => $show['status'] ?? '',
			'runtime'        => $show['runtime'] ?? $show['averageRuntime'] ?? null,
			'premiered'      => $show['premiered'] ?? '',
			'ended'          => $show['ended'] ?? '',
			'schedule'       => $show['schedule'] ?? array(),
			'rating'         => $show['rating']['average'] ?? null,
			'weight'         => $show['weight'] ?? 0,
			'network'        => isset( $show['network'] ) ? $show['network']['name'] ?? '' : '',
			'web_channel'    => isset( $show['webChannel'] ) ? $show['webChannel']['name'] ?? '' : '',
			'externals'      => array(
				'tvrage' => $show['externals']['tvrage'] ?? null,
				'thetvdb'=> $show['externals']['thetvdb'] ?? null,
				'imdb'   => $show['externals']['imdb'] ?? '',
			),
			'poster'         => $show['image']['medium'] ?? null,
			'poster_original'=> $show['image']['original'] ?? null,
			'overview'       => $show['summary'] ?? '',
			'source'         => 'tvmaze',
		);

		// Clean HTML from summary.
		if ( $result['overview'] ) {
			$result['overview'] = wp_strip_all_tags( $result['overview'] );
		}

		if ( $detailed ) {
			$result['official_site'] = $show['officialSite'] ?? '';
			$result['updated']       = $show['updated'] ?? null;
		}

		return $result;
	}

	/**
	 * Normalize episode data.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return array<string, mixed> Normalized episode.
	 */
	private function normalize_episode( array $episode ): array {
		$result = array(
			'id'              => $episode['id'] ?? 0,
			'tvmaze_id'       => $episode['id'] ?? 0,
			'url'             => $episode['url'] ?? '',
			'title'           => $episode['name'] ?? '',
			'season'          => $episode['season'] ?? 0,
			'number'          => $episode['number'] ?? 0,
			'type'            => $episode['type'] ?? 'regular',
			'airdate'         => $episode['airdate'] ?? '',
			'airtime'         => $episode['airtime'] ?? '',
			'airstamp'        => $episode['airstamp'] ?? '',
			'runtime'         => $episode['runtime'] ?? null,
			'rating'          => $episode['rating']['average'] ?? null,
			'image'           => $episode['image']['medium'] ?? null,
			'image_original'  => $episode['image']['original'] ?? null,
			'overview'        => $episode['summary'] ?? '',
			'content_type'    => 'episode',
			'source'          => 'tvmaze',
		);

		// Clean HTML from summary.
		if ( $result['overview'] ) {
			$result['overview'] = wp_strip_all_tags( $result['overview'] );
		}

		return $result;
	}

	/**
	 * Normalize season data.
	 *
	 * @param array<string, mixed> $season Season data.
	 * @return array<string, mixed> Normalized season.
	 */
	private function normalize_season( array $season ): array {
		return array(
			'id'            => $season['id'] ?? 0,
			'tvmaze_id'     => $season['id'] ?? 0,
			'url'           => $season['url'] ?? '',
			'number'        => $season['number'] ?? 0,
			'name'          => $season['name'] ?? '',
			'episode_order' => $season['episodeOrder'] ?? null,
			'premiere_date' => $season['premiereDate'] ?? '',
			'end_date'      => $season['endDate'] ?? '',
			'network'       => isset( $season['network'] ) ? $season['network']['name'] ?? '' : '',
			'web_channel'   => isset( $season['webChannel'] ) ? $season['webChannel']['name'] ?? '' : '',
			'image'         => $season['image']['medium'] ?? null,
			'image_original'=> $season['image']['original'] ?? null,
			'overview'      => wp_strip_all_tags( $season['summary'] ?? '' ),
			'type'          => 'season',
			'source'        => 'tvmaze',
		);
	}

	/**
	 * Normalize cast data.
	 *
	 * @param array<int, array<string, mixed>> $cast Cast array.
	 * @return array<int, array<string, mixed>> Normalized cast.
	 */
	private function normalize_cast( array $cast ): array {
		$result = array();

		foreach ( $cast as $member ) {
			$person = $member['person'] ?? array();
			$character = $member['character'] ?? array();

			$result[] = array(
				'person_id'  => $person['id'] ?? 0,
				'name'       => $person['name'] ?? '',
				'image'      => $person['image']['medium'] ?? null,
				'character'  => $character['name'] ?? '',
				'self'       => $member['self'] ?? false,
				'voice'      => $member['voice'] ?? false,
			);
		}

		return $result;
	}

	/**
	 * Normalize crew data.
	 *
	 * @param array<int, array<string, mixed>> $crew Crew array.
	 * @return array<int, array<string, mixed>> Normalized crew.
	 */
	private function normalize_crew( array $crew ): array {
		$result = array();

		foreach ( $crew as $member ) {
			$person = $member['person'] ?? array();

			$result[] = array(
				'person_id' => $person['id'] ?? 0,
				'name'      => $person['name'] ?? '',
				'image'     => $person['image']['medium'] ?? null,
				'type'      => $member['type'] ?? '',
			);
		}

		return $result;
	}

	/**
	 * Normalize person data.
	 *
	 * @param array<string, mixed> $person Person data.
	 * @return array<string, mixed> Normalized person.
	 */
	private function normalize_person( array $person ): array {
		return array(
			'id'            => $person['id'] ?? 0,
			'tvmaze_id'     => $person['id'] ?? 0,
			'url'           => $person['url'] ?? '',
			'name'          => $person['name'] ?? '',
			'country'       => $person['country']['name'] ?? '',
			'birthday'      => $person['birthday'] ?? '',
			'deathday'      => $person['deathday'] ?? '',
			'gender'        => $person['gender'] ?? '',
			'image'         => $person['image']['medium'] ?? null,
			'image_original'=> $person['image']['original'] ?? null,
			'type'          => 'person',
			'source'        => 'tvmaze',
		);
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://www.tvmaze.com/api';
	}
}
