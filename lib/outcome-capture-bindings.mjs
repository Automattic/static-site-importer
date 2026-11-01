import fs from 'node:fs';
import path from 'node:path';

// Only contract schemas identify evidence. Filenames other than the standard
// receipt and _redirects are resolved from the receipt, never producer names.
export function readOutcomeCapture(fixture) {
  const source = fixture.source_kind === 'website_artifact' || fixture.source_kind === 'website_directory' ? path.dirname(fixture.source) : fixture.source;
  let files;
  if (fixture.source_kind === 'website_artifact') {
    files = new Map(JSON.parse(fs.readFileSync(fixture.source, 'utf8')).files.map(file => [file.path, file.content]));
  }
  const read = name => {
    if (typeof name !== 'string' || path.isAbsolute(name) || name.split('/').includes('..')) return null;
    if (files) return files.get(name) ?? null;
    const root = fs.realpathSync(source);
    const file = path.resolve(root, name);
    if (!fs.existsSync(file) || !fs.realpathSync(file).startsWith(root + path.sep)) return null;
    return fs.readFileSync(file, 'utf8');
  };
  const parse = name => { try { return JSON.parse(read(name)); } catch { return null; } };
  const receipt = parse('capture-receipt.json');
  if (receipt?.schema !== 'blocks-engine-capture/capture-receipt/v1') return {};
  const reports = [...new Set([...(receipt.reports ?? []), receipt.cleanup?.evidencePath].filter(name => typeof name === 'string'))].map(parse).filter(Boolean);
  return { receipt, reports };
}

/** Derive only declarations backed by capture evidence. Live candidate DOM is
 * used to prove identity, not to invent the source inventory or expectations. */
export async function deriveOutcomeBindings({ fixture, documents, candidateOrigin, sourceOrigin, browser, bindings = {} }) {
  const { receipt, reports = [] } = readOutcomeCapture(fixture);
  const config = { documents: structuredClone(documents), controls: {}, forms: {} };
  const redirects = path.join(fixture.directory, '_redirects');
  if (fs.existsSync(redirects) || Array.isArray(receipt?.routes)) {
    config.aliases = [];
    if (fs.existsSync(redirects)) for (const line of fs.readFileSync(redirects, 'utf8').split('\n')) {
      const [from, to, status = '301'] = line.trim().split(/\s+/);
      if (from?.startsWith('/') && !from.startsWith('//') && to && /^30[1278]!?$/.test(status)) config.aliases.push({ path: from, destination: to.startsWith('/') && !to.startsWith('//') ? to : (() => { try { const url = new URL(to); return url.pathname + url.search + url.hash; } catch { return to; } })() });
    }
    for (const [route, document] of Object.entries(documents)) {
      if (!document.originalUrl) continue;
      try {
        const source = new URL(document.originalUrl);
        if (source.pathname !== route) config.aliases.push({ path: source.pathname + source.search, destination: route });
      } catch { /* absent route evidence */ }
    }
    for (const row of receipt?.duplicateRoutes ?? []) {
      try { config.aliases.push({ path: new URL(row.url).pathname, destination: new URL(row.canonicalUrl).pathname }); } catch { /* absent evidence */ }
    }
    config.aliases = [...new Map(config.aliases.map(alias => [alias.path, alias])).values()];
  }
  if (receipt?.source?.url) {
    try { config.sourceHosts = [new URL(receipt.source.url).host]; } catch { /* absent evidence */ }
  }
  const cleanup = reports.find(report => /^blocks-engine-capture\/source-cleanup\/v\d+$/.test(report.schema));
  if (cleanup && receipt.cleanup?.complete === true && Array.isArray(cleanup.pages) && cleanup.pages.length && cleanup.pages.every(page => Array.isArray(page.reports) && page.reports.length && page.reports.every(report => Array.isArray(report.records) && !report.truncated && !report.failures?.length))) {
    const matched = cleanup.pages.flatMap(page => page.reports.flatMap(report => report.records.flatMap(record => {
      const rule = page.policy?.rules?.find(rule => rule.id === record.rule) ?? receipt.cleanup?.policy?.rules?.find(rule => rule.id === record.rule);
      return ['telemetry', 'analytics'].includes(record.category ?? rule?.category) && ['remove', 'removed'].includes(record.action) ? [{ ...rule, ...record }] : [];
    })));
    config.telemetrySelectors = [...new Set(matched.map(record => record.selector).filter(value => typeof value === 'string' && value))];
    config.telemetryMarkers = [...new Set(matched.flatMap(record => [record.host, ...(Array.isArray(record.hosts) ? record.hosts : []), record.selector].filter(value => typeof value === 'string' && value)))];
  }
  const interactions = reports.find(report => /^blocks-engine-capture\/captured-interactions\/v[12]$/.test(report.schema));
  const pages = [], widths = new Set();
  for (const [route, document] of Object.entries(config.documents)) {
    document.sourceOrigin = sourceOrigin;
    const probe = await browser.newPage();
    try {
      const response = await probe.goto(new URL(route, candidateOrigin).href);
      if (response?.status() !== 200 || new URL(probe.url()).origin !== candidateOrigin) continue;
      const source = await probe.evaluate(html => {
        const dom = new DOMParser().parseFromString(html, 'text/html');
        return { main: dom.querySelectorAll('main').length };
      }, document.html);
      // Scope classes describe the document scope, not necessarily post content.
      // Accept them only when the capture explicitly identifies a content landmark.
      const declared = [receipt?.contentLandmark, ...(receipt?.document_scope_classes ?? []).filter(value => /^[\w-]+$/.test(value)).map(value => `.${value}`)].filter(value => typeof value === 'string');
      let boundary = null;
      for (const selector of declared) {
        const unique = await probe.evaluate(({ html, selector }) => {
          const source = new DOMParser().parseFromString(html, 'text/html').querySelectorAll(selector);
          const candidate = document.querySelectorAll(selector);
          return source.length === 1 && candidate.length === 1 && !source[0].matches('html,body') && !candidate[0].matches('html,body');
        }, { html: document.html, selector });
        if (unique) { boundary = selector; break; }
      }
      boundary ??= source.main === 1 && await probe.locator('main').count() === 1 ? 'main' : null;
      if (boundary) { document.sourceContent = boundary; document.candidateContent = boundary; }
      const captures = interactions?.pages?.filter(page => {
        try { return new URL(page.sourceUrl).pathname === new URL(document.originalUrl ?? route, candidateOrigin).pathname; } catch { return false; }
      }) ?? [];
      // Multiple viewport captures describe the same source inventory. Keep all
      // controls, deduplicating identical evidence, and test at every width.
      if (!captures.length) continue;
      for (const capture of captures) if (Number.isInteger(capture.viewport?.width)) widths.add(capture.viewport.width);
      const capture = captures[0];
      const states = [], navigation = [], forms = [];
      const identity = async selector => {
        if (typeof selector !== 'string') return null;
        return probe.evaluate(({ html, selector }) => {
          let matches;
          try { matches = new DOMParser().parseFromString(html, 'text/html').querySelectorAll(selector); } catch { return null; }
          if (matches.length !== 1) return null;
          const node = matches[0];
          const identities = node.id ? [`#${CSS.escape(node.id)}`] : [];
          for (const attribute of node.attributes) if (attribute.name.startsWith('data-blocks-engine-capture-')) identities.push(`[${CSS.escape(attribute.name)}=${JSON.stringify(attribute.value)}]`);
          return identities.find(identity => node.ownerDocument.querySelectorAll(identity).length === 1 && document.querySelectorAll(identity).length === 1) ?? null;
        }, { html: document.html, selector });
      };
      const components = [...new Map(captures.flatMap(page => page.components ?? page.states ?? []).map(item => [JSON.stringify(item), item])).values()];
      for (const [index, item] of components.entries()) {
        const id = `${route}:control:${index}`;
        states.push({ ...item, id });
        const transition = item.transitions?.find(row => row.from === item.initial);
        const trigger = await identity(transition?.trigger?.selector);
        const target = await identity(item.selector);
        // Visibility declarations or changed accessibility/hidden attributes in
        // two captured snapshots provide expectations; state names do not.
        const after = item.states?.find(row => row.id === transition?.to);
        const before = item.states?.find(row => row.id === transition?.from);
        let expectation = typeof after?.visible === 'boolean' ? { expected: after.visible } : null;
        if (!expectation && target && before?.html && after?.html) expectation = await probe.evaluate(({ before, after, target }) => {
          const read = html => {
            const dom = new DOMParser().parseFromString(html, 'text/html');
            const nodes = dom.querySelectorAll(target);
            return nodes.length === 1 ? nodes[0] : null;
          };
          const a = read(before), b = read(after);
          if (!a || !b) return null;
          for (const attribute of ['hidden', 'aria-expanded', 'aria-hidden']) {
            if (a.getAttribute(attribute) !== b.getAttribute(attribute)) return { attribute, expected: b.getAttribute(attribute) };
          }
          return null;
        }, { before: before.html, after: after.html, target });
        config.controls[id] = !['unsupported', 'partial'].includes(item.status) && trigger && target && expectation && transition?.trigger?.action === 'click'
          ? { trigger, target, ...expectation }
          : { unsupportedReason: 'preserved_identity_and_captured_transition_required' };
      }
      const nav = [...new Map(captures.flatMap(page => page.routeNavigation ?? page.navigation ?? []).map(item => [JSON.stringify(item), item])).values()];
      for (const [index, item] of nav.entries()) {
        const id = `${route}:navigation:${index}`;
        navigation.push({ ...item, id, destination: item.url ?? item.destination });
        const trigger = await identity(item.selector);
        config.controls[id] = trigger ? { trigger } : { unsupportedReason: 'preserved_navigation_identity_required' };
      }
      // The source document itself exhaustively enumerates native forms. No
      // recipient, successful delivery or submission assertion is invented.
      const sourceForms = await probe.evaluate(html => [...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('form')].map(node => ({ selector: node.id ? `#${node.id}` : null })), document.html);
      const capturedForms = captures.flatMap(page => page.forms ?? []);
      const allForms = [...new Map([...sourceForms, ...capturedForms].map((item, index) => [item.selector ?? `unidentified:${index}`, item])).values()];
      for (const [index, item] of allForms.entries()) {
        const id = `${route}:form:${index}`;
        forms.push({ ...item, id });
        const selector = await identity(item.selector);
        config.forms[id] = selector ? { selector } : { unsupportedReason: 'preserved_form_identity_required' };
      }
      if (captures.every(page => Array.isArray(page.components ?? page.states) && Array.isArray(page.routeNavigation ?? page.navigation))) pages.push({ route, sourceUrl: capture.sourceUrl, interactionStates: { schema: capture.schema, states }, navigation, forms });
    } catch {
      // A failed DOM probe supplies no binding; the outcome checker retains
      // this route as unsupported rather than losing the entire corpus import.
    } finally { await probe.close(); }
  }
  if (interactions) config.capture = { schema: 'static-site-importer/interactive-capture/v1', pages };
  if (widths.size) config.widths = [...widths].sort((a, b) => a - b);
  // Explicit values override derived values at the individual document/control
  // level as well as at the top level.
  return { ...config, ...bindings, ...(Object.hasOwn(bindings, 'telemetryMarkers') ? { telemetrySelectors: bindings.telemetrySelectors ?? [] } : {}), documents: Object.fromEntries([...new Set([...Object.keys(config.documents), ...Object.keys(bindings.documents ?? {})])].map(route => [route, { ...config.documents[route], ...bindings.documents?.[route] }])), controls: { ...config.controls, ...bindings.controls }, forms: { ...config.forms, ...bindings.forms } };
}
