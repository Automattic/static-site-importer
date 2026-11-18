// Attribution is posted only after triage has issue URLs, including newly
// filed findings. A range-specific marker makes retries and cache hits safe.
export function reportRegression(bisect, tracked, site, gh) {
  if (bisect?.status !== 'complete' || !bisect.culprit) return new Map();
  const { culprit } = bisect;
  const regressed = bisect.signatures.filter((s) => bisect.after[s.signature] > s.before);
  const marker = `<!-- import-loop:regressed-by -->\n<!-- ${site} ${bisect.prev}..${bisect.cur} -->`;
  const notes = new Map();
  const lines = [];
  const issueLines = new Map();
  const post = (url, body) => {
    const comments = JSON.parse(gh('issue', 'view', url, '--json', 'comments')).comments;
    if (!comments.some((c) => c.body.includes(marker))) gh('issue', 'comment', url, '--body', `${marker}\n${body}`);
  };
  for (const item of tracked) {
    const signature = regressed.find((s) => s.signature === item.signature);
    if (!signature) continue;
    const cells = signature.cells.map((c) => `${c.route ?? 'site'}@${c.width ?? 'all'}`).join(', ');
    const note = `Regressed on ${site} by ${culprit.pr} (\`${culprit.commit}\`): count ${signature.before} → ${bisect.after[signature.signature]} on ${cells}.`;
    issueLines.set(item.issue_url, [...(issueLines.get(item.issue_url) || []), note]);
    notes.set(item.signature, `${note}\n\nCulprit diff stat:\n\`\`\`\n${culprit.diff_stat}\n\`\`\``);
    lines.push(`- ${site}: ${item.issue_url} (\`${item.signature}\`), ${signature.before} → ${bisect.after[signature.signature]} on ${cells}`);
  }
  for (const [url, messages] of issueLines) post(url, messages.join('\n\n'));
  if (lines.length) post(culprit.pr, `This change regressed these import-loop findings:\n${lines.join('\n')}`);
  return notes;
}
