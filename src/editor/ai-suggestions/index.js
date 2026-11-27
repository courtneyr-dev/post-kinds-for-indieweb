/**
 * Register the AI suggestions panel.
 *
 * @package
 * @since 1.11.0
 */

import { registerPlugin } from '@wordpress/plugins';
import AiSuggestionsPanel from './AiSuggestionsPanel';

registerPlugin( 'pkiw-ai-suggestions', { render: AiSuggestionsPanel } );
