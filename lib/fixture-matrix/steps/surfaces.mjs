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
export const MAX_EXTRA_SURFACE_COUNT = 5;

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

export function resolveSurfaceEditorTarget(surface, input = {}) {
  if (!surface || surface.id === FRONT_PAGE_SURFACE.id) {
    return surface;
  }

  const page = findImportedSurfacePage(surface, input);
  const postId = positiveIntegerValue(page?.materialized_post_id ?? page?.materializedPostId ?? page?.post_id ?? page?.postId);
  if (postId !== undefined) {
    return {
      ...surface,
      postId,
      post_id: postId,
      editor_target_source: 'import-report',
    };
  }

  return {
    ...surface,
    postType: 'page',
    post_type: 'page',
    postSlug: surfacePostSlug(surface),
    post_slug: surfacePostSlug(surface),
    editor_target_source: 'surface-slug-fallback',
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

function findImportedSurfacePage(surface, input = {}) {
  const pages = importedPages(input);
  if (!pages.length) {
    return null;
  }

  const sourceKeys = surfaceSourceKeys(surface);
  const routeKeys = surfaceRouteKeys(surface);
  return pages.find((page) => {
    const pageSourceKeys = surfaceSourceKeys({ source_entry: page.source_path ?? page.sourcePath ?? page.path ?? page.source ?? '' });
    if ([...pageSourceKeys].some((key) => sourceKeys.has(key))) {
      return true;
    }

    const permalink = page.permalink || page.url || page.candidate_url || page.candidateUrl || '';
    const slug = page.slug || page.post_name || page.postName || '';
    const pageRouteKeys = new Set([
      ...surfaceRouteKeys({ target: permalink, candidate_url: permalink }),
      ...surfaceRouteKeys({ target: slug, candidate_url: slug }),
    ]);
    return [...pageRouteKeys].some((key) => routeKeys.has(key));
  }) || null;
}

function importedPages(input = {}) {
  const reports = [
    input.import_report,
    input.importReport,
    input.report,
    input.fixture?.import_report,
    input.fixture?.importReport,
  ].filter((report) => report && typeof report === 'object');
  const pages = [];
  for (const report of reports) {
    pages.push(...arrayValue(report.desired?.pages));
    pages.push(...arrayValue(report.source_of_truth_manifest?.desired?.pages));
    pages.push(...arrayValue(report.sourceOfTruthManifest?.desired?.pages));
    pages.push(...arrayValue(report.materialized_pages));
    pages.push(...arrayValue(report.materializedPages));
    pages.push(...arrayValue(report.pages));
  }
  pages.push(...arrayValue(input.materialized_pages));
  pages.push(...arrayValue(input.materializedPages));
  return pages.filter((page) => page && typeof page === 'object');
}

function surfaceSourceKeys(surface) {
  const raw = String(surface?.source_entry || surface?.source_path || surface?.sourcePath || '').trim();
  const normalized = normalizeRoutePath(raw.replace(/^website\//, ''));
  const withoutExtension = normalized.replace(/\.(?:html?)$/i, '').replace(/\/index$/i, '');
  return new Set([normalized, withoutExtension, `website/${normalized}`].filter(Boolean));
}

function surfaceRouteKeys(surface) {
  const values = [surface?.target, surface?.candidate_url, surface?.candidateUrl].map((value) => String(value || '').trim()).filter(Boolean);
  return new Set(values.map((value) => {
    try {
      value = new URL(value).pathname;
    } catch {
      // Relative routes and slugs are expected here.
    }
    return normalizeRoutePath(value).replace(/\/index$/i, '');
  }).filter(Boolean));
}

function surfacePostSlug(surface) {
  const source = String(surface?.source_entry || '').replace(/^website\//, '');
  const sourceSlug = normalizeRoutePath(source).replace(/\.(?:html?)$/i, '').replace(/\/index$/i, '').split('/').filter(Boolean).pop();
  if (sourceSlug) {
    return sourceSlug;
  }
  return [...surfaceRouteKeys(surface)][0]?.split('/').filter(Boolean).pop() || String(surface?.id || '').replace(/--\d+$/, '') || 'page';
}

function arrayValue(value) {
  return Array.isArray(value) ? value : [];
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

function positiveIntegerValue(value) {
  const number = Number(value);
  if (!Number.isInteger(number) || number <= 0) {
    return undefined;
  }
  return number;
}
