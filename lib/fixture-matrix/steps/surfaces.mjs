/**
 * External dependencies
 */
import path from 'node:path';

/**
 * Internal dependencies
 */
import { collectFixtureFiles, normalizeFixture } from '../fixtures.mjs';

const FRONT_PAGE_SURFACE = Object.freeze({
  id: 'front-page',
  label: 'Front page',
  target: 'front-page',
  source_entry: 'index.html',
  candidate_url: '/',
});

export function selectFixtureSurfaces(fixture, options = {}) {
  const config = normalizeSurfaceCoverageOptions(options);
  const surfaces = [FRONT_PAGE_SURFACE];
  if (config.extraSurfaceCount <= 0) {
    return surfaces;
  }

  const normalized = normalizeFixture(fixture);
  const files = collectFixtureFiles(normalized.directory, options)
    .filter((file) => isHtmlPath(file.relative_path))
    .sort((left, right) => left.relative_path.localeCompare(right.relative_path))
    .map((file) => surfaceFromHtmlPath(file.relative_path))
    .filter((surface) => surface.id !== FRONT_PAGE_SURFACE.id);

  return surfaces.concat(files.slice(0, config.extraSurfaceCount));
}

export function normalizeSurfaceCoverageOptions(input = {}) {
  const raw = input.surfaceCoverage ?? input.surface_coverage ?? input.browserSurfaceCoverage ?? input.browser_surface_coverage;
  if (raw === undefined || raw === null || raw === false || raw === 'false' || raw === '0' || raw === 0) {
    return { extraSurfaceCount: 0 };
  }

  if (raw === true) {
    return { extraSurfaceCount: positiveInteger(input.maxExtraSurfaces ?? input.max_extra_surfaces, 2) };
  }

  if (typeof raw === 'number' || (typeof raw === 'string' && /^\d+$/.test(raw.trim()))) {
    return { extraSurfaceCount: positiveInteger(raw, 0) };
  }

  if (typeof raw === 'object') {
    return {
      extraSurfaceCount: positiveInteger(raw.maxExtraSurfaces ?? raw.max_extra_surfaces ?? raw.extraSurfaceCount ?? raw.extra_surface_count, 2),
    };
  }

  return { extraSurfaceCount: 0 };
}

function surfaceFromHtmlPath(relativePath) {
  const normalized = normalizeRoutePath(relativePath);
  if (/^index\.html?$/i.test(normalized)) {
    return FRONT_PAGE_SURFACE;
  }
  const extensionless = normalized.replace(/\.(?:html?)$/i, '');
  const slugPath = extensionless.endsWith('/index') ? extensionless.slice(0, -6) : extensionless;
  const slug = slugPath.split('/').filter(Boolean).join('-') || 'home';
  const id = slug || 'front-page';
  return {
    id,
    label: normalized,
    target: `/${slug}/`,
    source_entry: normalized,
    candidate_url: `/${slug}/`,
  };
}

function normalizeRoutePath(filePath) {
  return path.posix.normalize(String(filePath || '').replace(/\\+/g, '/')).replace(/^\.\//, '').replace(/^\/+/, '');
}

function isHtmlPath(filePath) {
  return /\.html?$/i.test(filePath);
}

function positiveInteger(value, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number) || number < 0) {
    return fallback;
  }
  return Math.floor(number);
}
