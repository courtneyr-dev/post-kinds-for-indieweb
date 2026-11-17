import { useEffect } from '@wordpress/element';
import { date } from '@wordpress/date';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const FIRST_YEAR = 2000;

export default function Edit( { attributes, setAttributes } ) {
	const metrics = Array.isArray( attributes.metrics )
		? attributes.metrics
		: [];
	const currentYear = Number.parseInt( date( 'Y' ), 10 );
	const kinds = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'kind', {
				per_page: -1,
				hide_empty: false,
				context: 'view',
			} ) || [],
		[]
	);

	// C10: the block has no default year; insert writes the current site-local year.
	useEffect( () => {
		if ( ! attributes.year ) {
			setAttributes( { year: currentYear } );
		}
	}, [ attributes.year, currentYear, setAttributes ] );

	const kindOptions = [
		{
			label: __(
				'Choose a kind',
				'post-kinds-for-indieweb-in-block-themes'
			),
			value: '',
		},
		...kinds.map( ( term ) => ( { label: term.name, value: term.slug } ) ),
	];
	const yearOptions = [];
	for ( let year = currentYear; year >= FIRST_YEAR; year-- ) {
		yearOptions.push( { label: String( year ), value: String( year ) } );
	}

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Wrapped settings',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Kind',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ attributes.kind || '' }
						options={ kindOptions }
						onChange={ ( kind ) => setAttributes( { kind } ) }
					/>
					<SelectControl
						label={ __(
							'Year',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ String( attributes.year || currentYear ) }
						options={ yearOptions }
						onChange={ ( value ) =>
							setAttributes( {
								year: Number.parseInt( value, 10 ),
							} )
						}
					/>
					<TextControl
						label={ __(
							'Metric keys',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						help={ __(
							'Comma-separated, in display order. Leave empty for the default order.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ metrics.join( ', ' ) }
						onChange={ ( value ) =>
							setAttributes( {
								metrics: value
									.split( ',' )
									.map( ( item ) => item.trim() )
									.filter( Boolean ),
							} )
						}
					/>
					<RangeControl
						label={ __(
							'Top items',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						min={ 0 }
						max={ 12 }
						value={ attributes.topCount ?? 3 }
						onChange={ ( topCount ) =>
							setAttributes( { topCount } )
						}
					/>
					<TextControl
						label={ __(
							'Top items heading',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						help={ __(
							'Leave empty for the default heading.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ attributes.heading || '' }
						onChange={ ( heading ) => setAttributes( { heading } ) }
					/>
					<SelectControl
						label={ __(
							'Heading level',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ String( attributes.headingLevel || 2 ) }
						options={ [ 2, 3, 4 ].map( ( level ) => ( {
							label: `H${ level }`,
							value: String( level ),
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( {
								headingLevel: Number.parseInt( value, 10 ),
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="post-kinds-indieweb/wrapped"
				attributes={ { ...attributes, metrics } }
			/>
		</div>
	);
}
