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

	const buildArchive = async function ( input ) {
		const file = input && input.files && input.files.length ? input.files[ 0 ] : null;
		if ( ! file || ! /\.zip$/i.test( file.name || '' ) ) {
			return null;
		}

		return {
			path: file.name || 'website.zip',
			name: file.name || 'website.zip',
			type: file.type || 'application/zip',
			size: file.size || 0,
			content_base64: await fileToBase64( file ),
		};
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

	const previewUrl = function ( report ) {
		const preview = report && report.preview && typeof report.preview === 'object' ? report.preview : {};
		const playground = preview.playground && typeof preview.playground === 'object' ? preview.playground : {};
		return preview.url || playground.blueprint_url || '';
	};

	const setPreviewLink = function ( root, report ) {
		const wrap = root.querySelector( '[data-static-site-importer-preview-link-wrap]' );
		const link = root.querySelector( '[data-static-site-importer-preview-link]' );

		if ( ! wrap || ! link ) {
			return;
		}

		const url = previewUrl( report );
		if ( url ) {
			link.href = url;
			wrap.hidden = false;
		} else {
			link.removeAttribute( 'href' );
			wrap.hidden = true;
		}
	};

	const hasSource = function ( source ) {
		return Boolean(
			( source.files && source.files.length > 0 ) ||
			( source.archive && source.archive.content_base64 ) ||
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
			const archive = root.querySelector( '[data-static-site-importer-source-archive]' );
			const provider = root.getAttribute( 'data-static-site-importer-provider' ) || '';
			const source = {
				url: sourceUrl ? sourceUrl.value : '',
				html: html ? html.value : '',
				files: await buildFiles( files ),
				archive: await buildArchive( archive ),
			};

			if ( ! hasSource( source ) ) {
				showStatus( root, 'Add a website URL, site directory, ZIP archive, or raw HTML to start.' );
				return;
			}

			showStatus( root, 'Creating WordPress preview...' );
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
					} ),
				} );
				const report = await response.json();
				setReport( root, report );
				setPreviewLink( root, report );
				if ( response.ok && previewUrl( report ) ) {
					showStatus( root, 'Preview ready.' );
				} else if ( response.ok && report.preview && report.preview.status === 'unavailable' ) {
					showStatus( root, report.preview.message || 'Preview unavailable: WP Codebox did not return a preview URL or Playground blueprint URL.' );
				} else {
					showStatus( root, response.ok && report.success ? 'Preview request complete.' : 'Preview request failed.' );
				}
			} catch ( error ) {
				setReport( root, { success: false, error: { message: error.message } } );
				setPreviewLink( root, null );
				showStatus( root, 'Preview request failed.' );
			} finally {
				submit.disabled = false;
			}
		} );
	} );
} )();
