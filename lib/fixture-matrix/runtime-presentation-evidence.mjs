import { createHash } from 'node:crypto';
import { shellToken } from './shared/utils.mjs';
import { fixtureStepMetadata } from './steps/shared.mjs';

export const RUNTIME_PRESENTATION_EVIDENCE_SCHEMA = 'blocks-engine/php-transformer/runtime-presentation-evidence/v1';
export const RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE = 'runtime_presentation_evidence_unavailable';

export function runtimePresentationEvidenceEnabled(input = {}) {
  return input.runtimePresentationEvidence === true || input.runtime_presentation_evidence === true;
}

export function runtimePresentationEvidenceArtifactPath(fixture = {}) {
  const fixtureId = String(fixture.id || '');
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/.test(fixtureId)) {
    throw new Error('Runtime presentation evidence requires a 1-80 character safe fixture id.');
  }
  return `${fixtureId}/runtime-presentation-evidence.json`;
}

// This probe runs before `validate-artifact`, after browser network-idle. It
// returns only the bounded fields accepted by Blocks Engine, never page markup.
export function runtimePresentationEvidenceProbeStep({ fixture = {}, sourceUrl, artifact, outputArtifact, outputRuntimePath, viewport = { width: 1280, height: 1600 } } = {}) {
  const assets = Object.fromEntries((artifact?.files || [])
    .filter((file) => /^image\//.test(file.type || ''))
    .map((file) => [String(file.path).replace(/^website\//, ''), createHash('sha256').update(file.content_base64 || '').digest('hex')]));
  const script = `const assets=${JSON.stringify(assets)}; const selector=(n)=>{const p=n.parentElement;if(!p)return n.tagName.toLowerCase();const s=[...p.children].filter(x=>x.tagName===n.tagName);return selector(p)+' > '+n.tagName.toLowerCase()+':nth-of-type('+s.indexOf(n)+1+')'}; const clip=(n)=>{let p=n.parentElement;while(p){const s=getComputedStyle(p);if(/(hidden|clip|scroll|auto)/.test(s.overflow+s.overflowX+s.overflowY)){const r=p.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}}p=p.parentElement}const r=n.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}}; return {schema:'${RUNTIME_PRESENTATION_EVIDENCE_SCHEMA}',provenance:{browser:{name:'Chromium',version:navigator.userAgent},viewport:{width:innerWidth,height:innerHeight,device_scale_factor:devicePixelRatio},lifecycle:{phase:'network-idle'}},observations:[...document.images].map(n=>{const r=n.getBoundingClientRect(),s=getComputedStyle(n),m=(s.transform.match(/-?[\\d.]+/g)||[]).map(Number),path=new URL(n.currentSrc||n.src,location.href).pathname.replace(/^\\//,'');return {element:{source_path:'${String(fixture.entrypoint || 'index.html').replace(/^\/+/, '')}',selector:selector(n)},asset_hash:assets[path]||'',intrinsic:{width:n.naturalWidth,height:n.naturalHeight},rendered:{width:r.width,height:r.height},transform:{matrix:m.length===6?m:[1,0,0,1,0,0],origin:{x:parseFloat(s.transformOrigin)||0,y:parseFloat(s.transformOrigin.split(' ')[1])||0}},clip:clip(n)}}).filter(x=>x.asset_hash&&x.intrinsic.width>0&&x.intrinsic.height>0&&x.rendered.width>0&&x.rendered.height>0)};`;
  const outputPath = outputArtifact || runtimePresentationEvidenceArtifactPath(fixture);
  return { command: 'wordpress.browser-probe', allowFailure: true, args: [`url=${sourceUrl}`, 'wait-for=networkidle', `viewport=${viewport.width}x${viewport.height}`, `script=${script}`, `output-artifact=${outputPath}`, ...(outputRuntimePath ? [`output-runtime-path=${outputRuntimePath}`] : []), 'capture=html'], metadata: { fixture_id: fixture.id, fixture_path: fixture.fixture_path || fixture.directory, phase: 'runtime-presentation-evidence', evidence_schema: RUNTIME_PRESENTATION_EVIDENCE_SCHEMA, output_artifact: outputPath, ...(outputRuntimePath ? { output_runtime_path: outputRuntimePath } : {}) } };
}

// Validate the browser result and atomically attach it to the exact artifact the
// following validate-artifact command compiles. The relative path is produced by
// runtimePresentationEvidenceArtifactPath, while the PHP guard prevents a bad
// runtime path from escaping either the declared artifact root or its fixture.
export function runtimePresentationEvidenceMergeStep({ fixture = {}, artifactRoot, outputArtifact } = {}) {
  const relativePath = outputArtifact || runtimePresentationEvidenceArtifactPath(fixture);
  const config = Buffer.from(JSON.stringify({ artifact_root: artifactRoot, fixture_id: fixture.id, output_artifact: relativePath }), 'utf8').toString('base64');
  const code = `$config = json_decode(base64_decode('${config}'), true);
$fail = static function ( $code, $message ) {
	WP_CLI::line( wp_json_encode( array( 'status' => 'failed', 'success' => false, 'runtime_presentation_evidence' => array( 'status' => 'invalid', 'diagnostic' => array( 'kind' => '${RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE}', 'code' => $code, 'message' => $message ) ) ), JSON_UNESCAPED_SLASHES ) );
	WP_CLI::error( $message );
};
if ( ! is_array( $config ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', (string) ( $config['fixture_id'] ?? '' ) ) || ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9._-]{0,79}/runtime-presentation-evidence\\.json$#', (string) ( $config['output_artifact'] ?? '' ) ) ) { $fail( 'invalid_runtime_presentation_evidence_path', 'Runtime presentation evidence paths are invalid.' ); }
$root = realpath( (string) $config['artifact_root'] );
if ( false === $root || ! is_dir( $root ) ) { $fail( 'runtime_presentation_evidence_artifact_root_unavailable', 'The declared artifact root is unavailable.' ); }
$fixture_dir = realpath( $root . DIRECTORY_SEPARATOR . $config['fixture_id'] );
if ( false === $fixture_dir || ! str_starts_with( $fixture_dir . DIRECTORY_SEPARATOR, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) { $fail( 'runtime_presentation_evidence_fixture_boundary', 'The runtime presentation evidence fixture directory is outside the declared artifact root.' ); }
$evidence_path = $fixture_dir . DIRECTORY_SEPARATOR . basename( $config['output_artifact'] );
$artifact_path = $fixture_dir . DIRECTORY_SEPARATOR . 'artifact.json';
if ( ! is_file( $evidence_path ) ) { $fail( 'runtime_presentation_evidence_unavailable', 'The browser probe did not persist runtime presentation evidence.' ); }
$evidence = json_decode( file_get_contents( $evidence_path ), true );
if ( ! is_array( $evidence ) || '${RUNTIME_PRESENTATION_EVIDENCE_SCHEMA}' !== ( $evidence['schema'] ?? null ) || ! is_array( $evidence['provenance'] ?? null ) || ! is_array( $evidence['observations'] ?? null ) ) { $fail( 'invalid_runtime_presentation_evidence', 'The persisted runtime presentation evidence is not a valid typed envelope.' ); }
$artifact = json_decode( file_get_contents( $artifact_path ), true );
if ( ! is_array( $artifact ) ) { $fail( 'invalid_fixture_artifact', 'The fixture artifact is not valid JSON.' ); }
$artifact['runtime_presentation_evidence'] = $evidence;
$temporary = tempnam( $fixture_dir, '.runtime-presentation-evidence-' );
if ( false === $temporary || false === file_put_contents( $temporary, wp_json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL ) || ! rename( $temporary, $artifact_path ) ) { if ( $temporary && file_exists( $temporary ) ) { unlink( $temporary ); } $fail( 'runtime_presentation_evidence_merge_failed', 'Runtime presentation evidence could not be merged into the fixture artifact.' ); }
WP_CLI::line( wp_json_encode( array( 'status' => 'success', 'success' => true, 'runtime_presentation_evidence' => $evidence, 'artifact' => $artifact_path ), JSON_UNESCAPED_SLASHES ) );`;
  return {
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`eval(base64_decode('${Buffer.from(code, 'utf8').toString('base64')}'));`)}`],
    metadata: fixtureStepMetadata(fixture, 'runtime-presentation-evidence-merge', {
      artifact_root: artifactRoot,
      input_artifact: relativePath,
      artifact: `${fixture.id}/artifact.json`,
      evidence_schema: RUNTIME_PRESENTATION_EVIDENCE_SCHEMA,
    }),
  };
}

export function collectRuntimePresentationEvidence(payloads = []) {
  // A raw browser result proves only that the probe ran. Accept evidence only
  // from the persisted artifact or successful merge output, both of which use
  // the nested compiler-input field.
  const evidence = payloads
    .map((payload) => payload?.runtime_presentation_evidence)
    .filter((candidate) => candidate?.schema === RUNTIME_PRESENTATION_EVIDENCE_SCHEMA)
    .at(-1) || null;
  if (evidence) return { evidence, diagnostics: [] };
  const mergeDiagnostic = payloads
    .map((payload) => payload?.runtime_presentation_evidence?.diagnostic)
    .filter((diagnostic) => diagnostic?.kind === RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE)
    .at(-1);
  if (mergeDiagnostic) return { evidence: null, diagnostics: [{ severity: 'warning', loss_class: 'runtime_evidence_unavailable', ...mergeDiagnostic }] };
  const requested = payloads.some((payload) => payload?.command === 'wordpress.browser-probe' && payload?.metadata?.phase === 'runtime-presentation-evidence');
  if (!requested) return { evidence: null, diagnostics: [] };
  return { evidence: null, diagnostics: [{ kind: RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE, severity: 'warning', loss_class: 'runtime_evidence_unavailable', message: 'Runtime presentation evidence was not merged into artifact.json before compilation.' }] };
}
