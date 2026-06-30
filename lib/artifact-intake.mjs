import fs from 'node:fs';
import path from 'node:path';

const DEFAULT_ENTRYPOINT = 'index.html';
const DEFAULT_MAX_DEPTH = 3;

export function materializeGeneratedArtifactFixtures(input = {}) {
  const artifactRoot = requiredDirectory(input.artifactRoot || input.artifact_root, 'artifactRoot');
  const fixtureRoot = path.resolve(requiredString(input.fixtureRoot || input.fixture_root, 'fixtureRoot'));
  const entrypoint = input.entrypoint || DEFAULT_ENTRYPOINT;
  const maxDepth = finiteNumber(input.maxDepth ?? input.max_depth, DEFAULT_MAX_DEPTH);
  const candidates = discoverGeneratedArtifacts(artifactRoot, { entrypoint, maxDepth });
  const fixtures = [];

  fs.mkdirSync(fixtureRoot, { recursive: true });
  for (const [index, candidate] of candidates.entries()) {
    const fixtureId = uniqueFixtureId(candidate.id || path.basename(candidate.source), fixtures, index + 1);
    const fixtureDirectory = path.join(fixtureRoot, fixtureId);
    fs.rmSync(fixtureDirectory, { recursive: true, force: true });
    fs.mkdirSync(fixtureDirectory, { recursive: true });

    if (candidate.kind === 'website_artifact') {
      materializeWebsiteArtifact(candidate.payload, fixtureDirectory);
    } else {
      copyDirectory(candidate.source, fixtureDirectory);
      const discoveredEntry = candidate.entrypoint || entrypoint;
      if (discoveredEntry !== entrypoint && fs.existsSync(path.join(fixtureDirectory, discoveredEntry))) {
        fs.copyFileSync(path.join(fixtureDirectory, discoveredEntry), path.join(fixtureDirectory, entrypoint));
      }
    }

    fixtures.push({
      id: fixtureId,
      directory: fixtureDirectory,
      fixture_path: fixtureDirectory,
      fixture_root: fixtureRoot,
      entrypoint,
      source: candidate.source,
      source_kind: candidate.kind,
    });
  }

  return {
    schema: 'static-site-importer/generated-artifact-intake/v1',
    artifact_root: artifactRoot,
    fixture_root: fixtureRoot,
    entrypoint,
    count: fixtures.length,
    fixtures,
  };
}

export function discoverGeneratedArtifacts(root, options = {}) {
  const artifactRoot = requiredDirectory(root, 'artifactRoot');
  const entrypoint = options.entrypoint || DEFAULT_ENTRYPOINT;
  const maxDepth = finiteNumber(options.maxDepth ?? options.max_depth, DEFAULT_MAX_DEPTH);
  const candidates = [];

  visitDirectories(artifactRoot, 0, maxDepth, (directory) => {
    const artifact = readWebsiteArtifact(directory);
    if (artifact) {
      candidates.push({
        kind: 'website_artifact',
        source: artifact.path,
        payload: artifact.payload,
        id: artifact.payload?.metadata?.site || path.basename(directory),
      });
      return false;
    }

    if (fs.existsSync(path.join(directory, entrypoint))) {
      candidates.push({ kind: 'static_directory', source: directory, entrypoint, id: path.basename(directory) });
      return false;
    }

    const websiteDirectory = path.join(directory, 'website');
    if (fs.existsSync(path.join(websiteDirectory, entrypoint))) {
      candidates.push({ kind: 'website_directory', source: websiteDirectory, entrypoint, id: path.basename(directory) });
      return false;
    }

    return true;
  });

  return candidates.sort((left, right) => left.source.localeCompare(right.source));
}

function readWebsiteArtifact(directory) {
  for (const name of ['artifact.json', 'website-artifact.json', 'static-site-candidate.json']) {
    const filePath = path.join(directory, name);
    if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
      continue;
    }
    try {
      const payload = JSON.parse(fs.readFileSync(filePath, 'utf8'));
      if (Array.isArray(payload?.files) && payload.files.some((file) => file.path === 'website/index.html')) {
        return { path: filePath, payload };
      }
    } catch {
      // Non-JSON files with these names are not website artifact candidates.
    }
  }
  return null;
}

function materializeWebsiteArtifact(artifact, fixtureDirectory) {
  for (const file of artifact.files || []) {
    if (!file?.path || !file.path.startsWith('website/')) {
      continue;
    }
    const relativePath = file.path.slice('website/'.length);
    if (!relativePath || relativePath.includes('..')) {
      continue;
    }
    const destination = path.join(fixtureDirectory, relativePath);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    if (typeof file.content === 'string') {
      fs.writeFileSync(destination, file.content);
    } else if (typeof file.content_base64 === 'string') {
      fs.writeFileSync(destination, Buffer.from(file.content_base64, 'base64'));
    }
  }
}

function copyDirectory(source, destination) {
  for (const entry of fs.readdirSync(source, { withFileTypes: true })) {
    if (entry.name === '.git' || entry.name === 'node_modules') {
      continue;
    }
    const sourcePath = path.join(source, entry.name);
    const destinationPath = path.join(destination, entry.name);
    if (entry.isDirectory()) {
      fs.mkdirSync(destinationPath, { recursive: true });
      copyDirectory(sourcePath, destinationPath);
    } else if (entry.isFile()) {
      fs.mkdirSync(path.dirname(destinationPath), { recursive: true });
      fs.copyFileSync(sourcePath, destinationPath);
    }
  }
}

function visitDirectories(directory, depth, maxDepth, callback) {
  const shouldDescend = callback(directory) !== false;
  if (!shouldDescend || depth >= maxDepth) {
    return;
  }
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory() && entry.name !== '.git' && entry.name !== 'node_modules') {
      visitDirectories(path.join(directory, entry.name), depth + 1, maxDepth, callback);
    }
  }
}

function uniqueFixtureId(value, existingFixtures, fallback) {
  const base = slug(value || `fixture-${fallback}`);
  const existing = new Set(existingFixtures.map((fixture) => fixture.id));
  if (!existing.has(base)) {
    return base;
  }
  let suffix = 2;
  while (existing.has(`${base}-${suffix}`)) {
    suffix += 1;
  }
  return `${base}-${suffix}`;
}

function requiredString(value, name) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new TypeError(`${name} must be a non-empty string.`);
  }
  return value;
}

function requiredDirectory(value, name) {
  const directory = path.resolve(requiredString(value, name));
  if (!fs.existsSync(directory) || !fs.statSync(directory).isDirectory()) {
    throw new Error(`${name} must be an existing directory: ${directory}`);
  }
  return directory;
}

function finiteNumber(value, fallback) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function slug(value) {
  return String(value || 'fixture')
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'fixture';
}
