( function ( blocks, element, components ) {
	if ( ! blocks || ! element ) {
		return;
	}

	const el = element.createElement;

	blocks.registerBlockType( 'static-site-importer/importer', {
		edit( props ) {
			const attributes = props.attributes || {};
			const applyToCurrentSite = Boolean( attributes.applyToCurrentSite );
			const setAttributes = typeof props.setAttributes === 'function' ? props.setAttributes : function () {};

			return el(
				'div',
				{ className: 'ssi-importer ssi-importer--editor' },
				el( 'p', { className: 'ssi-importer__eyebrow' }, 'Static Site Importer' ),
				el( 'h2', { className: 'ssi-importer__title' }, 'Bring a site into WordPress' ),
				el( 'p', { className: 'ssi-importer__copy' }, 'Visitors paste a URL, upload site files, or add HTML. The block imports through Static Site Importer.' ),
				components && components.Notice
					? el( components.Notice, { status: 'info', isDismissible: false }, 'URL imports use the generic Static Site Importer provider hook before falling back to the built-in public URL fetcher.' )
					: null,
				components && components.ToggleControl
					? el( components.ToggleControl, {
						label: 'Apply imports to this site',
						help: applyToCurrentSite ? 'Imports mutate this WordPress site.' : 'Imports create a preview when possible.',
						checked: applyToCurrentSite,
						onChange: function ( value ) {
							setAttributes( { applyToCurrentSite: Boolean( value ) } );
						},
					} )
					: null
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.components );
