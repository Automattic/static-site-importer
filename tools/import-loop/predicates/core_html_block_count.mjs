// Exit 0 while the candidate's materialized content contains core/html,
// 1 once it does not, 2 on infrastructure failure. Requires an imported site
// with SSI active (WORDPRESS_PATH and WP_CLI, as in render.mjs).
import { spawnSync } from 'node:child_process';

const [transformer, candidate] = process.argv.slice(2);
try {
  if (!transformer || !candidate || !process.env.WORDPRESS_PATH) throw new Error('missing predicate arguments or WORDPRESS_PATH');
  const [command, ...prefix] = (process.env.WP_CLI || 'wp').split(/\s+/);
  const helper = new URL('./core-html.php', import.meta.url).pathname;
  const result = spawnSync(command, [...prefix, `--path=${process.env.WORDPRESS_PATH}`, 'eval-file', helper, transformer, candidate], { encoding: 'utf8', maxBuffer: 512 << 20 });
  if (result.status !== 0) throw new Error(result.stderr || 'materialization failed');
  const count = JSON.parse(result.stdout);
  if (!Number.isInteger(count) || count < 0) throw new Error('invalid core/html count');
  process.exit(count > 0 ? 0 : 1);
} catch (error) {
  console.error(error.message);
  process.exit(2);
}
