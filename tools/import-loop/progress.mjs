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

// A check that could not run: its findings are missing, not fixed.
export const UNCHECKED = new Set(['editor_unreachable', 'theme_checks_unverified', 'visual_parity_unverified', 'import_report_missing']);

export function compareImports(before, after, signature, cells) {
  const blockers = { removed: [], added: [], unchanged: [] };
  const a = new Map(before.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, f]));
  const b = new Map(after.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, f]));
  for (const key of new Set([...a.keys(), ...b.keys()])) {
    const was = occurrences(a.get(key)), now = occurrences(b.get(key));
    const wasExcess = excess(a.get(key)), nowExcess = excess(b.get(key));
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
      if (!n || (n.failures || []).some((failure) => !(score.failures || []).includes(failure))) regressions.push(key);
    }
  }
  const own = { before: occurrences(a.get(signature), cells), after: occurrences(b.get(signature), cells) };
  const ownExcess = { before: excess(a.get(signature), cells), after: excess(b.get(signature), cells) };
  if (ownExcess.before || ownExcess.after) own.excess = ownExcess;
  const unchecked = [...before.findings, ...after.findings].filter((f) => UNCHECKED.has(f.check));
  const measured = (before.visual || []).length > unmeasured.length;
  const pass = Boolean(signature && change(own.before, own.after, ownExcess.before, ownExcess.after) < 0 && !unchecked.length && !blockers.added.some((f) => f.signature !== signature) && !regressions.length && measured && after.visual?.length);
  return { pass, own, blockers, visual_regressions: [...new Set(regressions)], visual_unmeasured: unmeasured };
}
