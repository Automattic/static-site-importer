( function ( blocks, element, components ) {
	if ( ! blocks || ! element ) {
		return;
	}

	const el = element.createElement;

	blocks.registerBlockType( 'static-site-importer/importer', {
		edit() {
			return el(
				'div',
				{ className: 'ssi-importer ssi-importer--editor' },
				el( 'p', { className: 'ssi-importer__eyebrow' }, 'Static Site Importer' ),
				el( 'h2', { className: 'ssi-importer__title' }, 'Bring a site into WordPress' ),
				el( 'p', { className: 'ssi-importer__copy' }, 'Visitors paste a URL, upload site files, or add HTML. The block imports through Static Site Importer.' ),
				components && components.Notice
					? el( components.Notice, { status: 'info', isDismissible: false }, 'URL imports use the generic Static Site Importer provider hook before falling back to the built-in public URL fetcher.' )
					: null
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.components );
