// Retention: a pass's WordPress sites are working state, not evidence. Every
// re-import (the pass site, triage's trunk site, each head checked) is a full
// WordPress copy of about 300 MB served by its own `php -S`. Once a slug's pass
// is scored, nothing reads them again: the next pass re-imports from the
// capture, and Cooks carry their own copies. retain() stops every server the
// slug's passes started and removes their sites, keeping only the scored
// pass's own site, stopped, as the latest result to inspect. Findings,
// triage, confirm verdicts, prompts and logs stay: they are small and they are
// the record.
//
// sweep() applies the same to every slug whose scoreboard has not moved for
// `staleDays`: a chain that was cancelled or crashed never scores its last pass.
import fs from 'node:fs';
import path from 'node:path';

const PASS = /^(.+)-p(\d+)(-.*)?$/;
const dirs = (dir) => { try { return fs.readdirSync(dir, { withFileTypes: true }).filter((d) => d.isDirectory()).map((d) => path.join(dir, d.name)); } catch { return []; } };

// Each site's server leads its own process group (reimport.sh starts it under
// setsid), with PHP_CLI_SERVER_WORKERS workers: stop the whole group.
export function stopServer(site) {
  try { process.kill(-Number(fs.readFileSync(path.join(site, '.server.pid'), 'utf8')), 'SIGTERM'); } catch {}
}

// Every re-imported site under one pass directory: <pass>/site,
// <pass>-triage/trunk-site, and <…>/heads/<head>-site at any depth.
function sites(dir) {
  const found = [];
  const walk = (d, depth) => {
    for (const child of dirs(d)) {
      if (fs.existsSync(path.join(child, '.server.pid')) || /(^|-)site$/.test(path.basename(child)) && fs.existsSync(path.join(child, 'wp-config.php'))) found.push(child);
      else if (depth < 4) walk(child, depth + 1);
    }
  };
  walk(dir, 0);
  return found;
}

export function retain(runs, slug, keep) {
  const kept = path.join(runs, `${slug}-p${keep}`, 'site');
  let removed = 0;
  for (const dir of dirs(runs)) {
    const [, owner] = path.basename(dir).match(PASS) || [];
    if (owner !== slug) continue;
    for (const site of sites(dir)) {
      stopServer(site);
      if (site === kept) continue;
      fs.rmSync(site, { recursive: true, force: true });
      removed++;
    }
  }
  return removed;
}

export function sweep(runs, staleDays = 2) {
  const cutoff = Date.now() - staleDays * 86400e3;
  return fs.readdirSync(runs).filter((name) => name.endsWith('-scoreboard.jsonl')).flatMap((name) => {
    const board = path.join(runs, name);
    if (fs.statSync(board).mtimeMs > cutoff) return [];
    const slug = name.replace(/-scoreboard\.jsonl$/, '');
    const last = fs.readFileSync(board, 'utf8').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line)).at(-1);
    return [{ slug, removed: retain(runs, slug, last?.iteration ?? 0) }];
  });
}
