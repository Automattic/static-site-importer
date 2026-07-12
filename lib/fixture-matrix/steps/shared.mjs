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
