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

	const droppedFilesByRoot = new WeakMap();

	const selectedInputFiles = function ( inputs, root ) {
		const inputFiles = Array.prototype.slice.call( inputs || [] ).flatMap( function ( input ) {
			return input && input.files ? Array.prototype.slice.call( input.files ).map( function ( file ) {
				return { file, path: uploadedFilePath( file ) };
			} ) : [];
		} );
		const droppedFiles = root ? droppedFilesByRoot.get( root ) || [] : [];
		return inputFiles.concat( droppedFiles );
	};

	const readDirectoryEntries = function ( reader ) {
		return new Promise( function ( resolve, reject ) {
			const entries = [];
			const readBatch = function () {
				reader.readEntries( function ( batch ) {
					if ( ! batch.length ) {
						resolve( entries );
						return;
					}
					entries.push.apply( entries, batch );
					readBatch();
				}, reject );
			};
			readBatch();
		} );
	};

	const fileFromEntry = function ( entry ) {
		return new Promise( function ( resolve, reject ) {
			entry.file( resolve, reject );
		} );
	};

	const filesFromEntry = async function ( entry ) {
		if ( entry.isFile ) {
			const file = await fileFromEntry( entry );
			return [ { file, path: ( entry.fullPath || file.name || 'upload' ).replace( /^\//, '' ) } ];
		}

		if ( entry.isDirectory ) {
			const entries = await readDirectoryEntries( entry.createReader() );
			const nested = await Promise.all( entries.map( filesFromEntry ) );
			return nested.flat();
		}

		return [];
	};

	const droppedFiles = async function ( dataTransfer ) {
		const items = Array.prototype.slice.call( dataTransfer && dataTransfer.items ? dataTransfer.items : [] );
		const entries = items.map( function ( item ) {
			return typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null;
		} ).filter( Boolean );

		if ( entries.length ) {
			const nested = await Promise.all( entries.map( filesFromEntry ) );
			return nested.flat();
		}

		return Array.prototype.slice.call( dataTransfer && dataTransfer.files ? dataTransfer.files : [] ).map( function ( file ) {
			return { file, path: uploadedFilePath( file ) };
		} );
	};

	const setDroppedFiles = function ( root, files ) {
		droppedFilesByRoot.set( root, files );
		const dropzone = root.querySelector( '[data-static-site-importer-dropzone]' );
		if ( dropzone ) {
			if ( files.length ) {
				dropzone.setAttribute( 'data-static-site-importer-dropped-count', String( files.length ) );
			} else {
				dropzone.removeAttribute( 'data-static-site-importer-dropped-count' );
			}
		}
	};

	const buildFiles = async function ( inputs, root ) {
		const selectedFiles = selectedInputFiles( inputs, root );
		const files = selectedFiles.filter( function ( record ) {
			return ! /\.zip$/i.test( record.file.name || record.path || '' );
		} );
		return Promise.all( files.map( async function ( upload ) {
			const file = upload.file;
			const path = upload.path || uploadedFilePath( file );
			const output = {
				path,
				name: file.name || path,
				type: file.type || '',
				size: file.size || 0,
			};

			if ( /\.html?$/i.test( path ) || /^text\//i.test( file.type || '' ) ) {
				output.content = await fileToText( file );
			} else {
				output.content_base64 = await fileToBase64( file );
			}

			return output;
		} ) );
	};

	const buildArchive = async function ( inputs, root ) {
		const selectedFiles = selectedInputFiles( inputs, root );
		const archive = selectedFiles.find( function ( upload ) {
			return /\.zip$/i.test( upload.file.name || upload.path || '' );
		} );
		if ( ! archive ) {
			return null;
		}
		const file = archive.file;

		return {
			path: archive.path || file.name || 'website.zip',
			name: file.name || 'website.zip',
			type: file.type || 'application/zip',
			size: file.size || 0,
			content_base64: await fileToBase64( file ),
		};
	};

	const setReport = function ( root, report ) {
		const status = root.querySelector( '[data-static-site-importer-status]' );
		const reportEl = root.querySelector( '[data-static-site-importer-report]' );
		if ( status ) {
			status.hidden = false;
		}
		if ( reportEl ) {
			reportEl.hidden = false;
			reportEl.value = JSON.stringify( report, null, 2 );
		}
	};

	const showStatus = function ( root, message ) {
		const status = root.querySelector( '[data-static-site-importer-status]' );
		const progress = root.querySelector( '[data-static-site-importer-progress]' );
		if ( status && progress ) {
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

	const bindDropzone = function ( root ) {
		const dropzone = root.querySelector( '[data-static-site-importer-dropzone]' );
		if ( ! dropzone ) {
			return;
		}

		[ 'dragenter', 'dragover' ].forEach( function ( eventName ) {
			dropzone.addEventListener( eventName, function ( event ) {
				event.preventDefault();
				dropzone.classList.add( 'ssi-importer__upload-group--dragging' );
			} );
		} );

		[ 'dragleave', 'drop' ].forEach( function ( eventName ) {
			dropzone.addEventListener( eventName, function () {
				dropzone.classList.remove( 'ssi-importer__upload-group--dragging' );
			} );
		} );

		dropzone.addEventListener( 'drop', async function ( event ) {
			event.preventDefault();
			setDroppedFiles( root, await droppedFiles( event.dataTransfer ) );
		} );
	};

	roots.forEach( function ( root ) {
		bindDropzone( root );
		root.querySelectorAll( '[data-static-site-importer-source-files], [data-static-site-importer-source-directory]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				setDroppedFiles( root, [] );
			} );
		} );

		const submit = root.querySelector( '[data-static-site-importer-submit]' );
		if ( ! submit ) {
			return;
		}

		submit.addEventListener( 'click', async function () {
			const form = root.querySelector( '[data-static-site-importer-form]' );
			const html = root.querySelector( '[data-static-site-importer-source-html]' );
			const uploadInputs = root.querySelectorAll( '[data-static-site-importer-source-files], [data-static-site-importer-source-directory]' );
			const provider = root.getAttribute( 'data-static-site-importer-provider' ) || '';
			const applyToCurrentSite = root.getAttribute( 'data-static-site-importer-apply-to-current-site' ) === '1';
			const source = {
				url: form ? form.getAttribute( 'data-static-site-importer-default-url' ) || '' : '',
				html: html ? html.value : '',
				files: await buildFiles( uploadInputs, root ),
				archive: await buildArchive( uploadInputs, root ),
			};

			if ( ! hasSource( source ) ) {
				setReport( root, { success: false, error: { message: 'Upload a website or paste HTML to start.' } } );
				showStatus( root, 'Upload a website or paste HTML to start.' );
				return;
			}

			showStatus( root, applyToCurrentSite ? 'Importing to this site...' : 'Creating WordPress preview...' );
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
						apply_to_current_site: applyToCurrentSite,
						activate: applyToCurrentSite,
						overwrite: applyToCurrentSite,
					} ),
				} );
				const report = await response.json();
				setReport( root, report );
				setPreviewLink( root, report );
				if ( response.ok && applyToCurrentSite && report.success ) {
					showStatus( root, 'Import complete.' );
				} else if ( response.ok && previewUrl( report ) ) {
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
