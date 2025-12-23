/**
 * Reactions for IndieWeb - Post Kinds Data Store
 *
 * Manages post kind state, auto-detection, and metadata in the block editor.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { createReduxStore } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { store as editorStore } from '@wordpress/editor';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Store name constant.
 *
 * @type {string}
 */
export const STORE_NAME = 'reactions-indieweb/post-kinds';

/**
 * Meta key prefix used in PHP.
 *
 * @type {string}
 */
const META_PREFIX = '_reactions_';

/**
 * Default state shape.
 *
 * @type {Object}
 */
const DEFAULT_STATE = {
	selectedKind: null,
	autoDetectedKind: null,
	isAutoDetectionEnabled: true,
	apiLookup: {
		isLoading: false,
		results: [],
		error: null,
		type: null,
	},
	availableKinds: [],
	isInitialized: false,
};

/**
 * Action types.
 *
 * @type {Object}
 */
const ACTION_TYPES = {
	SET_KIND: 'SET_KIND',
	SET_AUTO_DETECTED_KIND: 'SET_AUTO_DETECTED_KIND',
	ENABLE_AUTO_DETECTION: 'ENABLE_AUTO_DETECTION',
	DISABLE_AUTO_DETECTION: 'DISABLE_AUTO_DETECTION',
	START_API_LOOKUP: 'START_API_LOOKUP',
	RECEIVE_API_RESULTS: 'RECEIVE_API_RESULTS',
	API_LOOKUP_ERROR: 'API_LOOKUP_ERROR',
	CLEAR_API_RESULTS: 'CLEAR_API_RESULTS',
	SET_AVAILABLE_KINDS: 'SET_AVAILABLE_KINDS',
	SET_INITIALIZED: 'SET_INITIALIZED',
};

/**
 * Store actions.
 *
 * @type {Object}
 */
const actions = {
	/**
	 * Set the selected kind.
	 *
	 * @param {string} kind Kind slug.
	 * @return {Object} Action object.
	 */
	setKind( kind ) {
		return {
			type: ACTION_TYPES.SET_KIND,
			kind,
		};
	},

	/**
	 * Set the auto-detected kind.
	 *
	 * @param {string|null} kind Kind slug or null.
	 * @return {Object} Action object.
	 */
	setAutoDetectedKind( kind ) {
		return {
			type: ACTION_TYPES.SET_AUTO_DETECTED_KIND,
			kind,
		};
	},

	/**
	 * Enable auto-detection.
	 *
	 * @return {Object} Action object.
	 */
	enableAutoDetection() {
		return {
			type: ACTION_TYPES.ENABLE_AUTO_DETECTION,
		};
	},

	/**
	 * Disable auto-detection.
	 *
	 * @return {Object} Action object.
	 */
	disableAutoDetection() {
		return {
			type: ACTION_TYPES.DISABLE_AUTO_DETECTION,
		};
	},

	/**
	 * Start an API lookup.
	 *
	 * @param {string} lookupType Type of lookup (music, movie, book, venue).
	 * @return {Object} Action object.
	 */
	startApiLookup( lookupType ) {
		return {
			type: ACTION_TYPES.START_API_LOOKUP,
			lookupType,
		};
	},

	/**
	 * Receive API lookup results.
	 *
	 * @param {Array} results API results.
	 * @return {Object} Action object.
	 */
	receiveApiResults( results ) {
		return {
			type: ACTION_TYPES.RECEIVE_API_RESULTS,
			results,
		};
	},

	/**
	 * Handle API lookup error.
	 *
	 * @param {string} error Error message.
	 * @return {Object} Action object.
	 */
	apiLookupError( error ) {
		return {
			type: ACTION_TYPES.API_LOOKUP_ERROR,
			error,
		};
	},

	/**
	 * Clear API results.
	 *
	 * @return {Object} Action object.
	 */
	clearApiResults() {
		return {
			type: ACTION_TYPES.CLEAR_API_RESULTS,
		};
	},

	/**
	 * Set available kinds.
	 *
	 * @param {Array} kinds Available kind terms.
	 * @return {Object} Action object.
	 */
	setAvailableKinds( kinds ) {
		return {
			type: ACTION_TYPES.SET_AVAILABLE_KINDS,
			kinds,
		};
	},

	/**
	 * Mark store as initialized.
	 *
	 * @return {Object} Action object.
	 */
	setInitialized() {
		return {
			type: ACTION_TYPES.SET_INITIALIZED,
		};
	},

	/**
	 * Update the kind taxonomy term on the post.
	 *
	 * @param {string} kind Kind slug.
	 * @return {Function} Thunk action.
	 */
	updatePostKind( kind ) {
		return async ( { dispatch, registry } ) => {
			const { editPost } = registry.dispatch( editorStore );
			const { getEditedPostAttribute } = registry.select( editorStore );

			// Get available kinds to find the term ID.
			const kinds = registry.select( STORE_NAME ).getAvailableKinds();
			const kindTerm = kinds.find( ( k ) => k.slug === kind );

			if ( kindTerm ) {
				// Update the taxonomy term.
				await editPost( { kind: [ kindTerm.id ] } );
			}

			// Update local state.
			dispatch.setKind( kind );
		};
	},

	/**
	 * Update a kind meta field.
	 *
	 * @param {string} key   Meta key (without prefix).
	 * @param {*}      value Meta value.
	 * @return {Function} Thunk action.
	 */
	updateKindMeta( key, value ) {
		return async ( { registry } ) => {
			const { editPost } = registry.dispatch( editorStore );
			const metaKey = META_PREFIX + key;

			await editPost( {
				meta: {
					[ metaKey ]: value,
				},
			} );
		};
	},

	/**
	 * Perform an API lookup.
	 *
	 * @param {string} lookupType Type of lookup.
	 * @param {string} query      Search query.
	 * @return {Function} Thunk action.
	 */
	performApiLookup( lookupType, query ) {
		return async ( { dispatch } ) => {
			dispatch.startApiLookup( lookupType );

			try {
				const results = await apiFetch( {
					path: `/reactions-indieweb/v1/lookup/${ lookupType }?q=${ encodeURIComponent( query ) }`,
				} );

				dispatch.receiveApiResults( results );
			} catch ( error ) {
				dispatch.apiLookupError( error.message || 'Lookup failed' );
			}
		};
	},

	/**
	 * Initialize the store with current post data.
	 *
	 * @return {Function} Thunk action.
	 */
	initialize() {
		return async ( { dispatch, registry } ) => {
			// Fetch available kinds.
			try {
				const kinds = await apiFetch( { path: '/wp/v2/kind?per_page=100' } );
				dispatch.setAvailableKinds( kinds );
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Failed to fetch kinds:', error );
			}

			// Get current post's kind.
			const postKinds = registry.select( editorStore ).getEditedPostAttribute( 'kind' );
			const availableKinds = registry.select( STORE_NAME ).getAvailableKinds();

			if ( postKinds && postKinds.length > 0 && availableKinds.length > 0 ) {
				const currentKind = availableKinds.find( ( k ) => k.id === postKinds[ 0 ] );
				if ( currentKind ) {
					dispatch.setKind( currentKind.slug );
				}
			}

			dispatch.setInitialized();
		};
	},
};

/**
 * Store reducer.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Action object.
 * @return {Object} New state.
 */
function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case ACTION_TYPES.SET_KIND:
			return {
				...state,
				selectedKind: action.kind,
			};

		case ACTION_TYPES.SET_AUTO_DETECTED_KIND:
			return {
				...state,
				autoDetectedKind: action.kind,
			};

		case ACTION_TYPES.ENABLE_AUTO_DETECTION:
			return {
				...state,
				isAutoDetectionEnabled: true,
			};

		case ACTION_TYPES.DISABLE_AUTO_DETECTION:
			return {
				...state,
				isAutoDetectionEnabled: false,
			};

		case ACTION_TYPES.START_API_LOOKUP:
			return {
				...state,
				apiLookup: {
					isLoading: true,
					results: [],
					error: null,
					type: action.lookupType,
				},
			};

		case ACTION_TYPES.RECEIVE_API_RESULTS:
			return {
				...state,
				apiLookup: {
					...state.apiLookup,
					isLoading: false,
					results: action.results,
					error: null,
				},
			};

		case ACTION_TYPES.API_LOOKUP_ERROR:
			return {
				...state,
				apiLookup: {
					...state.apiLookup,
					isLoading: false,
					results: [],
					error: action.error,
				},
			};

		case ACTION_TYPES.CLEAR_API_RESULTS:
			return {
				...state,
				apiLookup: {
					isLoading: false,
					results: [],
					error: null,
					type: null,
				},
			};

		case ACTION_TYPES.SET_AVAILABLE_KINDS:
			return {
				...state,
				availableKinds: action.kinds,
			};

		case ACTION_TYPES.SET_INITIALIZED:
			return {
				...state,
				isInitialized: true,
			};

		default:
			return state;
	}
}

/**
 * Store selectors.
 *
 * @type {Object}
 */
const selectors = {
	/**
	 * Get the selected kind.
	 *
	 * @param {Object} state Store state.
	 * @return {string|null} Selected kind slug.
	 */
	getSelectedKind( state ) {
		return state.selectedKind;
	},

	/**
	 * Get the auto-detected kind.
	 *
	 * @param {Object} state Store state.
	 * @return {string|null} Auto-detected kind slug.
	 */
	getAutoDetectedKind( state ) {
		return state.autoDetectedKind;
	},

	/**
	 * Check if auto-detection is enabled.
	 *
	 * @param {Object} state Store state.
	 * @return {boolean} Whether auto-detection is enabled.
	 */
	isAutoDetectionEnabled( state ) {
		return state.isAutoDetectionEnabled;
	},

	/**
	 * Check if API lookup is loading.
	 *
	 * @param {Object} state Store state.
	 * @return {boolean} Whether lookup is in progress.
	 */
	isApiLoading( state ) {
		return state.apiLookup.isLoading;
	},

	/**
	 * Get API lookup results.
	 *
	 * @param {Object} state Store state.
	 * @return {Array} Lookup results.
	 */
	getApiResults( state ) {
		return state.apiLookup.results;
	},

	/**
	 * Get API lookup error.
	 *
	 * @param {Object} state Store state.
	 * @return {string|null} Error message.
	 */
	getApiError( state ) {
		return state.apiLookup.error;
	},

	/**
	 * Get API lookup type.
	 *
	 * @param {Object} state Store state.
	 * @return {string|null} Lookup type.
	 */
	getApiLookupType( state ) {
		return state.apiLookup.type;
	},

	/**
	 * Get available kinds.
	 *
	 * @param {Object} state Store state.
	 * @return {Array} Available kind terms.
	 */
	getAvailableKinds( state ) {
		return state.availableKinds;
	},

	/**
	 * Check if store is initialized.
	 *
	 * @param {Object} state Store state.
	 * @return {boolean} Whether store is initialized.
	 */
	isInitialized( state ) {
		return state.isInitialized;
	},

	/**
	 * Get a kind meta value from the post.
	 *
	 * @param {Object} state Store state.
	 * @param {string} key   Meta key (without prefix).
	 * @return {*} Meta value.
	 */
	getKindMeta: ( state, key ) => ( registry ) => {
		const meta = registry.select( editorStore ).getEditedPostAttribute( 'meta' ) || {};
		return meta[ META_PREFIX + key ] || '';
	},

	/**
	 * Get all kind meta values.
	 *
	 * @param {Object} state Store state.
	 * @return {Object} All meta values.
	 */
	getAllKindMeta: ( state ) => ( registry ) => {
		const meta = registry.select( editorStore ).getEditedPostAttribute( 'meta' ) || {};
		const kindMeta = {};

		Object.keys( meta ).forEach( ( key ) => {
			if ( key.startsWith( META_PREFIX ) ) {
				const shortKey = key.replace( META_PREFIX, '' );
				kindMeta[ shortKey ] = meta[ key ];
			}
		} );

		return kindMeta;
	},
};

/**
 * Create and export the store.
 */
export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

export default store;
