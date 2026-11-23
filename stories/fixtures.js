import taxonomySource from '../includes/class-taxonomy.php?raw';

// Kind terms as GET /wp/v2/kind returns them, read from Taxonomy::$default_kinds
// so a renamed or added kind shows up here without editing the stories.
const defaultKinds = taxonomySource.match(
	/private array \$default_kinds = \[([\s\S]*?)\n\t\];/
)[ 1 ];

export const KINDS = [
	...defaultKinds.matchAll(
		/'([a-z-]+)'\s*=> \[\s*'name'\s*=> '([^']+)',\s*'description' => '((?:[^'\\]|\\.)*)'/g
	),
].map( ( [ , slug, name, description ], index ) => ( {
	id: index + 1,
	slug,
	name,
	description: description.replace( /\\'/g, "'" ),
} ) );

if ( KINDS.length < 40 ) {
	throw new Error(
		`Parsed ${ KINDS.length } kinds from includes/class-taxonomy.php. The $default_kinds format changed.`
	);
}

export const kindId = ( slug ) => KINDS.find( ( k ) => k.slug === slug ).id;

// Shapes from includes/class-plugin.php get_available_syndication_services().
export const SYNDICATION = {
	lastfmConnected: {
		lastfm: {
			name: 'Last.fm',
			kind: 'listen',
			metaKey: '_pkiw_syndicate_lastfm',
			connected: true,
			needsAuth: false,
		},
	},
	lastfmNeedsAuth: {
		lastfm: {
			name: 'Last.fm',
			kind: 'listen',
			metaKey: '_pkiw_syndicate_lastfm',
			connected: false,
			needsAuth: true,
		},
	},
};

const cover = ( label, background ) =>
	`data:image/svg+xml,${ encodeURIComponent(
		`<svg xmlns="http://www.w3.org/2000/svg" width="250" height="250"><rect width="250" height="250" fill="${ background }"/><text x="125" y="135" font-family="sans-serif" font-size="28" fill="#fff" text-anchor="middle">${ label }</text></svg>`
	) }`;

// Normalized results from includes/apis/class-musicbrainz.php and
// includes/apis/class-openlibrary.php, as the lookup routes return them.
export const MUSIC_RESULTS = [
	{
		track: 'Hounds of Love',
		artist: 'Kate Bush',
		album: 'Hounds of Love',
		mbid: '0d3b5e2c-0000-4000-8000-000000000001',
		cover: cover( 'HoL', '#3b2a5c' ),
		source: 'musicbrainz',
	},
	{
		track: 'Running Up That Hill',
		artist: 'Kate Bush',
		album: 'Hounds of Love',
		mbid: '0d3b5e2c-0000-4000-8000-000000000002',
		cover: cover( 'RUTH', '#5c2a3b' ),
		source: 'musicbrainz',
	},
];

export const BOOK_RESULTS = [
	{
		title: 'The Left Hand of Darkness',
		author: 'Ursula K. Le Guin',
		year: 1969,
		cover: cover( 'LHoD', '#24425c' ),
		source: 'openlibrary',
	},
	{
		title: 'The Dispossessed',
		author: 'Ursula K. Le Guin',
		year: 1974,
		cover: null,
		source: 'openlibrary',
	},
];

export const listenCover = cover( 'HoL', '#3b2a5c' );
