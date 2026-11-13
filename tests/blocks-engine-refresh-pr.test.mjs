import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { spawnSync } from 'node:child_process';

const script = resolve('scripts/release/blocks-engine-refresh-pr.sh');
test('failed refresh composes incident body, creates or updates one PR, and retires it after release', () => {
  const root = mkdtempSync(join(tmpdir(), 'refresh-pr-'));
  const cwd = join(root, 'repo');
  mkdirSync(cwd);
  const bin = join(root, 'bin');
  mkdirSync(bin);
  const capture = join(root, 'calls');
  const body = join(root, 'body');
  writeFileSync(join(bin, 'gh'), `#!/usr/bin/env bash
set -eu
printf '%s\\n' "$*" >> "$CAPTURE"
if [[ "$1 $2" == 'pr list' ]]; then printf '%s' "\${OPEN_PR:-}"; fi
if [[ "$1 $2" == 'release view' ]]; then echo https://github.com/example/ssi/releases/tag/v1.2.3; fi
while [[ $# -gt 0 ]]; do
  if [[ "$1" == --body-file ]]; then cp "$2" "$BODY"; break; fi
  shift
done
`, { mode: 0o755 });
  const env = { ...process.env, PATH: `${bin}:${process.env.PATH}`, CAPTURE: capture, BODY: body,
    GITHUB_REPOSITORY: 'example/ssi', GITHUB_RUN_ID: '123', HOMEBOY_DRY_RUN: 'false' };
  const run = (cmd, args, extra = {}) => spawnSync(cmd, args, { cwd, env: { ...env, ...extra }, encoding: 'utf8' });
  const git = (...args) => {
    const result = run('git', args);
    assert.equal(result.status, 0, result.stderr);
    return result.stdout.trim();
  };
  const lock = (version) => JSON.stringify({ packages: ['php', 'figma'].map(name => ({
    name: `automattic/blocks-engine-${name}-transformer`, version,
  })) });
  try {
    git('init', '--initial-branch=main');
    git('config', 'user.name', 'Test');
    git('config', 'user.email', 'test@example.com');
    writeFileSync(join(cwd, 'composer.lock'), lock('0.1.0'));
    writeFileSync(join(cwd, 'composer.json'), '{}');
    git('add', '.');
    git('commit', '-m', 'initial');
    git('init', '--bare', join(root, 'remote.git'));
    git('remote', 'add', 'origin', join(root, 'remote.git'));
    git('push', 'origin', 'main');
    const main = git('rev-parse', 'HEAD');
    const log = join(root, 'test.log');
    writeFileSync(log, 'HOST_SMOKE_FAIL:tests/smoke-example.php:exit=1\nJS_SMOKE_FAIL:tests/example.test.mjs:exit=1\nnot ok 1 - compiler contract\n1) ExampleTest::testCompat\n');
    let created = '';
    for (const existing of ['', '42']) {
      git('reset', '--hard', main);
      writeFileSync(join(cwd, 'composer.lock'), lock(existing ? '0.3.0' : '0.2.0'));
      writeFileSync(capture, '');
      assert.equal(run('bash', [script, 'failure', log], { OPEN_PR: existing }).status, 1);
      const calls = readFileSync(capture, 'utf8');
      assert.match(calls, existing ? /pr edit 42/ : /pr create/);
      assert.doesNotMatch(calls, existing ? /pr create/ : /pr edit/);
      const text = readFileSync(body, 'utf8');
      for (const expected of ['php-transformer: `0.1.0`', 'figma-transformer: `0.1.0`', existing ? '`0.3.0`' : '`0.2.0`',
        '/actions/runs/123', 'tests/smoke-example.php', 'tests/example.test.mjs', 'compiler contract', 'ExampleTest::testCompat',
        'Push compat fixes', 'maintainer', 'GITHUB_TOKEN', 'never reset', '/pull/2940']) assert.ok(text.includes(expected), expected);
      assert.equal(git('rev-parse', 'refs/remotes/origin/main'), main);
      const incident = git('ls-remote', '--heads', 'origin', 'refs/heads/deps/blocks-engine-refresh').split(/\s/)[0];
      if (existing) {
        // An open PR keeps its branch (and any maintainer compat commits) untouched.
        assert.equal(incident, created);
        assert.equal(git('rev-parse', 'HEAD'), main);
      } else {
        created = incident;
        assert.equal(git('log', '-1', '--format=%s', incident), 'fix(deps): refresh Blocks Engine pins to latest release');
        assert.equal(git('log', '-1', '--format=%an', incident), 'github-actions[bot]');
      }
    }
    writeFileSync(capture, '');
    assert.equal(run('bash', [script, 'failure', log], { HOMEBOY_DRY_RUN: 'true' }).status, 0);
    assert.equal(readFileSync(capture, 'utf8'), '');
    assert.equal(run('bash', [script, 'resolved'], { OPEN_PR: '42' }).status, 0);
    assert.match(readFileSync(capture, 'utf8'), /pr close 42.*releases\/tag\/v1.2.3/);
    assert.equal(git('ls-remote', '--heads', 'origin', 'refs/heads/deps/blocks-engine-refresh'), '');
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
