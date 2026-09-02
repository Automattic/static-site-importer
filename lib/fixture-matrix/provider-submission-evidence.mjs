import { createHash } from 'node:crypto';

export const PROVIDER_SUBMISSION_EVIDENCE_SCHEMA = 'static-site-importer/provider-submission-evidence/v1';

export function validateProviderSubmissionEvidence({ fixtureId, requirements, evidence, materializationReceipt, routeEntityMapping } = {}) {
  const declared = array(requirements).filter((row) => row?.required === true);
  if (declared.length === 0) return { required: false, valid: true, errors: [] };
  const observed = array(evidence);
  const errors = [];
  const receiptPlanHash = planHash(materializationReceipt);
  const receiptHash = sha256(materializationReceipt);
  const routeEntities = new Set(array(routeEntityMapping).map((row) => identityKey(row?.route, String(row?.entity ?? ''))));
  const requirementKeys = new Set();
  const evidenceKeys = new Set();

  for (const requirement of declared) {
    const key = identityKey(requirement.page_route, requirement.form_identity);
    if (!nonempty(requirement.page_route) || !sha(requirement.form_identity) || !nonempty(requirement.provider_id) || requirement.provider_owner !== 'wordpress' || requirementKeys.has(key)) errors.push('provider_submission_requirement_invalid');
    requirementKeys.add(key);
    const envelope = observed.find((row) => identityKey(row?.page?.route, row?.form_identity) === key);
    if (!envelope) {
      errors.push('provider_submission_evidence_missing');
      continue;
    }
    evidenceKeys.add(key);
    validateEnvelope(envelope, requirement, { fixtureId, receiptPlanHash, receiptHash, routeEntities }, errors);
  }
  if (observed.some((row) => !requirementKeys.has(identityKey(row?.page?.route, row?.form_identity))) || evidenceKeys.size !== observed.length) errors.push('provider_submission_evidence_unexpected');
  return { required: true, valid: errors.length === 0, errors: [...new Set(errors)].sort() };
}

export function providerSubmissionReportProjection(input = {}) {
  const validation = validateProviderSubmissionEvidence(input);
  if (!validation.required) return null;
  return {
    schema: PROVIDER_SUBMISSION_EVIDENCE_SCHEMA,
    status: validation.valid ? 'verified' : 'not_proven',
    required_form_count: array(input.requirements).filter((row) => row?.required === true).length,
    verified_form_count: validation.valid ? array(input.requirements).filter((row) => row?.required === true).length : 0,
    errors: validation.errors,
  };
}

export function sha256(value) {
  return createHash('sha256').update(canonicalJson(value)).digest('hex');
}

function validateEnvelope(row, requirement, binding, errors) {
  if (row?.schema !== PROVIDER_SUBMISSION_EVIDENCE_SCHEMA) errors.push('provider_submission_schema_invalid');
  if (row?.fixture_id !== binding.fixtureId || row?.page?.route !== requirement.page_route || !nonempty(row?.page?.wordpress_entity_id) || row?.form_identity !== requirement.form_identity || !binding.routeEntities.has(identityKey(row?.page?.route, row?.page?.wordpress_entity_id))) errors.push('provider_submission_identity_mismatch');
  if (row?.provider?.id !== requirement.provider_id || !pinned(row?.provider?.version)) errors.push('provider_submission_provider_mismatch');
  if (row?.provider?.ownership !== 'wordpress' || row?.provider?.submission_endpoint?.scope !== 'wordpress-local' || row?.provider?.submission_endpoint?.source_endpoint_contacted !== false || array(row?.network?.external_request_origins).length !== 0) errors.push('provider_submission_endpoint_not_wordpress_owned');
  if (!sha(binding.receiptPlanHash) || row?.plan_hash !== binding.receiptPlanHash || row?.materialization_receipt_sha256 !== binding.receiptHash) errors.push('provider_submission_materialization_mismatch');
  if (!validArtifactRef(row?.artifact_ref)) errors.push('provider_submission_artifact_missing');
  if (!behavior(row?.behaviors?.required_field_failure, 'validation_error', 0)) errors.push('provider_submission_required_field_failure_unproven');
  const valid = row?.behaviors?.valid_submission;
  if (valid?.status !== 'passed' || valid?.ui !== 'success' || valid?.local_receipt?.storage !== 'wordpress-local' || !nonempty(valid?.local_receipt?.id) || !sha(valid?.local_receipt?.sha256)) errors.push('provider_submission_valid_success_unproven');
  if (!behavior(row?.behaviors?.provider_failure, 'provider_error', 0)) errors.push('provider_submission_provider_failure_unproven');
  const duplicate = row?.behaviors?.duplicate_submit;
  if (duplicate?.status !== 'passed' || duplicate?.ui !== 'success' || duplicate?.local_receipt_count !== 1 || duplicate?.receipt_sha256 !== valid?.local_receipt?.sha256) errors.push('provider_submission_duplicate_behavior_unproven');
  if (row?.notification?.capability !== 'separate' || row?.notification?.attempted !== false) errors.push('provider_submission_notification_not_separate');
}

function behavior(value, ui, count) { return value?.status === 'passed' && value?.ui === ui && value?.local_receipt_count === count; }
function planHash(receipt) { return receipt?.plan_hash || (receipt?.plan_identity?.schema === 'blocks-engine/wordpress-site-plan-identity/v1' ? receipt.plan_identity.hash : ''); }
function validArtifactRef(value) { return value && typeof value.path === 'string' && value.path && !value.path.startsWith('/') && !value.path.split('/').includes('..'); }
function identityKey(route, identity) { return `${route || ''}\u0000${identity || ''}`; }
function pinned(value) { return nonempty(value) && !/latest|unknown/i.test(value); }
function nonempty(value) { return typeof value === 'string' && value.trim().length > 0; }
function sha(value) { return /^[a-f0-9]{64}$/.test(String(value || '')); }
function array(value) { return Array.isArray(value) ? value : []; }
function canonicalJson(value) {
  if (Array.isArray(value)) return `[${value.map(canonicalJson).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key])}`).join(',')}}`;
  return JSON.stringify(value) ?? 'null';
}
