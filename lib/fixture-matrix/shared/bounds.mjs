// Output-size bounding for the Static Site Importer fixture matrix (#554).
//
// The matrix assembles one aggregate result that is serialized with
// JSON.stringify (per-fixture results + findings + result_summary). The
// block-composition parsing added in #552 reads serialized `post_content` /
// Gutenberg block markup; if that raw bulk is retained on each fixture result,
// a lane-scale run (~30+ fixtures) serializes into a string that exceeds V8's
// hard ~512MB per-string ceiling and throws `Invalid string length` (peak RSS
// 11.2 GB observed at 30 fixtures).
//
// These helpers bound the RETAINED output independent of fixture count: raw
// serialized markup is discarded after COUNTS are computed, and every retained
// string field is truncated to a sane cap. Metrics (native_conversion_rate,
// loss classes, finding counts, block counts, tags/complexity) are never
// touched — this drops raw BULK, not metrics.

// Per-string ceiling for any retained text field (source snippets, observed
// output, reasons, and any deep string inside a retained report blob). Generous
// enough to keep findings human-readable while orders of magnitude below V8's
// per-string limit, so the aggregate scales with #fixtures/#patterns rather
// than with raw content volume.
export const MAX_RETAINED_STRING_LENGTH = 2048;

// Depth guard for the recursive blob bounder. Retained report blobs are shallow
// in practice; this only stops object/array recursion from running away on a
// pathological structure. Strings are truncated at every depth regardless.
const MAX_BOUND_DEPTH = 16;

const TRUNCATION_NOTICE = '…[truncated]';

// Truncate a single string to `max` characters, appending a visible notice so a
// consumer can tell the value was clipped. Non-strings pass through untouched.
export function truncateString(value, max = MAX_RETAINED_STRING_LENGTH) {
  if (typeof value !== 'string' || value.length <= max) {
    return value;
  }
  return `${value.slice(0, max)}${TRUNCATION_NOTICE}`;
}

// Recursively truncate every string inside an arbitrary JSON-ish blob so a
// single retained value (e.g. a serialized `post_content` body, or a
// `block_documents[].post_content` carried on an import report) can not balloon
// the aggregate output. Object/array shape, keys, and all non-string values
// (counts, numbers, booleans) are preserved exactly — only string VALUES are
// bounded. Returns the blob unchanged when there is nothing to bound.
export function boundBlob(value, max = MAX_RETAINED_STRING_LENGTH, depth = 0) {
  if (typeof value === 'string') {
    return truncateString(value, max);
  }
  if (!value || typeof value !== 'object' || depth >= MAX_BOUND_DEPTH) {
    return value;
  }
  if (Array.isArray(value)) {
    return value.map((entry) => boundBlob(entry, max, depth + 1));
  }
  const bounded = {};
  for (const [key, entry] of Object.entries(value)) {
    bounded[key] = boundBlob(entry, max, depth + 1);
  }
  return bounded;
}
