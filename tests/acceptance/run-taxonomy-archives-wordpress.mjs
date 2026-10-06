import { mkdirSync, mkdtempSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const engineRoot = resolve( process.env.BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT ?? join( root, '../blocks-engine-2468/php-transformer' ) );
const cli = process.env.WP_CODEBOX_CLI ?? '/home/chubes/.local/bin/wp-codebox';
const evidenceRoot = resolve( process.env.SSI_TAXONOMY_EVIDENCE ?? join( root, 'artifacts/taxonomy-archives' ) );
const editorMarker = 'SSI Gutenberg category template edit persisted';
const editorReloadMarker = 'SSI Gutenberg category template reload persisted';
const editorTemplateUrl = '/wp-admin/site-editor.php?p=%2Fwp_template%2Ftaxonomy-archive-acceptance%2F%2Fcategory-personal&canvas=edit';
if ( ! statSync( join( engineRoot, 'src/WordPressSitePlan/TaxonomyProjection.php' ), { throwIfNoEntry: false } )?.isFile() ) {
	throw new Error( 'Set BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT to the paired producer candidate source checkout.' );
}
mkdirSync( evidenceRoot, { recursive: true } );
const sessionDir = mkdtempSync( join( tmpdir(), 'ssi-taxonomy-wordpress-' ) );
const workloadFile = join( sessionDir, 'workload.json' );
const editorTemplateId = 'taxonomy-archive-acceptance//category-personal';
const editorSaveScript = `window.__taxonomyEditorSave = (async () => {
const id = '${ editorTemplateId }';
const marker = '${ editorMarker }';
const core = wp.data.resolveSelect('core');
const dispatch = wp.data.dispatch('core');
const template = await core.getEntityRecord('postType', 'wp_template', id);
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
const template = await wp.data.resolveSelect('core').getEntityRecord('postType', 'wp_template', id);
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
const editorVerification = `$template = get_block_template(get_stylesheet() . '//category-personal');
$persisted = $template instanceof WP_Block_Template && str_contains($template->content, '${editorMarker}');
$reloaded = $template instanceof WP_Block_Template && str_contains($template->content, '${editorReloadMarker}');
if (!function_exists('blocks_engine_php_transformer_convert_format')) { require_once '/wordpress/wp-content/plugins/blocks-engine-candidate/php-transformer.php'; }
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-theme-exporter.php';
$export = Static_Site_Importer_Theme_Exporter::export_theme(array('theme_slug' => get_stylesheet()));
$exportError = is_wp_error($export) ? array('code' => $export->get_error_code(), 'message' => $export->get_error_message()) : null;
$artifact = is_array($export) ? ($export['website_artifact'] ?? array()) : array();
$files = array_column($artifact['files'] ?? array(), 'content', 'path');
$archivePath = 'website/writing/category/personal/index.html';
$nextPath = 'website/writing/category/personal/page/2/index.html';
$archive = (string) ($files[$archivePath] ?? '');
$nextArchive = (string) ($files[$nextPath] ?? '');
$exported = !is_wp_error($export) && 1 === (int) ($artifact['report']['taxonomy_archive_count'] ?? 0) && 2 === (int) ($artifact['report']['taxonomy_archive_page_count'] ?? 0) && str_contains($archive, 'Personal') && str_contains($archive, 'Story 12') && str_contains($archive, 'Added after import') && str_contains($archive, '${editorReloadMarker}') && str_contains($archive, 'writing/category/personal/page/2/') && str_contains($archive, 'href="../../../story-12/"') && str_contains($nextArchive, 'Story 2') && str_contains($nextArchive, 'Previous Page') && !str_contains($nextArchive, 'Next Page') && !str_contains($nextArchive, 'Outside the archive') && str_contains($nextArchive, 'href="../../../../../style.css"');
$result = array('schema' => 'ssi-taxonomy/editor-template-persistence/v1', 'template_id' => get_stylesheet() . '//category-personal', 'persisted' => $persisted, 'reloaded' => $reloaded, 'exported' => $exported, 'archive_path' => $archivePath, 'archive_page_count' => $artifact['report']['taxonomy_archive_page_count'] ?? 0, 'archive_bytes' => strlen($archive), 'archive_sha256' => hash('sha256', $archive), 'archive_html' => $archive, 'next_page_path' => $nextPath, 'next_page_html' => $nextArchive, 'export_error' => $exportError, 'bridge_available' => function_exists('blocks_engine_php_transformer_convert_format'));
echo wp_json_encode($result) . "\n";
if (!$persisted || !$reloaded || !$exported) { throw new RuntimeException('The Gutenberg category template edit/save/reload or native archive export did not persist.'); }`;
const workload = {
	schema: 'wp-codebox/wordpress-workload-run/v1',
	wordpress_version: process.env.SSI_TAXONOMY_WORDPRESS_VERSION ?? '7.1',
	blueprint: { steps: [ { step: 'defineWpConfigConsts', consts: { SSI_TAXONOMY_DISPOSABLE_TEST: true } } ] },
	mounts: [
		{ source: root, target: '/wordpress/wp-content/plugins/static-site-importer', mode: 'readonly' },
		{ source: engineRoot, target: '/wordpress/wp-content/plugins/blocks-engine-candidate', mode: 'readonly' },
	],
	steps: [
		{ command: 'wordpress.run-php', args: [ `code-file=${ join( root, 'tests/acceptance/taxonomy-archives-wordpress.php' ) }` ] },
		{
			command: 'wordpress.browser-page-load',
			args: [ `url=${ editorTemplateUrl }`, 'auth=wordpress-admin', 'wait-for=load', `script=${ editorSaveScript }`, 'capture=html,console,errors,network,screenshot', 'duration=12s', 'timeout=120s' ],
		},
		{ command: 'wordpress.browser-page-load', args: [ `url=${ editorTemplateUrl }`, 'auth=wordpress-admin', 'wait-for=load', `script=${ editorReloadScript }`, 'capture=html,console,errors,screenshot', 'duration=8s', 'timeout=120s' ] },
		{ command: 'wordpress.run-php', args: [ `code=${ editorVerification }` ] },
		{ command: 'wordpress.browser-page-load', args: [ 'url=/writing/category/personal/', 'wait-for=domcontentloaded', 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
		{ command: 'wordpress.browser-page-load', args: [ 'url=/writing/category/personal/page/2/', 'wait-for=domcontentloaded', 'capture=html,console,errors,screenshot', 'network-policy=block' ] },
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
const editorSteps = ( result.executions ?? [] ).filter( step => 'wordpress.browser-page-load' === step.command );
const editorSaveStep = editorSteps[0];
const editorReloadStep = editorSteps[1];
const editorVerifyStep = ( result.executions ?? [] ).filter( step => 'wordpress.run-php' === step.command )[1];
const browserStep = editorSteps[2];
const pageTwoBrowserStep = editorSteps[3];
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
const pageTwoSnapshotRow = snapshotContents.find( row => row.content.includes( 'Story 7' ) && ! row.content.includes( 'Story 12' ) && ! row.content.includes( 'Outside the archive' ) );
const browserAssertions = {
	categoryTemplateEditorRouteLoaded: editorSaveStep?.exitCode === 0 && String( editorSaveStep?.stdout ?? '' ).includes( '/wp-admin/site-editor.php' ),
	categoryTemplateEditorSaveRan: editorSaveStep?.exitCode === 0 && editorVerify.persisted === true,
	categoryTemplateEditorReloadRan: editorReloadStep?.exitCode === 0 && editorVerify.reloaded === true,
	nativeArchiveExported: editorVerify.exported === true && 'website/writing/category/personal/index.html' === editorVerify.archive_path,
	exportArchiveHasDigest: 'string' === typeof editorVerify.archive_sha256 && 64 === editorVerify.archive_sha256.length,
	exportPaginationHasSecondPage: 2 === editorVerify.archive_page_count && 'website/writing/category/personal/page/2/index.html' === editorVerify.next_page_path && String( editorVerify.next_page_html ?? '' ).includes( 'Story 2' ),
	sourceArchiveHeading: snapshot.includes( '<h1 class="wp-block-heading">Personal</h1>' ),
	dynamicPostAppears: snapshot.includes( 'Added after import' ),
	sourceMemberOrderingPreserved: snapshot.indexOf( 'Story 12</a>' ) < snapshot.indexOf( 'Story 11</a>' ),
	sharedHeaderRetained: snapshot.includes( 'Shared source header' ),
	sharedFooterRetainedOnce: 1 === snapshot.split( 'Shared source footer' ).length - 1,
	categoryTemplateEditRendered: snapshot.includes( editorMarker ) && snapshot.includes( editorReloadMarker ),
	nativePaginationRendered: snapshot.includes( 'Next Page' ),
	unrelatedPostExcluded: ! snapshot.includes( 'Outside the archive' ),
	requestedSourceRoute: new URL( browser.finalUrl ?? 'http://invalid/' ).pathname.replace( /\/$/, '' ) === '/writing/category/personal',
	noBrowserErrors: 0 === ( browser.summary?.errors ?? -1 ),
};
let pageTwoBrowser;
try {
	pageTwoBrowser = JSON.parse( pageTwoBrowserStep?.stdout ?? '{}' );
} catch {
	pageTwoBrowser = {};
}
browserAssertions.actualPageTwoHttpRequest = pageTwoBrowserStep?.exitCode === 0 && new URL( pageTwoBrowser.finalUrl ?? 'http://invalid/' ).pathname.includes( '/writing/category/personal/page/2' ) && Boolean( pageTwoSnapshotRow );
const success = result.success === true && command.status === 0 && phpStep?.exitCode === 0 && String( phpStep?.stdout ?? '' ).includes( 'Taxonomy archive WordPress store acceptance passed.' ) && editorSaveStep?.exitCode === 0 && editorReloadStep?.exitCode === 0 && editorVerifyStep?.exitCode === 0 && editorVerify.persisted === true && editorVerify.reloaded === true && editorVerify.exported === true && browserStep?.exitCode === 0 && pageTwoBrowserStep?.exitCode === 0 && Object.values( browserAssertions ).every( Boolean );
writeFileSync( join( evidenceRoot, 'browser-assertions.json' ), JSON.stringify( { success: Object.values( browserAssertions ).every( Boolean ), editorTemplateUrl, snapshot: snapshotPath, assertions: browserAssertions }, null, 2 ) );
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
	failure: result.result?.failure_summary ?? result.error?.message ?? null,
}, null, 2 ) );
if ( ! success ) process.exitCode = 1;
