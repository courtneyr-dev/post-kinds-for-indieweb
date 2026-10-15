/**
 * Missing-alt notice registration.
 *
 * @package
 * @since   1.9.0
 */

import { registerPlugin } from '@wordpress/plugins';

import MissingAltNotice from './MissingAltNotice';

registerPlugin( 'pkiw-missing-alt-notice', { render: MissingAltNotice } );
