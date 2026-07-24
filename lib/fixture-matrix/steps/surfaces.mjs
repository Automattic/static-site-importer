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

export const DEFAULT_EXTRA_SURFACE_COUNT = 2;
export const MAX_EXTRA_SURFACE_COUNT = 10;

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

  return surfaces.concat(uniqueSurfaceIds(files).slice(0, config.extraSurfaceCount));
}

export function summarizeSurfaceCoverage(fixtures = [], options = {}) {
  const config = normalizeSurfaceCoverageOptions(options);
  const perFixture = fixtures.map((fixture) => {
    const surfaces = selectFixtureSurfaces(fixture, options);
    return {
      fixture_id: fixture.id,
      surface_count: surfaces.length,
      extra_surface_count: Math.max(0, surfaces.length - 1),
      surface_ids: surfaces.map((surface) => surface.id),
    };
  });
  const totalSurfaceCount = perFixture.reduce((total, row) => total + row.surface_count, 0);
  const extraSurfaceCount = perFixture.reduce((total, row) => total + row.extra_surface_count, 0);
  return {
    enabled: config.extraSurfaceCount > 0,
    requested_extra_surfaces: config.requestedExtraSurfaceCount,
    max_extra_surfaces: config.maxExtraSurfaceCount,
    extra_surfaces_per_fixture: config.extraSurfaceCount,
    capped: config.extraSurfaceCount < config.requestedExtraSurfaceCount,
    fixture_count: perFixture.length,
    total_surface_count: totalSurfaceCount,
    total_extra_surface_count: extraSurfaceCount,
    browser_step_multiplier: totalSurfaceCount,
    per_fixture: perFixture,
  };
}

export function normalizeSurfaceCoverageOptions(input = {}) {
  const raw = input.surfaceCoverage ?? input.surface_coverage ?? input.browserSurfaceCoverage ?? input.browser_surface_coverage;
  if (raw === undefined || raw === null || raw === false || raw === 'false' || raw === '0' || raw === 0) {
    return normalizedConfig(0);
  }

  if (raw === true) {
    return normalizedConfig(input.maxExtraSurfaces ?? input.max_extra_surfaces, DEFAULT_EXTRA_SURFACE_COUNT);
  }

  if (typeof raw === 'number' || (typeof raw === 'string' && /^\d+$/.test(raw.trim()))) {
    return normalizedConfig(raw, 0);
  }

  if (typeof raw === 'object') {
    return normalizedConfig(raw.maxExtraSurfaces ?? raw.max_extra_surfaces ?? raw.extraSurfaceCount ?? raw.extra_surface_count, DEFAULT_EXTRA_SURFACE_COUNT);
  }

  return normalizedConfig(0);
}

function normalizedConfig(value, fallback = DEFAULT_EXTRA_SURFACE_COUNT) {
  const requested = positiveInteger(value, fallback);
  return {
    requestedExtraSurfaceCount: requested,
    maxExtraSurfaceCount: MAX_EXTRA_SURFACE_COUNT,
    extraSurfaceCount: Math.min(requested, MAX_EXTRA_SURFACE_COUNT),
  };
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

function uniqueSurfaceIds(surfaces) {
  const seen = new Map();
  return surfaces.map((surface) => {
    const count = seen.get(surface.id) || 0;
    seen.set(surface.id, count + 1);
    if (count === 0) {
      return surface;
    }
    return {
      ...surface,
      id: `${surface.id}--${count + 1}`,
    };
  });
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
