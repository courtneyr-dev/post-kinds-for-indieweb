import {
	createRegistry,
	createReduxStore,
	RegistryProvider,
	useRegistry,
} from '@wordpress/data';
import {
	BlockEditorProvider,
	BlockInspector,
	BlockList,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { SlotFillProvider } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { store as postKindsStore } from '../src/editor/stores/post-kinds';

// The core/editor selectors and actions PKIW's editor code and
// PluginDocumentSettingPanel call. editPost merges meta the way core-data
// merges meta edits.
const editorStore = ( post ) =>
	createReduxStore( 'core/editor', {
		reducer: ( state = post, action ) =>
			action.type === 'EDIT_POST'
				? {
						...state,
						...action.edits,
						meta: { ...state.meta, ...action.edits.meta },
					}
				: state,
		selectors: {
			getCurrentPost: ( state ) => state,
			getCurrentPostId: ( state ) => state.id,
			getCurrentPostType: ( state ) => state.type,
			getEditedPostAttribute: ( state, key ) => state[ key ],
			isEditorPanelEnabled: () => true,
			isEditorPanelOpened: () => true,
		},
		actions: {
			editPost: ( edits ) => ( { type: 'EDIT_POST', edits } ),
			toggleEditorPanelOpened: () => ( { type: 'TOGGLE_PANEL' } ),
		},
	} );

export const draftPost = ( { kind = [], meta = {}, title = '' } = {} ) => ( {
	id: 1,
	type: 'post',
	status: 'draft',
	title,
	kind,
	meta,
} );

export function EditorContext( { post, children } ) {
	const parent = useRegistry();
	const registry = useMemo( () => {
		const next = createRegistry( {}, parent );
		next.register( blockEditorStore );
		next.register( editorStore( post ) );
		next.register( postKindsStore );
		return next;
	}, [ parent, post ] );
	return (
		<RegistryProvider value={ registry }>
			<SlotFillProvider>{ children }</SlotFillProvider>
		</RegistryProvider>
	);
}

const NO_BLOCKS = [];

export function EditorFrame( { initialBlocks = NO_BLOCKS, sidebar } ) {
	const [ blocks, setBlocks ] = useState( initialBlocks );
	const selection = useMemo( () => {
		const clientId = initialBlocks[ 0 ]?.clientId;
		return clientId
			? { selectionStart: { clientId }, selectionEnd: { clientId } }
			: undefined;
	}, [ initialBlocks ] );
	return (
		<BlockEditorProvider
			value={ blocks }
			selection={ selection }
			useSubRegistry={ false }
			onInput={ setBlocks }
			onChange={ setBlocks }
		>
			<div className="pkiw-story-editor-frame">
				<div className="editor-styles-wrapper">
					<BlockList />
				</div>
				<div className="pkiw-story-editor-inspector">
					{ sidebar ?? <BlockInspector /> }
				</div>
			</div>
		</BlockEditorProvider>
	);
}
