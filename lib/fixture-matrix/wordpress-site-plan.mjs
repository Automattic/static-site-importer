// Canonical Blocks Engine materialization-plan (WordPress site plan) schemas.
//
// Blocks Engine versions the `wordpress_site_plan` artifact independently of
// the importer. Every schema id listed here is accepted intake across SSI
// tooling (fixture-matrix run-intake, acceptance handoff, and the FIG
// acceptance provider); ids outside the list fail closed.

export const SUPPORTED_MATERIALIZATION_PLAN_SCHEMAS = Object.freeze([
  'blocks-engine/wordpress-site-plan/v2',
  'blocks-engine/wordpress-site-plan/v3',
]);

export function isSupportedMaterializationPlanSchema(schema) {
  return SUPPORTED_MATERIALIZATION_PLAN_SCHEMAS.includes(schema);
}
