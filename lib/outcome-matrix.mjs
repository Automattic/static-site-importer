import { unavailableOutcomes } from './outcome-checks.mjs';

export const OUTCOME_MATRIX_SCHEMA = 'static-site-importer/outcome-matrix/v1';

/** Preserve evidence locations, including unavailable checks. No missing cell passes. */
export function outcomeMatrix(imports, previous) {
  const checks = Object.keys(unavailableOutcomes('', [], '').checks);
  const sites = imports.map(row => row.id);
  if (new Set(sites).size !== sites.length) throw new Error('Duplicate corpus site id');
  const cells = Object.fromEntries(checks.map(check => [check, Object.fromEntries(imports.map(row => {
    const result = row.report?.checks?.[check];
    const status = ['pass', 'fail'].includes(result?.status) ? result.status : 'unavailable';
    return [row.id, { status, evidence: `${row.evidence}${row.evidence.includes('#') ? '' : '#'}\/checks/${check}`, ...(status === 'unavailable' ? { reason: result?.evidence?.find(item => item.reason)?.reason ?? row.reason ?? 'outcome_evidence_missing' } : {}) }];
  }))]));
  if (previous && previous.schema !== OUTCOME_MATRIX_SCHEMA) throw new Error('Unsupported previous outcome matrix');
  const changes = [];
  if (previous) for (const check of new Set([...checks, ...Object.keys(previous.cells)])) {
    for (const site of new Set([...sites, ...previous.sites])) {
      const before = previous.cells[check]?.[site] ?? null;
      const after = cells[check]?.[site] ?? null;
      // Paths change on each run; status/reason changes are the comparable outcome.
      if (before?.status !== after?.status || before?.reason !== after?.reason) changes.push({ check, site, before, after });
    }
  }
  return { schema: OUTCOME_MATRIX_SCHEMA, status: imports.length && Object.values(cells).every(row => Object.values(row).every(cell => cell.status === 'pass')) ? 'passed' : 'failed', sites, cells, changes };
}

export function outcomeMatrixMarkdown(matrix) {
  const escape = value => String(value).replaceAll('|', '\\|').replaceAll('\n', ' ');
  const lines = ['# Outcome matrix', '', `Status: ${matrix.status}`, '', `| Check | ${matrix.sites.map(escape).join(' | ')} |`, `| --- | ${matrix.sites.map(() => '---').join(' | ')} |`];
  for (const [check, cells] of Object.entries(matrix.cells)) lines.push(`| ${check} | ${matrix.sites.map(site => {
    const cell = cells[site];
    return escape(`${cell.status}${cell.reason ? ` (${cell.reason})` : ''} [evidence](${cell.evidence})`);
  }).join(' | ')} |`);
  lines.push('', '## Changed cells', '');
  for (const change of matrix.changes) lines.push(`- ${escape(change.check)} × ${escape(change.site)}: ${change.before?.status ?? 'absent'} → ${change.after?.status ?? 'absent'}`);
  return `${lines.join('\n')}\n`;
}
