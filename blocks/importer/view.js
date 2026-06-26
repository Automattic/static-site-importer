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

	const shouldIncludeSiteFile = function ( path ) {
		const normalized = String( path || '' ).replace( /\\/g, '/' ).replace( /^\/+/, '' );
		const parts = normalized.split( '/' ).filter( Boolean );
		const name = parts.length ? parts[ parts.length - 1 ] : '';

		if ( name === '.DS_Store' ) {
			return false;
		}

		return ! ( name.toLowerCase() === 'result.json' && ! parts.includes( 'assets' ) );
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
			const path = record.path || uploadedFilePath( record.file );
			return ! /\.zip$/i.test( record.file.name || path || '' ) && shouldIncludeSiteFile( path );
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
			progress.hidden = false;
			progress.textContent = message;
		}
	};

	const previewUrl = function ( report ) {
		const preview = report && report.preview && typeof report.preview === 'object' ? report.preview : {};
		const playground = preview.playground && typeof preview.playground === 'object' ? preview.playground : {};
		return playground.blueprint_url || preview.url || '';
	};

	const openPendingPreviewWindow = function () {
		const openedWindow = window.open( 'about:blank', '_blank' );
		if ( openedWindow ) {
			openedWindow.opener = null;
		}

		return openedWindow;
	};

	const openPreview = function ( report, openedWindow ) {
		const url = previewUrl( report );
		if ( ! url ) {
			if ( openedWindow ) {
				openedWindow.close();
			}
			return false;
		}

		if ( openedWindow ) {
			openedWindow.location.href = url;
		} else {
			window.open( url, '_blank', 'noopener,noreferrer' );
		}

		return true;
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
				dropzone.classList.add( 'ssi-importer__dropzone--dragging' );
			} );
		} );

		[ 'dragleave', 'drop' ].forEach( function ( eventName ) {
			dropzone.addEventListener( eventName, function () {
				dropzone.classList.remove( 'ssi-importer__dropzone--dragging' );
			} );
		} );

		dropzone.addEventListener( 'drop', async function ( event ) {
			event.preventDefault();
			setDroppedFiles( root, await droppedFiles( event.dataTransfer ) );
		} );
	};

	const clickInput = function ( root, triggerSelector, inputSelector ) {
		const trigger = root.querySelector( triggerSelector );
		const input = root.querySelector( inputSelector );
		if ( ! trigger || ! input ) {
			return;
		}

		trigger.addEventListener( 'click', function () {
			input.click();
		} );
	};

	const bindUploadTriggers = function ( root ) {
		clickInput( root, '[data-static-site-importer-upload-files]', '[data-static-site-importer-source-files]' );
		clickInput( root, '[data-static-site-importer-upload-folder]', '[data-static-site-importer-source-directory]' );
		clickInput( root, '[data-static-site-importer-upload-figma]', '[data-static-site-importer-source-figma-file]' );
	};

	const submitFigmaFile = async function ( root, file ) {
		const restUrl = root.getAttribute( 'data-static-site-importer-figma-rest-url' );
		const nonce = root.getAttribute( 'data-static-site-importer-nonce' );
		const isCurrentSiteImport = root.getAttribute( 'data-static-site-importer-apply-to-current-site' ) === '1';
		const playgroundWindow = isCurrentSiteImport ? null : openPendingPreviewWindow();
		const formData = new FormData();
		formData.append( 'figma_file', file );
		formData.append( 'apply_to_current_site', isCurrentSiteImport ? '1' : '0' );
		formData.append( 'activate', isCurrentSiteImport ? '1' : '0' );
		formData.append( 'overwrite', isCurrentSiteImport ? '1' : '0' );

		showStatus( root, isCurrentSiteImport ? 'Importing Figma file to this site...' : 'Preparing Figma file for WordPress preview...' );

		try {
			const response = await fetch( restUrl, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
				},
				body: formData,
			} );
			const report = await response.json();
			setReport( root, report );
			if ( response.ok && isCurrentSiteImport && report.success ) {
				showStatus( root, 'Figma import complete.' );
			} else if ( response.ok && report.success && previewUrl( report ) ) {
				openPreview( report, playgroundWindow );
				showStatus( root, 'WordPress Playground opened.' );
			} else {
				if ( playgroundWindow ) {
					playgroundWindow.close();
				}
				showStatus( root, response.ok ? 'Figma import request complete.' : 'Figma import request failed.' );
			}
		} catch ( error ) {
			setReport( root, { success: false, error: { message: error.message } } );
			if ( playgroundWindow ) {
				playgroundWindow.close();
			}
			showStatus( root, 'Figma import request failed.' );
		}
	};

	roots.forEach( function ( root ) {
		bindDropzone( root );
		bindUploadTriggers( root );
		root.querySelectorAll( '[data-static-site-importer-source-files], [data-static-site-importer-source-directory]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				setDroppedFiles( root, [] );
			} );
		} );

		const figmaInput = root.querySelector( '[data-static-site-importer-source-figma-file]' );
		if ( figmaInput ) {
			figmaInput.addEventListener( 'change', function () {
				const file = figmaInput.files && figmaInput.files.length ? figmaInput.files[ 0 ] : null;
				if ( file ) {
					submitFigmaFile( root, file );
				}
			} );
		}

		const submit = root.querySelector( '[data-static-site-importer-submit]' );
		if ( ! submit ) {
			return;
		}

		submit.addEventListener( 'click', async function () {
			const form = root.querySelector( '[data-static-site-importer-form]' );
			const html = root.querySelector( '[data-static-site-importer-source-html]' );
			const uploadInputs = root.querySelectorAll( '[data-static-site-importer-source-files], [data-static-site-importer-source-directory]' );
			const provider = root.getAttribute( 'data-static-site-importer-provider' ) || '';
			const isCurrentSiteImport = root.getAttribute( 'data-static-site-importer-apply-to-current-site' ) === '1';
			const playgroundWindow = isCurrentSiteImport ? null : openPendingPreviewWindow();
			const source = {
				url: form ? form.getAttribute( 'data-static-site-importer-default-url' ) || '' : '',
				html: html ? html.value : '',
				files: await buildFiles( uploadInputs, root ),
				archive: await buildArchive( uploadInputs, root ),
			};

			if ( ! hasSource( source ) ) {
				if ( playgroundWindow ) {
					playgroundWindow.close();
				}
				setReport( root, { success: false, error: { message: 'Upload a website or paste HTML to start.' } } );
				showStatus( root, 'Upload a website or paste HTML to start.' );
				return;
			}

			showStatus( root, isCurrentSiteImport ? 'Importing to this site...' : 'Preparing WordPress preview...' );
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
						apply_to_current_site: isCurrentSiteImport,
						activate: isCurrentSiteImport,
						overwrite: isCurrentSiteImport,
					} ),
				} );
				const report = await response.json();
				setReport( root, report );
				if ( response.ok && isCurrentSiteImport && report.success ) {
					showStatus( root, 'Import complete.' );
				} else if ( response.ok && report.success && previewUrl( report ) ) {
					openPreview( report, playgroundWindow );
					showStatus( root, 'WordPress Playground opened.' );
				} else if ( response.ok && previewUrl( report ) ) {
					openPreview( report, playgroundWindow );
					showStatus( root, 'Preview opened.' );
				} else if ( response.ok && report.preview && report.preview.status === 'unavailable' ) {
					if ( playgroundWindow ) {
						playgroundWindow.close();
					}
					showStatus( root, report.preview.message || 'Preview unavailable: WP Codebox did not return a preview URL or Playground blueprint URL.' );
				} else {
					if ( playgroundWindow ) {
						playgroundWindow.close();
					}
					showStatus( root, response.ok && report.success ? 'Preview request complete.' : 'Preview request failed.' );
				}
			} catch ( error ) {
				setReport( root, { success: false, error: { message: error.message } } );
				if ( playgroundWindow ) {
					playgroundWindow.close();
				}
				showStatus( root, 'Preview request failed.' );
			} finally {
				submit.disabled = false;
			}
		} );
	} );
} )();
