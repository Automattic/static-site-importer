import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const WP_CODEBOX_RECIPE_MAX_BUFFER = 64 * 1024 * 1024;
const WP_CODEBOX_RECIPE_TAIL_BYTES = 64 * 1024;

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
  const args = recipeRunArgs(base, options, { output: Boolean(options.outputFile) });

  if (options.outputFile) {
    const result = await spawnRecipeWithTails(base.command, args);
    if (result.status !== 0 && recipeRunOutputUnsupported(result)) {
      return runWpCodeboxRecipeStdoutFallback(base, options);
    }

    const output = readTextFile(options.outputFile);
    const parsed = parseJsonText(output);
    if (result.status !== 0) {
      throw childFailureError(result);
    }

    return {
      exitCode: 0,
      outputFile: options.outputFile,
      stdout: result.stdout || '',
      stderr: result.stderr || '',
      json: parsed,
    };
  }

  const result = spawnSync(base.command, args, {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    maxBuffer: WP_CODEBOX_RECIPE_MAX_BUFFER,
  });
  const parsed = parseJsonText(result.stdout);
  if (result.status !== 0) {
    throw childFailureError(result);
  }

  return {
    exitCode: 0,
    outputFile: options.outputFile,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
    json: parsed,
  };
}

function recipeRunArgs(base, options, { output }) {
  return [
    ...(base.args || []),
    'recipe-run',
    '--recipe', options.recipeFile,
    '--artifacts', options.artifactsDir,
    ...(output && options.outputFile ? ['--output', options.outputFile] : []),
    '--json',
  ].filter(Boolean);
}

async function runWpCodeboxRecipeStdoutFallback(base, options) {
  const result = await spawnRecipeToOutputFile(base.command, recipeRunArgs(base, options, { output: false }), options.outputFile);
  const output = readTextFile(options.outputFile);
  const parsed = parseJsonText(output);
  if (result.status !== 0) {
    throw childFailureError(result);
  }

  return {
    exitCode: 0,
    outputFile: options.outputFile,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
    json: parsed,
  };
}

function childFailureError(result) {
  const error = new Error(childFailureMessage(result));
  error.code = result.error?.code || result.status || 1;
  error.signal = result.signal || result.error?.signal || '';
  error.stdout = result.stdout || '';
  error.stderr = result.stderr || '';
  return error;
}

function recipeRunOutputUnsupported(result) {
  const text = `${result.stderr || ''}\n${result.stdout || ''}\n${result.error?.message || ''}`;
  return /Unknown option:\s*--output/.test(text);
}

function spawnRecipeWithTails(command, args) {
  return new Promise((resolve) => {
    const stdout = new RingTextBuffer(WP_CODEBOX_RECIPE_TAIL_BYTES);
    const stderr = new RingTextBuffer(WP_CODEBOX_RECIPE_TAIL_BYTES);
    const child = spawn(command, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let spawnError = null;

    child.stdout?.on('data', (chunk) => stdout.push(chunk));
    child.stderr?.on('data', (chunk) => stderr.push(chunk));
    child.on('error', (error) => {
      spawnError = error;
    });
    child.on('close', (status, signal) => {
      resolve({
        status,
        signal,
        error: spawnError,
        stdout: stdout.toString(),
        stderr: stderr.toString(),
      });
    });
  });
}

function spawnRecipeToOutputFile(command, args, outputFile) {
  return new Promise((resolve) => {
    const stdout = new RingTextBuffer(WP_CODEBOX_RECIPE_TAIL_BYTES);
    const stderr = new RingTextBuffer(WP_CODEBOX_RECIPE_TAIL_BYTES);
    const output = fs.createWriteStream(outputFile, { encoding: 'utf8' });
    const child = spawn(command, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let spawnError = null;

    child.stdout?.on('data', (chunk) => {
      stdout.push(chunk);
      if (!output.write(chunk)) {
        child.stdout.pause();
      }
    });
    output.on('drain', () => child.stdout?.resume());
    child.stderr?.on('data', (chunk) => stderr.push(chunk));
    child.on('error', (error) => {
      spawnError = error;
    });
    child.on('close', (status, signal) => {
      output.end(() => {
        resolve({
          status,
          signal,
          error: spawnError,
          stdout: stdout.toString(),
          stderr: stderr.toString(),
        });
      });
    });
  });
}

class RingTextBuffer {
  constructor(limitBytes) {
    this.limitBytes = limitBytes;
    this.chunks = [];
    this.bytes = 0;
  }

  push(chunk) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk));
    this.chunks.push(buffer);
    this.bytes += buffer.length;
    while (this.bytes > this.limitBytes && this.chunks.length > 1) {
      this.bytes -= this.chunks.shift().length;
    }
    if (this.bytes > this.limitBytes) {
      const only = this.chunks[0];
      this.chunks[0] = only.subarray(only.length - this.limitBytes);
      this.bytes = this.chunks[0].length;
    }
  }

  toString() {
    return Buffer.concat(this.chunks, this.bytes).toString('utf8');
  }
}

function readTextFile(filePath) {
  try {
    return fs.readFileSync(filePath, 'utf8');
  } catch {
    return '';
  }
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
