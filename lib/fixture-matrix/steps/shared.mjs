import { DEFAULT_EDITOR_VALIDATION_TARGET } from '../shared/constants.mjs';

export function fixtureStepMetadata(fixture = {}, phase, fields = {}) {
  return {
    fixture_id: fixture.id,
    fixture_path: fixture.fixture_path || fixture.directory,
    phase,
    ...fields,
  };
}

export function firstPresent(values) {
  for (const value of values) {
    if (present(value)) {
      return value;
    }
  }
  return undefined;
}

export function editorStepTarget(input = {}, fixture = {}, surface = null, options = {}) {
  const urlAliases = Array.isArray(options.urlAliases) ? options.urlAliases : [];
  const targetAliases = Array.isArray(options.targetAliases) ? options.targetAliases : [];
  const postId = firstPresent([input.postId, input.post_id, surface?.postId, surface?.post_id, fixture.editor_post_id, fixture.editorPostId, fixture.post_id, fixture.postId]);
  const url = firstPresent([input.url, ...valuesForKeys(input, urlAliases), surface?.url, fixture.editor_url, fixture.editorUrl]);
  const target = firstPresent([input.target, ...valuesForKeys(input, targetAliases), surface?.target, fixture.editor_target, fixture.editorTarget, fixture.target]);
  const postType = firstPresent([input.postType, input.post_type, surface?.postType, surface?.post_type]);
  const postSlug = firstPresent([input.postSlug, input.post_slug, surface?.postSlug, surface?.post_slug]);

  const args = [];
  if (postId !== undefined) {
    args.push(`post-id=${postId}`);
  } else if (postSlug !== undefined) {
    if (postType !== undefined) {
      args.push(`post-type=${postType}`);
    }
    args.push(`post-slug=${postSlug}`);
  } else if (url !== undefined) {
    args.push(`url=${url}`);
  } else if (target !== undefined) {
    args.push(`target=${target}`);
  } else {
    args.push(`target=${DEFAULT_EDITOR_VALIDATION_TARGET}`);
  }

  const waitSelector = firstPresent([input.waitSelector, input.wait_selector, fixture.editor_wait_selector, fixture.editorWaitSelector]);
  if (waitSelector !== undefined) {
    args.push(`wait-selector=${waitSelector}`);
  }
  const waitTimeout = firstPresent([input.waitTimeout, input.wait_timeout, fixture.editor_wait_timeout, fixture.editorWaitTimeout]);
  if (waitTimeout !== undefined) {
    args.push(`wait-timeout=${waitTimeout}`);
  }

  return {
    args,
    metadata: {
      ...(postId !== undefined ? { post_id: postId } : {}),
      ...(postSlug !== undefined ? { post_slug: postSlug } : {}),
      ...(postType !== undefined ? { post_type: postType } : {}),
      ...(url !== undefined ? { url } : {}),
      ...(target !== undefined ? { target } : { target: DEFAULT_EDITOR_VALIDATION_TARGET }),
      ...(surface?.editor_target_source ? { editor_target_source: surface.editor_target_source } : {}),
    },
  };
}

export function resolveFixtureCandidateUrl(input = {}, fixture = {}, fallback) {
  return input.candidateUrl
    || input.candidate_url
    || fixture.candidate_url
    || fixture.candidateUrl
    || fallback;
}

function present(value) {
  return value !== undefined && value !== null && String(value).trim() !== '';
}

function valuesForKeys(source = {}, keys = []) {
  return keys.map((key) => source[key]);
}
