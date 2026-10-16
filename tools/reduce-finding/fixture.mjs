/**
 * Blocks Engine visual parity fixtures for reduced materialization findings.
 *
 * The fixture follows `blocks-engine/php-transformer/visual-parity-fixture/v1`
 * (`docs/contracts/php-transformer-visual-parity-fixture.schema.json` in
 * Automattic/blocks-engine; `./contracts/` holds a copy taken at blocks-engine
 * commit 7e1f88b8 so the tests can validate fixtures without network access).
 *
 * The source is the reduced page. The target is the WordPress render of that
 * page after Blocks Engine materialization. When the caller supplies it, the
 * target points at `target.html`; otherwise the target is a placeholder that
 * names the file to produce, and `metadata.target_status` is `placeholder`.
 */

export const VISUAL_PARITY_FIXTURE_SCHEMA = 'blocks-engine/php-transformer/visual-parity-fixture/v1';
export const SOURCE_HTML_PATH = 'source.html';
export const TARGET_HTML_PATH = 'target.html';
export const FIXTURE_VIEWPORTS = [
  { id: 'mobile', width: 390, height: 844 },
  { id: 'desktop', width: 1440, height: 1000 },
];
export const TARGET_PLACEHOLDER_INSTRUCTIONS = [
  `Write the WordPress render of ${SOURCE_HTML_PATH} after Blocks Engine materialization to ${TARGET_HTML_PATH},`,
  'or replace this target with the url/route of a WP Codebox runtime serving it.',
].join(' ');

/**
 * @param {object} [options]
 * @param {string} [options.name]
 * @param {string} [options.description]
 * @param {object} [options.signature] Output of `failureSignature()`.
 * @param {boolean} [options.targetProvided] Whether `target.html` holds the transformer output.
 * @returns {object} A schema-valid fixture.
 */
export function materializationFixture({ name = 'reduced-finding', description, signature, targetProvided = false } = {}) {
  const fixture = {
    schema: VISUAL_PARITY_FIXTURE_SCHEMA,
    name,
    description: description || 'Minimal page reduced from a URL-loop finding by tools/reduce-finding.mjs.',
    source: { html_path: SOURCE_HTML_PATH, renderer: 'playwright' },
    target: targetProvided
      ? { html_path: TARGET_HTML_PATH, renderer: 'wordpress' }
      : { html_path: TARGET_HTML_PATH, renderer: 'wordpress', environment: { placeholder: true, instructions: TARGET_PLACEHOLDER_INSTRUCTIONS } },
    viewports: FIXTURE_VIEWPORTS.map((viewport) => ({ ...viewport })),
    thresholds: { max_mismatch_percent: 0.5, severity_gate: 'error' },
    metadata: {
      producer: 'static-site-importer/reduce-finding',
      target_status: targetProvided ? 'provided' : 'placeholder',
    },
  };
  if (signature) {
    fixture.metadata.signature = { stage: signature.stage, check: signature.check, sha256: signature.sha256 };
  }
  return fixture;
}
