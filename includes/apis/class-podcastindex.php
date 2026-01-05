<?php
/**
 * Podcast Index API Integration
 *
 * Provides podcast search and episode metadata from Podcast Index.
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
 * Podcast Index API class.
 *
 * @since 1.0.0
 */
class PodcastIndex extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'podcastindex';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.podcastindex.org/api/1.0/';

	/**
	 * Rate limit.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.1;

	/**
	 * Cache duration: 1 day.
	 *
	 * @var int
	 */
	protected int $cache_duration = DAY_IN_SECONDS;

	/**
	 * API key.
	 *
	 * @var string|null
	 */
	private ?string $api_key = null;

	/**
	 * API secret.
	 *
	 * @var string|null
	 */
	private ?string $api_secret = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials      = get_option( 'reactions_indieweb_api_credentials', array() );
		$pi_creds         = $credentials['podcastindex'] ?? array();
		$this->api_key    = $pi_creds['api_key'] ?? '';
		$this->api_secret = $pi_creds['api_secret'] ?? '';
	}

	/**
	 * Get authentication headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		$time = time();
		$auth_string = $this->api_key . $this->api_secret . $time;
		$auth_hash = hash( 'sha1', $auth_string );

		return array(
			'X-Auth-Date'   => (string) $time,
			'X-Auth-Key'    => $this->api_key ?? '',
			'Authorization' => $auth_hash,
			'User-Agent'    => $this->user_agent,
		);
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		if ( ! $this->api_key || ! $this->api_secret ) {
			return false;
		}

		try {
			$this->get( 'search/byterm', array( 'q' => 'test', 'max' => 1 ) );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Search for podcasts.
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
			$response = $this->get(
				'search/byterm',
				array(
					'q'   => $query,
					'max' => 25,
				)
			);

			$results = array();

			if ( isset( $response['feeds'] ) && is_array( $response['feeds'] ) ) {
				foreach ( $response['feeds'] as $feed ) {
					$results[] = $this->normalize_result( $feed );
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
	 * Search by podcast title.
	 *
	 * @param string $title Podcast title.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_title( string $title ): array {
		$cache_key = 'title_' . md5( $title );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'search/bytitle',
				array(
					'q'   => $title,
					'max' => 25,
				)
			);

			$results = array();

			if ( isset( $response['feeds'] ) ) {
				foreach ( $response['feeds'] as $feed ) {
					$results[] = $this->normalize_podcast( $feed );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search by title failed', array( 'title' => $title, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Search by person (host, guest, author).
	 *
	 * @param string $person Person name.
	 * @return array<int, array<string, mixed>> Results.
	 */
	public function search_by_person( string $person ): array {
		$cache_key = 'person_' . md5( $person );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'search/byperson',
				array(
					'q'   => $person,
					'max' => 25,
				)
			);

			$results = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$results[] = $this->normalize_episode( $item );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search by person failed', array( 'person' => $person, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get podcast by ID.
	 *
	 * @param string $id Podcast Index feed ID.
	 * @return array<string, mixed>|null Podcast data.
	 */
	public function get_by_id( string $id ): ?array {
		return $this->get_podcast( (int) $id );
	}

	/**
	 * Get podcast details.
	 *
	 * @param int $feed_id Podcast Index feed ID.
	 * @return array<string, mixed>|null Podcast data.
	 */
	public function get_podcast( int $feed_id ): ?array {
		$cache_key = 'podcast_' . $feed_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'podcasts/byfeedid', array( 'id' => $feed_id ) );

			if ( isset( $response['feed'] ) ) {
				$result = $this->normalize_podcast( $response['feed'], true );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get podcast failed', array( 'feed_id' => $feed_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get podcast by iTunes ID.
	 *
	 * @param int $itunes_id Apple Podcasts ID.
	 * @return array<string, mixed>|null Podcast data.
	 */
	public function get_by_itunes_id( int $itunes_id ): ?array {
		$cache_key = 'itunes_' . $itunes_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'podcasts/byitunesid', array( 'id' => $itunes_id ) );

			if ( isset( $response['feed'] ) ) {
				$result = $this->normalize_podcast( $response['feed'], true );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get by iTunes ID failed', array( 'itunes_id' => $itunes_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get podcast by feed URL.
	 *
	 * @param string $url Feed URL.
	 * @return array<string, mixed>|null Podcast data.
	 */
	public function get_by_feed_url( string $url ): ?array {
		$cache_key = 'feed_' . md5( $url );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'podcasts/byfeedurl', array( 'url' => $url ) );

			if ( isset( $response['feed'] ) ) {
				$result = $this->normalize_podcast( $response['feed'], true );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get by feed URL failed', array( 'url' => $url, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get podcast by GUID.
	 *
	 * @param string $guid Podcast GUID.
	 * @return array<string, mixed>|null Podcast data.
	 */
	public function get_by_guid( string $guid ): ?array {
		$cache_key = 'guid_' . md5( $guid );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'podcasts/byguid', array( 'guid' => $guid ) );

			if ( isset( $response['feed'] ) ) {
				$result = $this->normalize_podcast( $response['feed'], true );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get by GUID failed', array( 'guid' => $guid, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get podcast episodes.
	 *
	 * @param int $feed_id Podcast Index feed ID.
	 * @param int $max     Max episodes.
	 * @return array<int, array<string, mixed>> Episodes.
	 */
	public function get_episodes( int $feed_id, int $max = 25 ): array {
		$cache_key = "episodes_{$feed_id}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'episodes/byfeedid',
				array(
					'id'  => $feed_id,
					'max' => min( $max, 1000 ),
				)
			);

			$episodes = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$episodes[] = $this->normalize_episode( $item );
				}
			}

			$this->set_cache( $cache_key, $episodes );

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episodes failed', array( 'feed_id' => $feed_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get episode by ID.
	 *
	 * @param int $episode_id Episode ID.
	 * @return array<string, mixed>|null Episode data.
	 */
	public function get_episode( int $episode_id ): ?array {
		$cache_key = 'episode_' . $episode_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'episodes/byid', array( 'id' => $episode_id ) );

			if ( isset( $response['episode'] ) ) {
				$result = $this->normalize_episode( $response['episode'], true );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episode failed', array( 'episode_id' => $episode_id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get episode by GUID.
	 *
	 * @param string $guid    Episode GUID.
	 * @param string $feedurl Feed URL for disambiguation.
	 * @return array<string, mixed>|null Episode data.
	 */
	public function get_episode_by_guid( string $guid, string $feedurl = '' ): ?array {
		$cache_key = 'episode_guid_' . md5( $guid . $feedurl );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array( 'guid' => $guid );
			if ( $feedurl ) {
				$params['feedurl'] = $feedurl;
			}

			$response = $this->get( 'episodes/byguid', $params );

			if ( isset( $response['episode'] ) ) {
				$result = $this->normalize_episode( $response['episode'], true );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episode by GUID failed', array( 'guid' => $guid, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get recent episodes from a podcast.
	 *
	 * @param int  $feed_id Podcast feed ID.
	 * @param int  $max     Max episodes.
	 * @param bool $fulltext Include full description.
	 * @return array<int, array<string, mixed>> Recent episodes.
	 */
	public function get_recent_episodes( int $feed_id, int $max = 10, bool $fulltext = false ): array {
		try {
			$response = $this->get(
				'episodes/byfeedid',
				array(
					'id'       => $feed_id,
					'max'      => $max,
					'fulltext' => $fulltext ? 'true' : 'false',
				)
			);

			$episodes = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$episodes[] = $this->normalize_episode( $item );
				}
			}

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get recent episodes failed', array( 'feed_id' => $feed_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get random episodes.
	 *
	 * @param int    $max      Max episodes.
	 * @param string $lang     Language code.
	 * @param string $category Category.
	 * @return array<int, array<string, mixed>> Random episodes.
	 */
	public function get_random_episodes( int $max = 10, string $lang = '', string $category = '' ): array {
		try {
			$params = array( 'max' => $max );

			if ( $lang ) {
				$params['lang'] = $lang;
			}

			if ( $category ) {
				$params['cat'] = $category;
			}

			$response = $this->get( 'episodes/random', $params );

			$episodes = array();

			if ( isset( $response['episodes'] ) ) {
				foreach ( $response['episodes'] as $item ) {
					$episodes[] = $this->normalize_episode( $item );
				}
			}

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get random episodes failed', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get trending podcasts.
	 *
	 * @param int    $max      Max podcasts.
	 * @param string $lang     Language code.
	 * @param string $category Category.
	 * @return array<int, array<string, mixed>> Trending podcasts.
	 */
	public function get_trending( int $max = 25, string $lang = '', string $category = '' ): array {
		$cache_key = 'trending_' . md5( $lang . $category );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array( 'max' => $max );

			if ( $lang ) {
				$params['lang'] = $lang;
			}

			if ( $category ) {
				$params['cat'] = $category;
			}

			$response = $this->get( 'podcasts/trending', $params );

			$podcasts = array();

			if ( isset( $response['feeds'] ) ) {
				foreach ( $response['feeds'] as $feed ) {
					$podcast = $this->normalize_podcast( $feed );
					$podcast['trend_score'] = $feed['trendScore'] ?? 0;
					$podcasts[] = $podcast;
				}
			}

			$this->set_cache( $cache_key, $podcasts, HOUR_IN_SECONDS * 6 );

			return $podcasts;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get trending failed', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get recent podcasts (newly added).
	 *
	 * @param int    $max      Max podcasts.
	 * @param string $lang     Language code.
	 * @param string $category Category.
	 * @return array<int, array<string, mixed>> Recent podcasts.
	 */
	public function get_recent_feeds( int $max = 25, string $lang = '', string $category = '' ): array {
		$cache_key = 'recent_feeds_' . md5( $lang . $category );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array( 'max' => $max );

			if ( $lang ) {
				$params['lang'] = $lang;
			}

			if ( $category ) {
				$params['cat'] = $category;
			}

			$response = $this->get( 'recent/feeds', $params );

			$podcasts = array();

			if ( isset( $response['feeds'] ) ) {
				foreach ( $response['feeds'] as $feed ) {
					$podcasts[] = $this->normalize_podcast( $feed );
				}
			}

			$this->set_cache( $cache_key, $podcasts, HOUR_IN_SECONDS );

			return $podcasts;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get recent feeds failed', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get new episodes.
	 *
	 * @param int    $max  Max episodes.
	 * @param string $lang Language code.
	 * @return array<int, array<string, mixed>> New episodes.
	 */
	public function get_new_episodes( int $max = 25, string $lang = '' ): array {
		$cache_key = 'new_episodes_' . $lang;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = array( 'max' => $max );

			if ( $lang ) {
				$params['lang'] = $lang;
			}

			$response = $this->get( 'recent/episodes', $params );

			$episodes = array();

			if ( isset( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$episodes[] = $this->normalize_episode( $item );
				}
			}

			$this->set_cache( $cache_key, $episodes, 600 ); // 10 min cache.

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get new episodes failed', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get categories.
	 *
	 * @return array<int, array<string, mixed>> Categories.
	 */
	public function get_categories(): array {
		$cache_key = 'categories';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'categories/list' );

			$categories = array();

			if ( isset( $response['feeds'] ) ) {
				foreach ( $response['feeds'] as $cat ) {
					$categories[] = array(
						'id'   => $cat['id'] ?? 0,
						'name' => $cat['name'] ?? '',
					);
				}
			}

			$this->set_cache( $cache_key, $categories, WEEK_IN_SECONDS );

			return $categories;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get categories failed', array( 'error' => $e->getMessage() ) );
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
		return $this->normalize_podcast( $raw_result );
	}

	/**
	 * Normalize podcast data.
	 *
	 * @param array<string, mixed> $feed     Feed data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized podcast.
	 */
	private function normalize_podcast( array $feed, bool $detailed = false ): array {
		$result = array(
			'id'              => $feed['id'] ?? 0,
			'podcastindex_id' => $feed['id'] ?? 0,
			'title'           => $feed['title'] ?? '',
			'url'             => $feed['url'] ?? '',
			'original_url'    => $feed['originalUrl'] ?? '',
			'link'            => $feed['link'] ?? '',
			'description'     => $feed['description'] ?? '',
			'author'          => $feed['author'] ?? '',
			'owner_name'      => $feed['ownerName'] ?? '',
			'image'           => $feed['image'] ?? $feed['artwork'] ?? '',
			'artwork'         => $feed['artwork'] ?? $feed['image'] ?? '',
			'language'        => $feed['language'] ?? '',
			'categories'      => $this->parse_categories( $feed['categories'] ?? array() ),
			'itunes_id'       => $feed['itunesId'] ?? null,
			'generator'       => $feed['generator'] ?? '',
			'explicit'        => $feed['explicit'] ?? false,
			'episode_count'   => $feed['episodeCount'] ?? 0,
			'newest_item_date'=> $feed['newestItemPublishTime'] ?? null,
			'type'            => 'podcast',
			'source'          => 'podcastindex',
		);

		if ( $detailed ) {
			$result['last_update_time'] = $feed['lastUpdateTime'] ?? null;
			$result['last_crawl_time']  = $feed['lastCrawlTime'] ?? null;
			$result['last_parse_time']  = $feed['lastParseTime'] ?? null;
			$result['content_type']     = $feed['contentType'] ?? '';
			$result['chash']            = $feed['chash'] ?? '';
			$result['dead']             = $feed['dead'] ?? 0;
			$result['funding']          = $feed['funding'] ?? array();
			$result['value']            = $feed['value'] ?? array();
		}

		return $result;
	}

	/**
	 * Normalize episode data.
	 *
	 * @param array<string, mixed> $episode  Episode data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized episode.
	 */
	private function normalize_episode( array $episode, bool $detailed = false ): array {
		$result = array(
			'id'              => $episode['id'] ?? 0,
			'podcastindex_id' => $episode['id'] ?? 0,
			'title'           => $episode['title'] ?? '',
			'link'            => $episode['link'] ?? '',
			'description'     => $episode['description'] ?? '',
			'guid'            => $episode['guid'] ?? '',
			'date_published'  => $episode['datePublished'] ?? null,
			'date_crawled'    => $episode['dateCrawled'] ?? null,
			'enclosure_url'   => $episode['enclosureUrl'] ?? '',
			'enclosure_type'  => $episode['enclosureType'] ?? '',
			'enclosure_length'=> $episode['enclosureLength'] ?? 0,
			'duration'        => $episode['duration'] ?? 0,
			'explicit'        => $episode['explicit'] ?? 0,
			'episode'         => $episode['episode'] ?? null,
			'season'          => $episode['season'] ?? null,
			'image'           => $episode['image'] ?? $episode['feedImage'] ?? '',
			'feed_id'         => $episode['feedId'] ?? 0,
			'feed_title'      => $episode['feedTitle'] ?? '',
			'feed_image'      => $episode['feedImage'] ?? '',
			'feed_language'   => $episode['feedLanguage'] ?? '',
			'type'            => 'episode',
			'source'          => 'podcastindex',
		);

		if ( $detailed ) {
			$result['chapters_url']   = $episode['chaptersUrl'] ?? '';
			$result['transcript_url'] = $episode['transcriptUrl'] ?? '';
			$result['soundbite']      = $episode['soundbite'] ?? null;
			$result['soundbites']     = $episode['soundbites'] ?? array();
			$result['persons']        = $episode['persons'] ?? array();
			$result['social_interact']= $episode['socialInteract'] ?? array();
			$result['value']          = $episode['value'] ?? array();
		}

		return $result;
	}

	/**
	 * Parse categories.
	 *
	 * @param array<int|string, string>|object $categories Categories data.
	 * @return array<int, string> Category names.
	 */
	private function parse_categories( $categories ): array {
		if ( empty( $categories ) ) {
			return array();
		}

		if ( is_object( $categories ) ) {
			$categories = (array) $categories;
		}

		return array_values( $categories );
	}

	/**
	 * Set API credentials.
	 *
	 * @param string $key    API key.
	 * @param string $secret API secret.
	 * @return void
	 */
	public function set_credentials( string $key, string $secret ): void {
		$this->api_key = $key;
		$this->api_secret = $secret;
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://podcastindex-org.github.io/docs-api/';
	}
}
