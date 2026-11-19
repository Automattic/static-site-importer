import { execFileSync } from 'node:child_process';

// Shared by the editor and authoring collectors against an existing import.
export function wordpressRunner(site, wpCli) {
  const [command, ...prefix] = wpCli.split(/\s+/);
  return (...argv) => execFileSync(command, [...prefix, `--path=${site}`, ...argv], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: Infinity }).trim();
}
