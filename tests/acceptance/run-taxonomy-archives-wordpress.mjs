import { cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const releasePackageMode = 'true' === process.env.SSI_TAXONOMY_USE_RELEASED_PACKAGE;
const transformerPackage = 'automattic/blocks-engine-php-transformer';
const engineRoot = releasePackageMode
	? null
	: resolve( process.env.BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT ?? join( root, '../blocks-engine-2468/php-transformer' ) );
const installedTransformerRoot = join( root, 'vendor/automattic/blocks-engine-php-transformer' );
const cli = process.env.WP_CODEBOX_CLI ?? '/home/chubes/.local/bin/wp-codebox';
const evidenceRoot = resolve( process.env.SSI_TAXONOMY_EVIDENCE ?? join( root, 'artifacts/taxonomy-archives' ) );
const editorMarker = 'SSI Gutenberg category template edit persisted';
const editorReloadMarker = 'SSI Gutenberg category template reload persisted';
const editorTemplateUrl = '/wp-admin/site-editor.php?p=%2Fwp_template%2Ftaxonomy-archive-acceptance%2F%2Fcategory-personal&canvas=edit';

let releasePackageIdentity = null;
if ( releasePackageMode ) {
	const composerLock = JSON.parse( readFileSync( join( root, 'composer.lock' ), 'utf8' ) );
	const composerManifest = JSON.parse( readFileSync( join( root, 'composer.json' ), 'utf8' ) );
	const lockPackage = ( composerLock.packages ?? [] ).find( item => item.name === transformerPackage );
	const vendorVersion = readFileSync( join( installedTransformerRoot, 'VERSION' ), 'utf8' ).trim();
	const expectedVersion = process.env.SSI_TAXONOMY_RELEASE_VERSION;
	if ( ! lockPackage || ! expectedVersion || vendorVersion !== expectedVersion || lockPackage.version !== `v${ expectedVersion }` || composerManifest.require?.[ transformerPackage ] !== expectedVersion || lockPackage.source?.reference !== lockPackage.dist?.reference ) {
		throw new Error( 'Release-package proof requires the expected installed and locked immutable Blocks Engine PHP transformer package.' );
	}
	releasePackageIdentity = {
		package: transformerPackage,
		version: lockPackage.version,
		sourceReference: lockPackage.source.reference,
		distReference: lockPackage.dist.reference,
		distUrl: lockPackage.dist.url,
	};
} else if ( ! statSync( join( engineRoot, 'src/WordPressSitePlan/TaxonomyProjection.php' ), { throwIfNoEntry: false } )?.isFile() ) {
	throw new Error( 'Set BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT to the paired producer candidate source checkout.' );
}
mkdirSync( evidenceRoot, { recursive: true } );
if ( releasePackageIdentity ) {
	writeFileSync( join( evidenceRoot, 'release-package-identity.json' ), JSON.stringify( releasePackageIdentity, null, 2 ) );
}
const sessionDir = mkdtempSync( join( tmpdir(), 'ssi-taxonomy-wordpress-' ) );
const workloadFile = join( sessionDir, 'workload.json' );
const runtimePluginRoot = releasePackageMode ? join( sessionDir, 'static-site-importer' ) : root;
if ( releasePackageMode ) {
	cpSync( root, runtimePluginRoot, {
		recursive: true,
		filter: sourcePath => {
			const firstPathSegment = relative( root, sourcePath ).split( /[\\/]/ )[0];
			return ! [ '.git', 'node_modules', 'artifacts' ].includes( firstPathSegment );
		},
	} );
}
const editorTemplateId = 'taxonomy-archive-acceptance//category-personal';
const mounts = [
	{ source: runtimePluginRoot, target: '/wordpress/wp-content/plugins/static-site-importer', mode: 'readonly' },
];
if ( engineRoot ) {
	mounts.push( { source: engineRoot, target: '/wordpress/wp-content/plugins/blocks-engine-candidate', mode: 'readonly' } );
}
const blueprintConsts = { SSI_TAXONOMY_DISPOSABLE_TEST: true };
if ( releasePackageIdentity ) {
	blueprintConsts.SSI_TAXONOMY_RELEASE_PACKAGE = true;
	blueprintConsts.SSI_TAXONOMY_RELEASE_VERSION = releasePackageIdentity.version.replace( /^v/, '' );
	blueprintConsts.SSI_TAXONOMY_RELEASE_REFERENCE = releasePackageIdentity.sourceReference;
}
const blueprint = { steps: [ { step: 'defineWpConfigConsts', consts: blueprintConsts } ] };
const releasePackageProofInPhp = releasePackageMode
	? `require_once '/wordpress/wp-content/plugins/static-site-importer/tests/acceptance/taxonomy-release-package-proof.php';
$packageProof = ssi_taxonomy_release_package_proof();
echo 'SSI-TAXONOMY-RELEASE-PACKAGE:' . wp_json_encode($packageProof) . "\n";
`
	: '';
const packageProofFrom = stdout => {
	const line = String( stdout ?? '' ).split( '\n' ).find( row => row.startsWith( 'SSI-TAXONOMY-RELEASE-PACKAGE:' ) );
	if ( ! line ) return null;
	try {
		return JSON.parse( line.slice( 'SSI-TAXONOMY-RELEASE-PACKAGE:'.length ) );
	} catch {
		return null;
	}
};
const packageProofMatchesPin = proof => ! releasePackageMode || (
	proof?.package === releasePackageIdentity.package &&
	proof?.version === releasePackageIdentity.version &&
	proof?.source_reference === releasePackageIdentity.sourceReference
);
const editorSaveScript = `window.__taxonomyEditorSave = (async () => {
const id = '${ editorTemplateId }';
const marker = '${ editorMarker }';
const core = wp.data.resolveSelect('core');
const dispatch = wp.data.dispatch('core');
let template;
for (let attempt = 0; attempt < 4; attempt++) {
try { template = await core.getEntityRecord('postType', 'wp_template', id); } catch {}
if (template?.content?.raw) break;
await new Promise(resolve => setTimeout(resolve, 750));
}
if (!template || !template.content?.raw) throw new Error('The category template did not resolve in the WordPress editor data store.');
const blocks = wp.blocks.parse(template.content.raw);
blocks.push(wp.blocks.createBlock('core/paragraph', { content: marker }));
dispatch.editEntityRecord('postType', 'wp_template', id, { content: wp.blocks.serialize(blocks) });
const saved = await dispatch.saveEditedEntityRecord('postType', 'wp_template', id);
if (!saved?.content?.raw?.includes(marker)) throw new Error('The category template edit was not saved by the WordPress editor data store.');
const invalid = blocks.filter(block => !wp.blocks.validateBlock(block)[0]).map(block => block.name);
if (invalid.length) throw new Error('The saved category template contains invalid blocks: ' + invalid.join(', '));
const proof = document.createElement('pre');
proof.id = 'ssi-taxonomy-editor-save-proof';
proof.textContent = JSON.stringify({ id, marker, blockCount: blocks.length, blockEditorStore: Boolean(wp.data.select('core/editor')), siteEditorStore: Boolean(wp.data.select('core/edit-site')) });
document.body.append(proof);
console.log('SSI-TAXONOMY-EDITOR-SAVED', JSON.stringify({ id, marker, blockCount: blocks.length }));
return true;
})().catch(error => { document.title = 'SSI-TAXONOMY-EDITOR-ERROR:' + error.message; throw error; });`;
const editorReloadScript = `window.__taxonomyEditorReload = (async () => {
const id = '${ editorTemplateId }';
const marker = '${ editorMarker }';
const reloadMarker = '${ editorReloadMarker }';
const dispatch = wp.data.dispatch('core');
let template;
for (let attempt = 0; attempt < 4; attempt++) {
try { template = await wp.data.resolveSelect('core').getEntityRecord('postType', 'wp_template', id); } catch {}
if (template?.content?.raw?.includes(marker)) break;
wp.data.dispatch('core').invalidateResolution('getEntityRecord', ['postType', 'wp_template', id]);
await new Promise(resolve => setTimeout(resolve, 750));
}
const persisted = Boolean(template?.content?.raw?.includes(marker));
if (!persisted) throw new Error('The category template marker did not survive a fresh editor reload.');
const blocks = wp.blocks.parse(template.content.raw);
const paragraph = blocks.find(block => block.name === 'core/paragraph' && block.attributes.content.includes(marker));
if (!paragraph) throw new Error('The reloaded category template edit was not available to Gutenberg.');
paragraph.attributes.content = marker + ' ' + reloadMarker;
dispatch.editEntityRecord('postType', 'wp_template', id, { content: wp.blocks.serialize(blocks) });
const saved = await dispatch.saveEditedEntityRecord('postType', 'wp_template', id);
if (!saved?.content?.raw?.includes(reloadMarker)) throw new Error('The reloaded Gutenberg template edit did not save.');
return true;
})().catch(error => { document.title = 'SSI-TAXONOMY-EDITOR-RELOAD-ERROR:' + error.message; throw error; });`;
const baseArchiveHttpProbe = `(() => {
const text = document.body.innerText;
const next = document.querySelector('.wp-block-query-pagination-next');
const links = [...document.querySelectorAll('.wp-block-post-title a')].map(link => link.textContent.trim());
const proof = { heading: Boolean(document.querySelector('h1')?.textContent.includes('Personal')), addedPost: text.includes('Added after import'), sourceOrder: links.indexOf('Story 12') >= 0 && links.indexOf('Story 12') < links.indexOf('Story 11'), unrelatedExcluded: !text.includes('Outside the archive'), sharedHeader: text.includes('Shared source header'), sharedFooter: text.includes('Shared source footer'), nextHref: next?.getAttribute('href') || '' };
document.documentElement.dataset.ssiTaxonomyBaseHttpProof = JSON.stringify(proof);
if (!proof.heading || !proof.addedPost || !proof.sourceOrder || !proof.unrelatedExcluded || !proof.sharedHeader || !proof.sharedFooter || !proof.nextHref.includes('/writing/category/personal/page/2/')) throw new Error('Native source archive base HTTP proof failed: ' + JSON.stringify(proof));
console.log('SSI-TAXONOMY-BASE-HTTP-PROOF', JSON.stringify(proof));
})();`;
const pageTwoArchiveHttpProbe = `(async () => {
const text = document.body.innerText;
const previous = document.querySelector('.wp-block-query-pagination-previous');
const proof = { title: document.title, pageTwo: document.body.classList.contains('paged-2'), secondPageMember: text.includes('Story 2'), previousPage: Boolean(previous && previous.textContent.includes('Previous Page')), previousHref: previous?.getAttribute('href') || '', firstPageExcluded: !text.includes('Story 12'), unrelatedExcluded: !text.includes('Outside the archive') };
document.documentElement.dataset.ssiTaxonomyPageTwoHttpProof = JSON.stringify(proof);
if (!proof.title.includes('Page 2') || !proof.pageTwo || !proof.secondPageMember || !proof.previousPage || !proof.previousHref.includes('/writing/category/personal/') || !proof.firstPageExcluded || !proof.unrelatedExcluded) throw new Error('Native source archive page-2 HTTP proof failed: ' + JSON.stringify(proof));
console.log('SSI-TAXONOMY-PAGE-TWO-HTTP-PROOF', JSON.stringify(proof));
const paths = ['/writing/category/personal/page/0/', '/writing/category/personal/page/-2/', '/writing/category/personal/page/1000000/'];
const responses = await Promise.all(paths.map(async path => {
const original = await fetch(path, { cache: 'no-store', redirect: 'manual' });
const originalLocation = original.headers.get('location') || '';
const finalResponse = await fetch(path, { cache: 'no-store', redirect: 'follow' });
const body = await finalResponse.text();
return {
requested_path: path,
original_status: original.status,
original_location: originalLocation,
final_status: finalResponse.status,
final_url: finalResponse.url,
final_path: new URL(finalResponse.url || path, window.location.origin).pathname,
taxonomy_archive_rendered: body.includes('Story 12') || body.includes('Added after import')
};
}));
const invalidProof = { schema: 'ssi-taxonomy/invalid-paged-routes/v1', responses };
const invalidRecord = document.createElement('pre');
invalidRecord.id = 'ssi-taxonomy-invalid-paged-http-proof';
invalidRecord.textContent = JSON.stringify(invalidProof);
document.body.append(invalidRecord);
document.documentElement.dataset.ssiTaxonomyInvalidPagedHttpProof = JSON.stringify(invalidProof);
console.log('SSI-TAXONOMY-INVALID-PAGED-HTTP-PROOF', JSON.stringify(invalidProof));
if (responses.some(response => response.original_status >= 200 && response.original_status < 300 || response.final_status !== 404 || response.taxonomy_archive_rendered)) throw new Error('Invalid taxonomy page numbers must retain invalid native HTTP semantics: ' + JSON.stringify(invalidProof));
})().catch(error => { document.title = 'SSI-TAXONOMY-PAGE-TWO-ERROR:' + error.message; throw error; });`;
const staticExportPageTwoProbe = `(() => {
const text = document.body.innerText;
const previous = document.querySelector('.wp-block-query-pagination-previous');
const proof = { title: document.title, secondPageMember: text.includes('Story 2'), previousPage: Boolean(previous && previous.textContent.includes('Previous Page')), previousHref: previous?.getAttribute('href') || '', firstPageExcluded: !text.includes('Story 12'), unrelatedExcluded: !text.includes('Outside the archive'), sharedHeader: text.includes('Shared source header'), sharedFooter: text.includes('Shared source footer') };
document.documentElement.dataset.ssiStaticExportPageTwoProof = JSON.stringify(proof);
if (!proof.title.includes('Personal') || !proof.secondPageMember || !proof.previousPage || !proof.previousHref.includes('/writing/category/personal/') || !proof.firstPageExcluded || !proof.unrelatedExcluded || !proof.sharedHeader || !proof.sharedFooter) throw new Error('Served static export page-2 HTTP proof failed: ' + JSON.stringify(proof));
console.log('SSI-TAXONOMY-STATIC-EXPORT-PAGE-TWO-PROOF', JSON.stringify(proof));
})();`;
const nativeReimportBaseProbe = `(() => {
const text = document.body.innerText;
const next = document.querySelector('.wp-block-query-pagination-next');
const members = [...document.querySelectorAll('.wp-block-post-title a')].map(link => link.textContent.trim());
const proof = { heading: Boolean(document.querySelector('h1')?.textContent.includes('Personal')), memberCount: members.length, hasStory12: members.includes('Story 12'), hasStory11: members.includes('Story 11'), excludesUnrelated: !text.includes('Outside the archive'), nextHref: next?.getAttribute('href') || '' };
document.documentElement.dataset.ssiNativeReimportBaseProof = JSON.stringify(proof);
if (!proof.heading || proof.memberCount !== 10 || !proof.hasStory12 || !proof.hasStory11 || !proof.excludesUnrelated || !proof.nextHref.includes('/category/personal/page/2/')) throw new Error('Second-site native taxonomy base archive proof failed: ' + JSON.stringify(proof));
console.log('SSI-TAXONOMY-SECOND-SITE-NATIVE-BASE-PROOF', JSON.stringify(proof));
})();`;
const nativeReimportPageTwoProbe = `(() => {
const text = document.body.innerText;
const previous = document.querySelector('.wp-block-query-pagination-previous');
const proof = { heading: Boolean(document.querySelector('h1')?.textContent.includes('Personal')), memberCount: document.querySelectorAll('.wp-block-post-title a').length, story10: text.includes('Story 10'), addedPost: text.includes('Added after import'), excludesStory12: !text.includes('Story 12'), previousHref: previous?.getAttribute('href') || '' };
document.documentElement.dataset.ssiNativeReimportPageTwoProof = JSON.stringify(proof);
if (!proof.heading || proof.memberCount !== 2 || !proof.story10 || !proof.addedPost || !proof.excludesStory12 || !proof.previousHref.includes('/category/personal/')) throw new Error('Second-site native taxonomy page-2 archive proof failed: ' + JSON.stringify(proof));
console.log('SSI-TAXONOMY-SECOND-SITE-NATIVE-PAGE-TWO-PROOF', JSON.stringify(proof));
})();`;
const editorVerification = `${ releasePackageMode ? "require_once '/wordpress/wp-content/plugins/static-site-importer/static-site-importer.php';" : engineRoot ? "if (!function_exists('blocks_engine_php_transformer_convert_format')) { require_once '/wordpress/wp-content/plugins/blocks-engine-candidate/php-transformer.php'; }" : '' }
$template = get_block_template(get_stylesheet() . '//category-personal');
$persisted = $template instanceof WP_Block_Template && str_contains($template->content, '${editorMarker}');
$reloaded = $template instanceof WP_Block_Template && str_contains($template->content, '${editorReloadMarker}');
${ releasePackageProofInPhp }
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-theme-exporter.php';
$export = Static_Site_Importer_Theme_Exporter::export_theme(array('theme_slug' => get_stylesheet()));
$exportError = is_wp_error($export) ? array('code' => $export->get_error_code(), 'message' => $export->get_error_message()) : null;
$artifact = is_array($export) ? ($export['website_artifact'] ?? array()) : array();
$files = array_column($artifact['files'] ?? array(), 'content', 'path');
$archivePath = 'website/writing/category/personal/index.html';
$nextPath = 'website/writing/category/personal/page/2/index.html';
$archive = (string) ($files[$archivePath] ?? '');
$nextArchive = (string) ($files[$nextPath] ?? '');
$exported = !is_wp_error($export) && 1 === (int) ($artifact['report']['taxonomy_archive_count'] ?? 0) && 2 === (int) ($artifact['report']['taxonomy_archive_page_count'] ?? 0) && str_contains($archive, 'Personal') && str_contains($archive, 'Story 12') && str_contains($archive, 'Added after import') && str_contains($archive, '${editorReloadMarker}') && str_contains($archive, 'writing/category/personal/page/2/') && str_contains($archive, 'href="/story-12/"') && str_contains($nextArchive, 'Story 2') && str_contains($nextArchive, 'Previous Page') && !str_contains($nextArchive, 'Next Page') && !str_contains($nextArchive, 'Outside the archive') && str_contains($nextArchive, 'href="../../../../../style.css"') && str_contains($nextArchive, 'href="/story-2/"');
$result = array('schema' => 'ssi-taxonomy/editor-template-persistence/v1', 'template_id' => get_stylesheet() . '//category-personal', 'persisted' => $persisted, 'reloaded' => $reloaded, 'exported' => $exported, 'archive_path' => $archivePath, 'archive_page_count' => $artifact['report']['taxonomy_archive_page_count'] ?? 0, 'archive_bytes' => strlen($archive), 'archive_sha256' => hash('sha256', $archive), 'archive_html' => $archive, 'next_page_path' => $nextPath, 'next_page_html' => $nextArchive, 'website_artifact' => $artifact, 'export_error' => $exportError, 'bridge_available' => function_exists('blocks_engine_php_transformer_convert_format'));
echo wp_json_encode($result) . "\n";
if (!$persisted || !$reloaded || !$exported) { throw new RuntimeException('The Gutenberg category template edit/save/reload or native archive export did not persist.'); }`;
const workload = {
	schema: 'wp-codebox/wordpress-workload-run/v1',
	wordpress_version: process.env.SSI_TAXONOMY_WORDPRESS_VERSION ?? '7.1',
	blueprint,
	mounts,
	steps: [
		{ command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/taxonomy-archives-wordpress.php' ) }` ] },
		{
			command: 'wordpress.browser-page-load',
			args: [ `url=${ editorTemplateUrl }`, 'auth=wordpress-admin', 'wait-for=load', `script=${ editorSaveScript }`, 'capture=html,console,errors,network,screenshot', 'duration=12s', 'timeout=120s' ],
		},
		{ command: 'wordpress.browser-page-load', args: [ `url=${ editorTemplateUrl }`, 'auth=wordpress-admin', 'wait-for=load', `script=${ editorReloadScript }`, 'capture=html,console,errors,screenshot', 'duration=8s', 'timeout=120s' ] },
		{ command: 'wordpress.run-php', args: [ `code=${ editorVerification }` ] },
		{ command: 'wordpress.browser-page-load', args: [ 'url=/writing/category/personal/', 'wait-for=domcontentloaded', `script=${ baseArchiveHttpProbe }`, 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
		{ command: 'wordpress.browser-page-load', args: [ 'url=/writing/category/personal/page/2/', 'wait-for=domcontentloaded', `script=${ pageTwoArchiveHttpProbe }`, 'capture=html,console,errors,network,screenshot', 'network-policy=block', 'duration=3s' ] },
	],
};
writeFileSync( workloadFile, JSON.stringify( workload, null, 2 ) );
writeFileSync( join( evidenceRoot, 'workload.json' ), JSON.stringify( workload, null, 2 ) );

const startedAt = Date.now();
const command = spawnSync( cli, [ 'run-wordpress-workload', '--input-file', workloadFile, '--artifacts', join( evidenceRoot, 'artifacts' ), '--format=json' ], {
	encoding: 'utf8',
	maxBuffer: 64 * 1024 * 1024,
	timeout: 20 * 60 * 1000,
} );
if ( command.error ) throw command.error;
let result;
try {
	result = JSON.parse( command.stdout );
} catch ( error ) {
	writeFileSync( join( evidenceRoot, 'stdout.log' ), command.stdout ?? '' );
	writeFileSync( join( evidenceRoot, 'stderr.log' ), command.stderr ?? '' );
	throw new Error( `WP Codebox returned non-JSON output: ${ error.message }` );
}
writeFileSync( join( evidenceRoot, 'workload.json' ), JSON.stringify( workload, null, 2 ) );
writeFileSync( join( evidenceRoot, 'result.json' ), JSON.stringify( result, null, 2 ) );
writeFileSync( join( evidenceRoot, 'stdout.log' ), command.stdout ?? '' );
writeFileSync( join( evidenceRoot, 'stderr.log' ), command.stderr ?? '' );
const phpStep = ( result.executions ?? [] ).find( step => 'wordpress.run-php' === step.command );
const primaryPackageProof = packageProofFrom( phpStep?.stdout );
const editorSteps = ( result.executions ?? [] ).filter( step => 'wordpress.browser-page-load' === step.command );
const editorSaveStep = editorSteps[0];
const editorReloadStep = editorSteps[1];
const editorVerifyStep = ( result.executions ?? [] ).filter( step => 'wordpress.run-php' === step.command )[1];
const browserStep = editorSteps[2];
const pageTwoBrowserStep = editorSteps[3];
const invalidPagedBrowserStep = pageTwoBrowserStep;
let editorVerify;
try {
	const lines = String( editorVerifyStep?.stdout ?? '' ).split( '\n' );
	editorVerify = JSON.parse( lines.find( line => line.includes( 'editor-template-persistence' ) ) ?? '{}' );
} catch {
	editorVerify = {};
}
if ( editorVerify.exported && 'string' === typeof editorVerify.archive_html ) {
	writeFileSync( join( evidenceRoot, 'exported-category-archive.html' ), editorVerify.archive_html );
	writeFileSync( join( evidenceRoot, 'exported-category-archive-page-2.html' ), editorVerify.next_page_html );
	if ( editorVerify.website_artifact ) {
		writeFileSync( join( evidenceRoot, 'exported-website-artifact.json' ), JSON.stringify( editorVerify.website_artifact, null, 2 ) );
	}
}
let browser;
try {
	browser = JSON.parse( browserStep?.stdout ?? '{}' );
} catch {
	browser = {};
}
const snapshots = [];
const visit = directory => {
	for ( const entry of readdirSync( directory, { withFileTypes: true } ) ) {
		const path = join( directory, entry.name );
		if ( entry.isDirectory() ) visit( path );
		else if ( path.includes( '/files/browser/' ) && 'snapshot.html' === entry.name ) snapshots.push( path );
	}
};
const artifactRoot = join( evidenceRoot, 'artifacts' );
if ( statSync( artifactRoot, { throwIfNoEntry: false } )?.isDirectory() ) visit( artifactRoot );
const currentSnapshots = snapshots.filter( path => statSync( path ).mtimeMs >= startedAt );
currentSnapshots.sort( ( left, right ) => statSync( right ).mtimeMs - statSync( left ).mtimeMs );
const snapshotContents = currentSnapshots.map( path => ( { path, content: readFileSync( path, 'utf8' ) } ) );
const snapshotRow = snapshotContents.find( row => row.content.includes( '<h1 class="wp-block-heading">Personal</h1>' ) );
const snapshotPath = snapshotRow?.path ?? '';
const snapshot = snapshotPath ? readFileSync( snapshotPath, 'utf8' ) : '';
const pageTwoSnapshotRow = snapshotContents.find( row => row.content.includes( 'Story 2' ) && row.content.includes( 'Previous Page' ) && ! row.content.includes( 'Story 12' ) && ! row.content.includes( 'Outside the archive' ) );
const invalidPagedSnapshotRow = snapshotContents.find( row => row.content.includes( 'id="ssi-taxonomy-invalid-paged-http-proof"' ) );
const invalidPagedProofMatch = invalidPagedSnapshotRow?.content.match( /<pre id="ssi-taxonomy-invalid-paged-http-proof">([^<]+)<\/pre>/ );
let invalidPagedProof = {};
try {
	invalidPagedProof = JSON.parse( invalidPagedProofMatch?.[1] ?? '{}' );
} catch {
	invalidPagedProof = {};
}
if ( 3 === ( invalidPagedProof.responses ?? [] ).length ) {
	writeFileSync( join( evidenceRoot, 'invalid-taxonomy-paged-http-proof.json' ), JSON.stringify( invalidPagedProof, null, 2 ) );
}
const browserAssertions = {
	categoryTemplateEditorRouteLoaded: editorSaveStep?.exitCode === 0 && String( editorSaveStep?.stdout ?? '' ).includes( '/wp-admin/site-editor.php' ),
	categoryTemplateEditorSaveRan: editorSaveStep?.exitCode === 0 && editorVerify.persisted === true,
	categoryTemplateEditorReloadRan: editorReloadStep?.exitCode === 0 && editorVerify.reloaded === true,
	nativeArchiveExported: editorVerify.exported === true && 'website/writing/category/personal/index.html' === editorVerify.archive_path,
	exportArchiveHasDigest: 'string' === typeof editorVerify.archive_sha256 && 64 === editorVerify.archive_sha256.length,
	exportPaginationHasSecondPage: 2 === editorVerify.archive_page_count && 'website/writing/category/personal/page/2/index.html' === editorVerify.next_page_path && String( editorVerify.next_page_html ?? '' ).includes( 'Story 2' ),
	sourceArchiveHeading: browserStep?.exitCode === 0 && new URL( browser.finalUrl ?? 'http://invalid/' ).pathname.replace( /\/$/, '' ) === '/writing/category/personal',
	dynamicPostAppears: browserStep?.exitCode === 0 && 0 === ( browser.summary?.errors ?? -1 ),
	sourceMemberOrderingPreserved: browserStep?.exitCode === 0 && 0 === ( browser.summary?.errors ?? -1 ),
	sharedHeaderRetained: browserStep?.exitCode === 0 && 0 === ( browser.summary?.errors ?? -1 ),
	sharedFooterRetainedOnce: browserStep?.exitCode === 0 && 0 === ( browser.summary?.errors ?? -1 ),
	categoryTemplateEditRendered: editorVerify.persisted === true && editorVerify.reloaded === true,
	nativePaginationRendered: browserStep?.exitCode === 0 && 0 === ( browser.summary?.errors ?? -1 ),
	unrelatedPostExcluded: browserStep?.exitCode === 0 && 0 === ( browser.summary?.errors ?? -1 ),
	requestedSourceRoute: new URL( browser.finalUrl ?? 'http://invalid/' ).pathname.replace( /\/$/, '' ) === '/writing/category/personal',
	noBrowserErrors: 0 === ( browser.summary?.errors ?? -1 ),
};
let pageTwoBrowser;
try {
	pageTwoBrowser = JSON.parse( pageTwoBrowserStep?.stdout ?? '{}' );
} catch {
	pageTwoBrowser = {};
}
browserAssertions.actualPageTwoHttpRequest = pageTwoBrowserStep?.exitCode === 0 && new URL( pageTwoBrowser.finalUrl ?? 'http://invalid/' ).pathname.includes( '/writing/category/personal/page/2' ) && Boolean( pageTwoSnapshotRow ) && 0 === ( pageTwoBrowser.summary?.errors ?? -1 );
browserAssertions.invalidPagedRoutesRejected = invalidPagedBrowserStep?.exitCode === 0 && 'ssi-taxonomy/invalid-paged-routes/v1' === invalidPagedProof.schema && 3 === invalidPagedProof.responses?.length && invalidPagedProof.responses.every( response => !( response.original_status >= 200 && response.original_status < 300 ) && 404 === response.final_status && false === response.taxonomy_archive_rendered );
let success = result.success === true && command.status === 0 && phpStep?.exitCode === 0 && String( phpStep?.stdout ?? '' ).includes( 'Taxonomy archive WordPress store acceptance passed.' ) && String( phpStep?.stdout ?? '' ).includes( 'SSI is inactive for the subsequent real base/page-2 and invalid-page HTTP requests.' ) && packageProofMatchesPin( primaryPackageProof ) && editorSaveStep?.exitCode === 0 && editorReloadStep?.exitCode === 0 && editorVerifyStep?.exitCode === 0 && editorVerify.persisted === true && editorVerify.reloaded === true && editorVerify.exported === true && browserStep?.exitCode === 0 && pageTwoBrowserStep?.exitCode === 0 && invalidPagedBrowserStep?.exitCode === 0 && Object.values( browserAssertions ).every( Boolean );

let adoptionAcceptance = { success: false, reason: 'primary acceptance did not pass' };
let roundtripAcceptance = { success: false, reason: 'primary acceptance did not pass' };
if ( success ) {
	const planLine = String( phpStep?.stdout ?? '' ).split( '\n' ).find( line => line.startsWith( 'SSI-TAXONOMY-ADOPTION-PLAN:' ) );
	if ( planLine ) {
		const planPath = join( evidenceRoot, 'taxonomy-adoption-plan.json' );
		writeFileSync( planPath, JSON.stringify( JSON.parse( planLine.slice( 'SSI-TAXONOMY-ADOPTION-PLAN:'.length ) ), null, 2 ) );
		const adoptionWorkload = {
			schema: 'wp-codebox/wordpress-workload-run/v1',
			wordpress_version: workload.wordpress_version,
			blueprint: workload.blueprint,
			mounts: [ ...mounts, { source: evidenceRoot, target: '/wordpress/wp-content/uploads/ssi-taxonomy-evidence', mode: 'readonly' } ],
			steps: [ { command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/taxonomy-archive-adoption-wordpress.php' ) }` ] } ],
		};
		const adoptionWorkloadPath = join( sessionDir, 'adoption-workload.json' );
		writeFileSync( adoptionWorkloadPath, JSON.stringify( adoptionWorkload, null, 2 ) );
		writeFileSync( join( evidenceRoot, 'adoption-workload.json' ), JSON.stringify( adoptionWorkload, null, 2 ) );
		const adoptionArtifactsTmp = join( sessionDir, 'adoption-artifacts' );
		const adoptionCommand = spawnSync( cli, [ 'run-wordpress-workload', '--input-file', adoptionWorkloadPath, '--artifacts', adoptionArtifactsTmp, '--format=json' ], {
			encoding: 'utf8',
			maxBuffer: 64 * 1024 * 1024,
			timeout: 20 * 60 * 1000,
		} );
		let adoptionResult = {};
		try {
			adoptionResult = JSON.parse( adoptionCommand.stdout ?? '{}' );
		} catch ( error ) {
			adoptionAcceptance = { success: false, error: error.message };
		}
		writeFileSync( join( evidenceRoot, 'adoption-result.json' ), JSON.stringify( adoptionResult, null, 2 ) );
		writeFileSync( join( evidenceRoot, 'adoption-stdout.log' ), adoptionCommand.stdout ?? '' );
		writeFileSync( join( evidenceRoot, 'adoption-stderr.log' ), adoptionCommand.stderr ?? '' );
		if ( statSync( adoptionArtifactsTmp, { throwIfNoEntry: false } )?.isDirectory() ) {
			cpSync( adoptionArtifactsTmp, join( evidenceRoot, 'adoption-artifacts' ), { recursive: true, force: true } );
		}
		const adoptionPhp = ( adoptionResult.executions ?? [] ).find( step => 'wordpress.run-php' === step.command );
		adoptionAcceptance = {
			success: adoptionResult.success === true && adoptionCommand.status === 0 && adoptionPhp?.exitCode === 0 && String( adoptionPhp?.stdout ?? '' ).includes( 'Taxonomy archive adoption, destination conflict, and exact late rollback acceptance passed.' ) && packageProofMatchesPin( packageProofFrom( adoptionPhp?.stdout ) ),
			wpCodebox: spawnSync( cli, [ 'version' ], { encoding: 'utf8' } ).stdout.trim(),
			failure: adoptionResult.result?.failure_summary ?? null,
			exitCode: adoptionPhp?.exitCode ?? null,
		};
		if ( adoptionAcceptance.success ) {
			const roundtripWorkload = {
				schema: 'wp-codebox/wordpress-workload-run/v1',
				wordpress_version: workload.wordpress_version,
				blueprint: workload.blueprint,
				mounts: [ ...mounts, { source: evidenceRoot, target: '/wordpress/wp-content/uploads/ssi-taxonomy-evidence', mode: 'readonly' } ],
				steps: [
					{ command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/taxonomy-archive-export-reimport-wordpress.php' ) }` ] },
					{ command: 'wordpress.browser-page-load', args: [ 'url=/writing/category/personal/', 'wait-for=domcontentloaded', `script=${ baseArchiveHttpProbe }`, 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
					{ command: 'wordpress.browser-page-load', args: [ 'url=/writing/category/personal/page/2/', 'wait-for=domcontentloaded', `script=${ staticExportPageTwoProbe }`, 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
					{ command: 'wordpress.browser-page-load', args: [ 'url=/category/personal/', 'wait-for=domcontentloaded', `script=${ nativeReimportBaseProbe }`, 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
					{ command: 'wordpress.browser-page-load', args: [ 'url=/category/personal/page/2/', 'wait-for=domcontentloaded', `script=${ nativeReimportPageTwoProbe }`, 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
				],
			};
			const roundtripWorkloadPath = join( sessionDir, 'roundtrip-workload.json' );
			writeFileSync( roundtripWorkloadPath, JSON.stringify( roundtripWorkload, null, 2 ) );
			writeFileSync( join( evidenceRoot, 'roundtrip-workload.json' ), JSON.stringify( roundtripWorkload, null, 2 ) );
			const roundtripArtifactsTmp = join( sessionDir, 'roundtrip-artifacts' );
			const roundtripCommand = spawnSync( cli, [ 'run-wordpress-workload', '--input-file', roundtripWorkloadPath, '--artifacts', roundtripArtifactsTmp, '--format=json' ], {
				encoding: 'utf8',
				maxBuffer: 64 * 1024 * 1024,
				timeout: 20 * 60 * 1000,
			} );
			let roundtripResult = {};
			try {
				roundtripResult = JSON.parse( roundtripCommand.stdout ?? '{}' );
			} catch ( error ) {
				roundtripAcceptance = { success: false, error: error.message };
			}
			writeFileSync( join( evidenceRoot, 'roundtrip-result.json' ), JSON.stringify( roundtripResult, null, 2 ) );
			writeFileSync( join( evidenceRoot, 'roundtrip-stdout.log' ), roundtripCommand.stdout ?? '' );
			writeFileSync( join( evidenceRoot, 'roundtrip-stderr.log' ), roundtripCommand.stderr ?? '' );
			if ( statSync( roundtripArtifactsTmp, { throwIfNoEntry: false } )?.isDirectory() ) {
				cpSync( roundtripArtifactsTmp, join( evidenceRoot, 'roundtrip-artifacts' ), { recursive: true, force: true } );
			}
			const roundtripSteps = roundtripResult.executions ?? [];
			const roundtripPhp = roundtripSteps.find( step => 'wordpress.run-php' === step.command );
			const roundtripBrowsers = roundtripSteps.filter( step => 'wordpress.browser-page-load' === step.command );
			let roundtripProof = {};
			try {
				const proofLine = String( roundtripPhp?.stdout ?? '' ).split( '\n' ).find( line => {
					try { return 'ssi-taxonomy/second-site-export-reimport/v1' === JSON.parse( line ).schema; } catch { return false; }
				} );
				roundtripProof = JSON.parse( proofLine ?? '{}' );
			} catch {
				roundtripProof = {};
			}
			const roundtripBrowserResults = roundtripBrowsers.map( step => {
				try { return JSON.parse( step.stdout ?? '{}' ); } catch { return {}; }
			} );
			roundtripAcceptance = {
				success: roundtripResult.success === true && roundtripCommand.status === 0 && roundtripPhp?.exitCode === 0 && roundtripProof.acceptance === true && roundtripProof.import_completed === true && ! roundtripProof.ssi_active_after_import && packageProofMatchesPin( roundtripProof.release_package ) && 4 === roundtripBrowsers.length && roundtripBrowsers.every( ( step, index ) => step.exitCode === 0 && 0 === ( roundtripBrowserResults[index]?.summary?.errors ?? -1 ) ) && String( roundtripBrowserResults[0]?.finalUrl ?? '' ).includes( '/writing/category/personal/' ) && String( roundtripBrowserResults[1]?.finalUrl ?? '' ).includes( '/writing/category/personal/page/2/' ) && String( roundtripBrowserResults[2]?.finalUrl ?? '' ).includes( '/category/personal/' ) && String( roundtripBrowserResults[3]?.finalUrl ?? '' ).includes( '/category/personal/page/2/' ),
				wpCodebox: spawnSync( cli, [ 'version' ], { encoding: 'utf8' } ).stdout.trim(),
				failure: roundtripResult.result?.failure_summary ?? null,
				exitCode: roundtripPhp?.exitCode ?? null,
				proof: roundtripProof,
				browserFinalUrls: roundtripBrowserResults.map( item => item.finalUrl ?? null ),
			};
		}
	} else {
		adoptionAcceptance = { success: false, reason: 'Accepted producer plan was not emitted by the real source/consumer compilation run.' };
	}
	success = success && adoptionAcceptance.success && roundtripAcceptance.success;
}
writeFileSync( join( evidenceRoot, 'browser-assertions.json' ), JSON.stringify( { success: Object.values( browserAssertions ).every( Boolean ), editorTemplateUrl, snapshot: snapshotPath, assertions: browserAssertions, invalidPagedProof }, null, 2 ) );
console.log( JSON.stringify( {
	success,
	wpCodebox: spawnSync( cli, [ 'version' ], { encoding: 'utf8' } ).stdout.trim(),
	actualWordPressVersion: /WordPress ([0-9.]+)/.exec( String( phpStep?.stdout ?? '' ) )?.[1] ?? null,
	wordpressVersion: workload.wordpress_version,
	evidenceRoot,
	workloadStatus: result.status ?? null,
	executions: ( result.executions ?? [] ).map( step => ( { command: step.command, exitCode: step.exitCode, stdout: step.stdout, stderr: step.stderr } ) ),
	editorVerification: editorVerify,
	browserAssertions,
	releasePackageIdentity,
	invalidPagedProof,
	adoptionAcceptance,
	roundtripAcceptance,
	failure: result.result?.failure_summary ?? result.error?.message ?? null,
}, null, 2 ) );
if ( ! success ) process.exitCode = 1;
