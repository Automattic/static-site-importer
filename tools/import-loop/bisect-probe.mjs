#!/usr/bin/env node
// Isolated probe: reimportSite owns its server until this process exits.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { args, reimportSite } from './lib.mjs';
import { checkImport } from './checks.mjs';
import { occurrences, UNCHECKED } from './progress.mjs';
const config = JSON.parse(fs.readFileSync(args.config, 'utf8'));
const { commit, dir, clone, paths, reimport, source, url, signatures } = config;
execFileSync('bash', [new URL('./gates/merge-onto.sh', import.meta.url).pathname, clone, commit, commit, path.join(dir, 'head'), ...paths]);
const site = path.join(dir, 'site');
const wpUrl = reimportSite(path.join(dir, 'head', 'php-transformer'), reimport, site);
const { result } = await checkImport({ url, source, site, 'check-scope': signatures, 'wp-url': wpUrl, 'wp-cli': `php ${reimport.bare_wp}/wp-cli.phar`, out: path.join(dir, 'checks') });
if (result.findings.some((f) => UNCHECKED.has(f.check)) || result.visual.some((c) => !c.verified)) throw new Error('probe checks incomplete');
const counts = Object.fromEntries(signatures.map(({ signature, cells }) => [signature, occurrences(result.findings.find((f) => f.signature === signature), cells)]));
fs.writeFileSync(path.join(dir, 'counts.json'), JSON.stringify(counts));
