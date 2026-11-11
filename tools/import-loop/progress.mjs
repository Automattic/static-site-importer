// Counts are actual block instances where evidence supplies a count, not
// merely the number of route/width cells carrying the finding.
export function occurrences(finding, cells) {
  return (finding?.occurrences || []).filter((o) => !cells || cells.some((c) => c.route === o.route && (c.width == null || c.width === o.width)))
    .reduce((sum, o) => sum + (Number.isInteger(o.evidence?.count) ? o.evidence.count : 1), 0);
}

export function compareImports(before, after, signature, cells) {
  const blockers = { removed: [], added: [], unchanged: [] };
  const a = new Map(before.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, f]));
  const b = new Map(after.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, f]));
  for (const key of new Set([...a.keys(), ...b.keys()])) {
    const was = occurrences(a.get(key)), now = occurrences(b.get(key));
    blockers[now < was ? 'removed' : now > was ? 'added' : 'unchanged'].push({ signature: key, before: was, after: now, delta: now - was });
  }
  const regressions = [];
  const current = new Map((after.visual || []).map((v) => [`${v.route}@${v.width}`, v]));
  for (const v of before.visual || []) {
    const key = `${v.route}@${v.width}`, next = current.get(key);
    // Compare each state/profile, not just route-wide pass/fail. A newly
    // unmeasured cell is a regression, never an improvement.
    if (!next?.verified || !v.verified) { regressions.push(key); continue; }
    for (const score of v.scores) {
      const n = next.scores.find((s) => s.state === score.state && s.profile === score.profile);
      if (!n || (n.failures || []).some((failure) => !(score.failures || []).includes(failure))) regressions.push(key);
    }
  }
  const own = { before: occurrences(a.get(signature), cells), after: occurrences(b.get(signature), cells) };
  const unchecked = [...before.findings, ...after.findings].filter((f) => /unverified|unreachable|missing/.test(f.check || '') && ['editor_unreachable', 'theme_checks_unverified', 'visual_parity_unverified', 'import_report_missing'].includes(f.check));
  const pass = Boolean(signature && own.before > own.after && !unchecked.length && !blockers.added.some((f) => f.signature !== signature) && !regressions.length && before.visual?.length && after.visual?.length);
  return { pass, own, blockers, visual_regressions: [...new Set(regressions)] };
}
