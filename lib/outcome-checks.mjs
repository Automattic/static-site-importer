import { interactiveOutcomes } from './interactive-outcomes.mjs';
const REQUIRED = ['link_graph', 'block_validity', 'head_singletons', 'control_inventory', 'forms'];
const TYPES = ['post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation'];
export const OUTCOME_SCHEMA = 'static-site-importer/outcome-checks/v1';
export function unavailableOutcomes(candidateOrigin, routes, importRunId) {
  return { schema: OUTCOME_SCHEMA, candidate_origin: candidateOrigin, routes, import_run_id: importRunId, status: 'pending', checks: Object.fromEntries(REQUIRED.map(name => [name, unsupported('runtime_evidence_unavailable')])) };
}
const unsupported = reason => ({ status: 'unsupported', evidence: [{ reason }] });
const result = (evidence, failures) => ({ status: failures.length ? 'fail' : 'pass', evidence, failures });

/** Only measured failures fail acceptance; unsupported checks keep it pending. */
export function outcomeGate(report) {
  const checks = REQUIRED.map(name => report?.checks?.[name]);
  return { status: checks.some(check => check?.status === 'fail') ? 'failed' : report?.schema === OUTCOME_SCHEMA && checks.every(check => check?.status === 'pass' && check.evidence?.length) ? 'passed' : 'pending', reason: 'outcome_checks_required' };
}

/** Caller executes the shipped PHP inventory collector in its own authenticated runtime.
 * Browser probes are independent, logged-out contexts, never the editor auth context.
 */
export async function runOutcomeChecks({ browser, page, candidateOrigin, routes, config = {}, widths = [390, 768, 1440], importRunId }) {
  const report = { schema: OUTCOME_SCHEMA, import_run_id: importRunId, candidate_origin: candidateOrigin, routes, widths, source_capture: config.capture ?? null, checks: {} };
  let inventory;
  try { inventory = await config.collectInventory?.({ page, candidateOrigin }); } catch { /* unavailable is not success */ }
  const complete = inventory?.schema === 'static-site-importer/outcome-inventory/v1' && TYPES.every(type => inventory.types?.includes(type)) && Array.isArray(inventory.documents) && inventory.documents.length > 0;
  const documents = complete ? inventory.documents : [];
  report.checks.block_validity = !complete || documents.some(doc => doc.parse?.parser !== 'parse_blocks' || typeof doc.parse.valid !== 'boolean')
    ? unsupported('complete_server_parse_inventory_required')
    : result(documents.map(({ id, type, parse }) => ({ id, type, ...parse })), documents.filter(doc => !doc.parse.valid).map(({ id, type, parse }) => ({ id, type, errors: parse.errors })));
  // Client editor validation, CSS links and shared anchors are follow-up capabilities,
  // not silently included in this server-parse slice's pass claim.
  report.capabilities = { client_editor_validation: 'unsupported', css_links: 'unsupported', shared_chrome_anchors: 'unsupported' };
  const linkEvidence = [], linkFailures = [], headEvidence = [], headFailures = [];
  const sourceHosts = config.sourceHosts;
  const hostsValid = Array.isArray(sourceHosts) && sourceHosts.length > 0 && sourceHosts.every(host => typeof host === 'string' && /^[a-z0-9.-]+(?::\d+)?$/i.test(host));
  const scan = (text, surface) => {
    const decoded = text.replace(/&amp;|&#0*38;|&#x0*26;/gi, '&').replace(/\\\//g, '/');
    const violations = [];
    if (/[?&]page_id(?:=|%3d)/i.test(decoded)) violations.push('page_id_url');
    for (const value of decoded.match(/(?:https?:)?\/\/[^\s<>"'()]+/gi) ?? []) {
      try { if (sourceHosts?.some(host => host.toLowerCase() === new URL(value, candidateOrigin).host.toLowerCase())) violations.push('source_host_url'); } catch { /* non URLs aren't links */ }
    }
    linkEvidence.push({ surface, violations });
    if (violations.length) linkFailures.push({ surface, violations });
  };
  for (const doc of documents) scan(doc.content, `${doc.type}:${doc.id}`);
  let available = true;
  for (const route of routes) {
    const probe = await browser.newPage();
    try {
      const response = await probe.goto(new URL(route, candidateOrigin).href, { waitUntil: 'load', timeout: 30000 });
      if (!response || response.status() !== 200 || new URL(probe.url()).origin !== candidateOrigin) throw new Error('route_unavailable');
      const head = await probe.locator('head').innerHTML();
      scan(head, `head:${route}`);
      const tags = await probe.locator('head').evaluate(head => {
        const select = selector => [...head.querySelectorAll(selector)].map(node => node.getAttribute('href') ?? node.getAttribute('content') ?? node.getAttribute('charset'));
        return Object.fromEntries(Object.entries({ charset: 'meta[charset]', viewport: 'meta[name="viewport" i]', canonical: 'link[rel~="canonical" i]', description: 'meta[name="description" i]', 'og:title': 'meta[property="og:title" i]', 'og:url': 'meta[property="og:url" i]', 'og:image': 'meta[property="og:image" i]', prohibited: 'meta[name="robots" i],meta[name="generator" i],script[src],link[rel="pingback"]' }).map(([key, selector]) => [key, select(selector)]));
      });
      const failures = [];
      for (const [name, values] of Object.entries(tags)) {
        if (name === 'prohibited') continue;
        if (values.length !== 1 || !values[0]?.trim()) failures.push(`${name}:singleton_required`);
        if (['canonical', 'og:url', 'og:image'].includes(name)) for (const value of values) {
          try { if (new URL(value).origin !== candidateOrigin) failures.push(`${name}:destination_required`); } catch { failures.push(`${name}:absolute_url_required`); }
        }
      }
      // Robots/generator are prohibited conservatively: provenance cannot be inferred
      // from rendered bytes. Telemetry patterns are explicitly caller supplied.
      if (await probe.locator('head meta[name="robots" i],head meta[name="generator" i],head link[rel="pingback"]').count()) failures.push('prohibited_metadata');
      for (const pattern of config.telemetryMarkers ?? []) if (head.includes(pattern)) failures.push('source_telemetry');
      headEvidence.push({ route, tags, failures });
      if (failures.length) headFailures.push({ route, failures });
    } catch { available = false; headEvidence.push({ route, reason: 'route_probe_unavailable' }); }
    finally { await probe.close(); }
  }
  let aliasesAvailable = Array.isArray(config.aliases);
  for (const alias of config.aliases ?? []) {
    const probe = await browser.newPage();
    try {
      const response = await probe.request.get(new URL(alias, candidateOrigin).href, { maxRedirects: 0 });
      const location = response.headers().location;
      const target = location && new URL(location, new URL(alias, candidateOrigin));
      let passed = response.status() >= 300 && response.status() < 400 && target?.origin === candidateOrigin && target.href !== new URL(alias, candidateOrigin).href;
      let targetStatus;
      if (passed) { const final = await probe.request.get(target.href, { maxRedirects: 0 }); targetStatus = final.status(); passed = targetStatus === 200; }
      const evidence = { alias, location, status: response.status(), target_status: targetStatus, passed };
      linkEvidence.push(evidence);
      if (!passed) linkFailures.push(evidence);
    } catch { aliasesAvailable = false; }
    finally { await probe.close(); }
  }
  report.checks.link_graph = linkFailures.length ? result(linkEvidence, linkFailures) : !complete || !hostsValid || !available || !aliasesAvailable ? { ...unsupported('inventory_source_hosts_aliases_and_route_probes_required'), evidence: [...linkEvidence, { reason: 'inventory_source_hosts_aliases_and_route_probes_required' }] } : result(linkEvidence, []);
  report.checks.head_singletons = headFailures.length ? result(headEvidence, headFailures) : !available || !Array.isArray(config.telemetryMarkers) ? { ...unsupported('route_probes_and_source_telemetry_markers_required'), evidence: headEvidence } : result(headEvidence, []);
  Object.assign(report.checks, await interactiveOutcomes({ browser, candidateOrigin, routes, widths, config }));
  report.status = outcomeGate(report).status;
  return report;
}
