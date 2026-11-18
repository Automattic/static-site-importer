// Counts are actual block instances where evidence supplies a count, not
// merely the number of route/width cells carrying the finding.
const inCells = (finding, cells) => (finding?.occurrences || []).filter((o) => !cells || cells.some((c) => c.route === o.route && (c.width == null || c.width === o.width)));
export function occurrences(finding, cells) {
  return inCells(finding, cells).reduce((sum, o) => sum + (Number.isInteger(o.evidence?.count) ? o.evidence.count : 1), 0);
}

// A threshold check records how far each occurrence is past its limit
// (`evidence.measure`: { value, limit }). Their total shows progress an
// occurrence count cannot: CSS from 125 KB to 100 KB is still one route over
// 90 KB.
export function excess(finding, cells) {
  const total = inCells(finding, cells).reduce((sum, o) => sum + (o.evidence?.measure ? Math.max(0, o.evidence.measure.value - o.evidence.measure.limit) : 0), 0);
  return Math.round(total * 1000) / 1000;
}
// Fewer occurrences, or as many and less excess past their limits.
const change = (was, now, wasExcess, nowExcess) => (now !== was ? now - was : Math.sign(nowExcess - wasExcess));

// The scorer emits each defect as one failure string whose leading words name
// its class. One finding per class gives a fix to one class its own signature,
// owner and count, instead of an all-defects bundle that only clears when every
// failure is gone. Title is the imported site's identity, produced by SSI, not
// the materializer; every other class belongs to Blocks Engine until a class
// proves another owner.
export const parityFailureClass = (failure) => {
  const text = String(failure);
  const known = [
    [/^title /, 'title', 'static-site-importer'],
    [/^nav /, 'link', 'blocks-engine'],
    [/^viewport animation/, 'motion', 'blocks-engine'],
    [/^text position/, 'text_position', 'blocks-engine'],
    [/^text \d/, 'text_content', 'blocks-engine'],
    [/^image geometry/, 'image_geometry', 'blocks-engine'],
    [/^background geometry/, 'background_geometry', 'blocks-engine'],
    [/^page height/, 'page_height', 'blocks-engine'],
    [/^images /, 'image_decoding', 'blocks-engine'],
    [/^pixel region /, 'pixel_region', 'blocks-engine'],
  ].find(([pattern]) => pattern.test(text));
  if (known) return { subject: known[1], owner_repo: known[2] };
  // A class this table has not seen: its leading words still give a stable
  // subject, so the scorer's new class is measured on its own, not bundled.
  const words = text.toLowerCase().match(/[a-z]+/g)?.slice(0, 3).join('_');
  return { subject: words || 'other', owner_repo: 'blocks-engine' };
};

// A state's failures as counts per class, the measure its finding reports:
// a class that appears or grows is a regression. The numbers inside a
// message are not compared: the source side of `text N chars !== source M`
// varies between captures of the same frozen route, and a fix that halves a
// mismatch still reads as a different string.
const classCounts = (failures) => (failures || []).map((f) => parityFailureClass(f).subject).reduce((m, cls) => m.set(cls, (m.get(cls) || 0) + 1), new Map());
const regressed = (before, after) => [...classCounts(after)].some(([cls, n]) => n > (classCounts(before).get(cls) || 0));

// A check that could not run: its findings are missing, not fixed.
export const UNCHECKED = new Set(['editor_unreachable', 'theme_checks_unverified', 'visual_parity_unverified', 'import_report_missing']);

// Blockers are compared on the routes both imports measured. A candidate that
// changes the capture's scope (a capture client that keeps routes the release
// folded away) measures routes the base never did: their blockers are newly
// measured scope, reported in `new_scope`, not a regression. Site-wide
// occurrences (no route) always count.
const onRoutes = (finding, routes) => finding && routes ? { ...finding, occurrences: finding.occurrences.filter((o) => o.route == null || routes.has(o.route)) } : finding;

export function compareImports(before, after, signature, cells) {
  const blockers = { removed: [], added: [], unchanged: [] };
  const shared = before.routes && after.routes ? new Set(before.routes.filter((r) => after.routes.includes(r))) : null;
  const a = new Map(before.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, f]));
  const b = new Map(after.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, f]));
  const new_scope = { routes: shared ? after.routes.filter((r) => !shared.has(r)) : [], blockers: shared ? [...b.values()].map((f) => ({ signature: f.signature, count: occurrences(f) - occurrences(onRoutes(f, shared)) })).filter((f) => f.count > 0) : [] };
  for (const key of new Set([...a.keys(), ...b.keys()])) {
    const was = occurrences(onRoutes(a.get(key), shared)), now = occurrences(onRoutes(b.get(key), shared));
    const wasExcess = excess(onRoutes(a.get(key), shared)), nowExcess = excess(onRoutes(b.get(key), shared));
    const moved = change(was, now, wasExcess, nowExcess);
    blockers[moved < 0 ? 'removed' : moved > 0 ? 'added' : 'unchanged'].push({ signature: key, before: was, after: now, delta: now - was, ...(wasExcess || nowExcess ? { excess: { before: wasExcess, after: nowExcess } } : {}) });
  }
  const regressions = [], unmeasured = [];
  const current = new Map((after.visual || []).map((v) => [`${v.route}@${v.width}`, v]));
  for (const v of before.visual || []) {
    const key = `${v.route}@${v.width}`, next = current.get(key);
    // Compare each state/profile, not just route-wide pass/fail. A newly
    // unmeasured cell is a regression, never an improvement. A cell the
    // compare could not measure on either side (e.g. no frozen reference for
    // that route) has nothing to regress from: it is reported, not failed.
    if (!v.verified && !next?.verified) { unmeasured.push(key); continue; }
    if (!next?.verified || !v.verified) { regressions.push(key); continue; }
    for (const score of v.scores) {
      const n = next.scores.find((s) => s.state === score.state && s.profile === score.profile);
      if (!n || regressed(score.failures, n.failures)) regressions.push(key);
    }
  }
  const own = { before: occurrences(a.get(signature), cells), after: occurrences(b.get(signature), cells) };
  const ownExcess = { before: excess(a.get(signature), cells), after: excess(b.get(signature), cells) };
  if (ownExcess.before || ownExcess.after) own.excess = ownExcess;
  const unchecked = [...before.findings, ...after.findings].filter((f) => UNCHECKED.has(f.check));
  const measured = (before.visual || []).length > unmeasured.length;
  const pass = Boolean(signature && change(own.before, own.after, ownExcess.before, ownExcess.after) < 0 && !unchecked.length && !blockers.added.some((f) => f.signature !== signature) && !regressions.length && measured && after.visual?.length);
  return { pass, own, blockers, new_scope, visual_regressions: [...new Set(regressions)], visual_unmeasured: unmeasured };
}
