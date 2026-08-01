import { createHash } from 'node:crypto';

export const RUNTIME_PRESENTATION_EVIDENCE_SCHEMA = 'blocks-engine/php-transformer/runtime-presentation-evidence/v1';
export const RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE = 'runtime_presentation_evidence_unavailable';

export function runtimePresentationEvidenceEnabled(input = {}) {
  return input.runtimePresentationEvidence === true || input.runtime_presentation_evidence === true;
}

// This probe runs before `validate-artifact`, after browser network-idle. It
// returns only the bounded fields accepted by Blocks Engine, never page markup.
export function runtimePresentationEvidenceProbeStep({ fixture = {}, sourceUrl, artifact, viewport = { width: 1280, height: 1600 } } = {}) {
  const assets = Object.fromEntries((artifact?.files || [])
    .filter((file) => /^image\//.test(file.type || ''))
    .map((file) => [String(file.path).replace(/^website\//, ''), createHash('sha256').update(file.content_base64 || '').digest('hex')]));
  const script = `() => { const assets=${JSON.stringify(assets)}; const selector=(n)=>{const p=n.parentElement;if(!p)return n.tagName.toLowerCase();const s=[...p.children].filter(x=>x.tagName===n.tagName);return selector(p)+' > '+n.tagName.toLowerCase()+':nth-of-type('+s.indexOf(n)+1+')'}; const clip=(n)=>{let p=n.parentElement;while(p){const s=getComputedStyle(p);if(/(hidden|clip|scroll|auto)/.test(s.overflow+s.overflowX+s.overflowY)){const r=p.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}}p=p.parentElement}const r=n.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}}; return {schema:'${RUNTIME_PRESENTATION_EVIDENCE_SCHEMA}',provenance:{browser:{name:'Chromium',version:navigator.userAgent},viewport:{width:innerWidth,height:innerHeight,device_scale_factor:devicePixelRatio},lifecycle:{phase:'network-idle'}},observations:[...document.images].map(n=>{const r=n.getBoundingClientRect(),s=getComputedStyle(n),m=(s.transform.match(/-?[\\d.]+/g)||[]).map(Number),path=new URL(n.currentSrc||n.src,location.href).pathname.replace(/^\\//,'');return {element:{source_path:'${String(fixture.entrypoint || 'index.html').replace(/^\/+/, '')}',selector:selector(n)},asset_hash:assets[path]||'',intrinsic:{width:n.naturalWidth,height:n.naturalHeight},rendered:{width:r.width,height:r.height},transform:{matrix:m.length===6?m:[1,0,0,1,0,0],origin:{x:parseFloat(s.transformOrigin)||0,y:parseFloat(s.transformOrigin.split(' ')[1])||0}},clip:clip(n)}}).filter(x=>x.asset_hash&&x.intrinsic.width>0&&x.intrinsic.height>0&&x.rendered.width>0&&x.rendered.height>0)} }`;
  return { command: 'wordpress.browser-probe', allowFailure: true, args: [`url=${sourceUrl}`, 'wait-for=networkidle', `viewport=${viewport.width}x${viewport.height}`, `script=${script}`, 'capture=html'], metadata: { fixture_id: fixture.id, fixture_path: fixture.fixture_path || fixture.directory, phase: 'runtime-presentation-evidence', evidence_schema: RUNTIME_PRESENTATION_EVIDENCE_SCHEMA } };
}

export function collectRuntimePresentationEvidence(payloads = []) {
  const evidence = payloads.filter((payload) => payload?.schema === RUNTIME_PRESENTATION_EVIDENCE_SCHEMA).at(-1) || null;
  if (evidence) return { evidence, diagnostics: [] };
  const requested = payloads.some((payload) => payload?.command === 'wordpress.browser-probe' && payload?.metadata?.phase === 'runtime-presentation-evidence');
  if (!requested) return { evidence: null, diagnostics: [] };
  return { evidence: null, diagnostics: [{ kind: RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE, severity: 'warning', loss_class: 'runtime_evidence_unavailable', message: 'Runtime presentation evidence was not attached before artifact compilation.', required_wp_codebox_primitive: 'A recipe browser command must write its typed page-evaluation result to a caller-selected artifact path so a following recipe step can merge it into artifact.json before validate-artifact compiles the artifact.' }] };
}
