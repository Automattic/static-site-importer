/* global FileReader */

( function () {
	const roots = document.querySelectorAll( '[data-static-site-importer]' );

	const fileToBase64 = function ( file ) {
		return new Promise( function ( resolve, reject ) {
			const reader = new FileReader();
			reader.onload = function () {
				const result = typeof reader.result === 'string' ? reader.result : '';
				resolve( result.replace( /^data:[^,]*,/, '' ) );
			};
			reader.onerror = function () {
				reject( reader.error || new Error( 'Unable to read uploaded file.' ) );
			};
			reader.readAsDataURL( file );
		} );
	};

	const fileToText = function ( file ) {
		return typeof file.text === 'function' ? file.text() : new Promise( function ( resolve, reject ) {
			const reader = new FileReader();
			reader.onload = function () {
				resolve( typeof reader.result === 'string' ? reader.result : '' );
			};
			reader.onerror = function () {
				reject( reader.error || new Error( 'Unable to read uploaded file.' ) );
			};
			reader.readAsText( file );
		} );
	};

	const uploadedFilePath = function ( file ) {
		return file.webkitRelativePath || file.name || 'upload';
	};

	const buildFiles = async function ( input ) {
		const selectedFiles = input && input.files ? Array.prototype.slice.call( input.files ) : [];
		return Promise.all( selectedFiles.map( async function ( file ) {
			const path = uploadedFilePath( file );
			const record = {
				path,
				name: file.name || path,
				type: file.type || '',
				size: file.size || 0,
			};

			if ( /\.html?$/i.test( path ) || /^text\//i.test( file.type || '' ) ) {
				record.content = await fileToText( file );
			} else {
				record.content_base64 = await fileToBase64( file );
			}

			return record;
		} ) );
	};

	const setReport = function ( root, report ) {
		const reportEl = root.querySelector( '[data-static-site-importer-report]' );
		if ( reportEl ) {
			reportEl.hidden = false;
			reportEl.value = JSON.stringify( report, null, 2 );
		}
	};

	const showStatus = function ( root, message ) {
		const status = root.querySelector( '[data-static-site-importer-status]' );
		const progress = root.querySelector( '[data-static-site-importer-progress]' );
		if ( status ) {
			status.hidden = false;
		}
		if ( progress ) {
			progress.textContent = message;
		}
	};

	const hasSource = function ( source ) {
		return Boolean(
			( source.files && source.files.length > 0 ) ||
			( source.html && source.html.trim() ) ||
			( source.url && source.url.trim() )
		);
	};

	roots.forEach( function ( root ) {
		const submit = root.querySelector( '[data-static-site-importer-submit]' );
		if ( ! submit ) {
			return;
		}

		submit.addEventListener( 'click', async function () {
			const sourceUrl = root.querySelector( '[data-static-site-importer-source-url]' );
			const html = root.querySelector( '[data-static-site-importer-source-html]' );
			const files = root.querySelector( '[data-static-site-importer-source-files]' );
			const provider = root.getAttribute( 'data-static-site-importer-provider' ) || '';
			const source = {
				url: sourceUrl ? sourceUrl.value : '',
				html: html ? html.value : '',
				files: await buildFiles( files ),
			};

			if ( ! hasSource( source ) ) {
				showStatus( root, 'Add a website URL, site files, or raw HTML to start.' );
				return;
			}

			showStatus( root, 'Importing site into WordPress...' );
			submit.disabled = true;

			try {
				const restUrl = root.getAttribute( 'data-static-site-importer-rest-url' );
				const nonce = root.getAttribute( 'data-static-site-importer-nonce' );
				const response = await fetch( restUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( {
						provider,
						source,
						activate: true,
						overwrite: true,
					} ),
				} );
				const report = await response.json();
				setReport( root, report );
				showStatus( root, response.ok && report.success ? 'Import complete.' : 'Import failed.' );
			} catch ( error ) {
				setReport( root, { success: false, error: { message: error.message } } );
				showStatus( root, 'Import request failed.' );
			} finally {
				submit.disabled = false;
			}
		} );
	} );
} )();
