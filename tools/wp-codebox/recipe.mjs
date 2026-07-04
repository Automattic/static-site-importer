import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const WP_CODEBOX_RECIPE_MAX_BUFFER = 64 * 1024 * 1024;

function externalHelper() {
  const helperPath = process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER;
  return helperPath ? require(helperPath) : null;
}

export function wpCodeboxBin(env = process.env) {
  const helper = externalHelper();
  if (helper?.wpCodeboxBin) {
    return helper.wpCodeboxBin(env);
  }
  return env.SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN || env.HOMEBOY_WP_CODEBOX_BIN || env.WP_CODEBOX_BIN || 'wp-codebox';
}

export function wpCodeboxCommand(bin = wpCodeboxBin()) {
  const helper = externalHelper();
  if (helper?.wpCodeboxCommand) {
    return helper.wpCodeboxCommand(bin);
  }
  return { command: bin, args: [] };
}

export async function runWpCodeboxRecipe(options = {}) {
  const helper = externalHelper();
  if (helper?.runWpCodeboxRecipe) {
    return helper.runWpCodeboxRecipe(options);
  }

  const base = wpCodeboxCommand(options.wpCodeboxBin || wpCodeboxBin());
  const args = [
    ...(base.args || []),
    'recipe-run',
    '--recipe', options.recipeFile,
    '--artifacts', options.artifactsDir,
    '--json',
  ].filter(Boolean);
  const result = spawnSync(base.command, args, {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    maxBuffer: WP_CODEBOX_RECIPE_MAX_BUFFER,
  });
  if (options.outputFile) {
    fs.writeFileSync(options.outputFile, result.stdout || '', 'utf8');
  }
  const parsed = parseJsonText(result.stdout);
  if (result.status !== 0) {
    const error = new Error(childFailureMessage(result));
    error.code = result.error?.code || result.status || 1;
    error.signal = result.signal || result.error?.signal || '';
    error.stdout = result.stdout || '';
    error.stderr = result.stderr || '';
    throw error;
  }

  return {
    exitCode: 0,
    outputFile: options.outputFile,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
    json: parsed,
  };
}

function childFailureMessage(result) {
  const parts = [];
  if (result.status !== null && result.status !== undefined) {
    parts.push(`exit ${result.status}`);
  }
  if (result.signal) {
    parts.push(`signal ${result.signal}`);
  }
  if (result.error?.code) {
    parts.push(`spawn ${result.error.code}`);
  }
  if (result.error?.message) {
    parts.push(result.error.message);
  }
  return `wp-codebox recipe-run failed with ${parts.join(', ') || 'unknown child process failure'}`;
}

function parseJsonText(text) {
  if (!text || !text.trim()) {
    return null;
  }
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}
