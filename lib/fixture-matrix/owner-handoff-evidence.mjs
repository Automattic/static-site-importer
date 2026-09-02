import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { editorInteractionEvidenceComplete, editorPresentationEvidenceComplete } from './gutenberg-incompatibility-registry.mjs';

export const OWNER_HANDOFF_EVIDENCE_SCHEMA = 'static-site-importer/owner-handoff-evidence/v1';
export const OWNER_TASK_SCHEMA = 'static-site-importer/owner-task-check/v1';
export const PLAN_IDENTITY_SCHEMA = 'blocks-engine/wordpress-site-plan-identity/v1';
export const DIMENSION_IDS = Object.freeze([
  'route_content_completeness',
  'visual_acceptance',
  'editability_shared_regions',
  'editor_presentation_persistence',
  'media_library_ownership',
  'link_portability',
  'provider_functionality',
  'site_identity_metadata',
  'accessibility',
  'frontend_performance',
  'deployment_rollback',
  'owner_tasks',
]);
export const OWNER_TASK_IDS = Object.freeze([
  'text_edit',
  'image_replace',
  'navigation_edit',
  'shared_footer_edit',
  'form_recipient_edit',
]);

const RANK = {
  hard_failure: 5,
  evidence_gap: 4,
  required_owner_decision: 3,
  acceptable_conversion: 2,
  informational: 1,
  pass: 0,
};
const OWNERS = {
  route_content_completeness: 'static-site-importer',
  visual_acceptance: 'static-site-importer',
  editability_shared_regions: 'blocks-engine',
  editor_presentation_persistence: 'static-site-importer',
  media_library_ownership: 'static-site-importer',
  link_portability: 'static-site-importer',
  provider_functionality: 'static-site-importer',
  site_identity_metadata: 'static-site-importer',
  accessibility: 'static-site-importer',
  frontend_performance: 'static-site-importer',
  deployment_rollback: 'static-site-importer',
  owner_tasks: 'static-site-importer',
};
const ACTIONS = {
  route_content_completeness: 'Supply a completed materialization receipt bound to the canonical plan hash.',
  visual_acceptance: 'Provide desktop and mobile visual acceptance evidence for every materialized route.',
  editability_shared_regions: 'Admit a hash-bound Blocks Engine editability report with shared-region ownership.',
  editor_presentation_persistence: 'Provide editor presentation coverage and persisted edit/save/reload evidence.',
  media_library_ownership: 'Import replaceable media as WordPress attachments with stable Media Library IDs.',
  link_portability: 'Prove internal-link rewrites and inventory remaining external URLs.',
  provider_functionality: 'Record provider-functionality receipts, including a successful form submission.',
  site_identity_metadata: 'Materialize document titles, canonical metadata, site identity, and unresolved placeholders.',
  accessibility: 'Provide keyboard and accessibility evidence for the generated frontend.',
  frontend_performance: 'Provide bounded generated-frontend performance evidence.',
  deployment_rollback: 'Record dependency, deployment, and rollback readiness on the materialization receipt.',
  owner_tasks: 'Prove text, image, navigation, shared-footer, and form-recipient edits with save/reload validation.',
};

export function produceOwnerHandoffEvidence(input = {}) {
  const plan = planIdentity(input.planIdentity || input.plan_identity);
  const receipt = object(input.materializationReceipt || input.materialization_receipt);
  const receiptHash = receiptSha256(input, receipt);
  const supplied = object(input.dimensions || input.evidence);
  const dimensions = [];
  const findings = [];
  let ownerTasks = null;
  for (const id of DIMENSION_IDS) {
    const row = projectDimension(id, supplied[id], receipt);
    dimensions.push({ id, status: row.status, owning_repository: row.owning_repository, evidence_reference: row.evidence_reference });
    findings.push(...row.findings);
    if (id === 'owner_tasks') ownerTasks = row.owner_tasks;
  }
  let worst = 'pass';
  for (const dimension of dimensions) worst = worse(worst, dimension.status);
  if (!validPlanIdentity(plan) || !sha256(receiptHash)) {
    worst = worse(worst, 'evidence_gap');
    findings.push(finding('route_content_completeness', 'evidence_gap', '', 'plan_identity', OWNERS.route_content_completeness, ACTIONS.route_content_completeness, reference(plan)));
  }
  const disposition = worst === 'hard_failure' ? 'failed' : worst === 'evidence_gap' ? 'not_proven' : worst === 'required_owner_decision' ? 'owner_decisions_required' : 'passed';
  const document = {
    schema: OWNER_HANDOFF_EVIDENCE_SCHEMA,
    plan_identity: validPlanIdentity(plan) ? plan : null,
    materialization_receipt_sha256: sha256(receiptHash) ? receiptHash : null,
    dimensions,
    findings,
    owner_tasks: ownerTasks,
    disposition,
    accepted_built_allowed: !['hard_failure', 'evidence_gap'].includes(worst),
  };
  document.report_card = renderOwnerHandoffReportCard(document);
  const directory = input.outputDirectory || input.output_directory;
  if (directory) {
    fs.mkdirSync(path.resolve(directory), { recursive: true });
    writeJson(path.join(directory, 'owner-handoff-evidence.json'), document);
    fs.writeFileSync(path.join(directory, 'owner-handoff-report-card.md'), document.report_card);
  }
  return document;
}

export function validateOwnerHandoffEvidence(document) {
  if (!document || document.schema !== OWNER_HANDOFF_EVIDENCE_SCHEMA) {
    return { valid: false, accepted_built_allowed: false, disposition: 'not_proven', reasons: [{ code: 'artifact_missing' }] };
  }
  const reasons = [];
  if (!validPlanIdentity(document.plan_identity) || !sha256(document.materialization_receipt_sha256)) reasons.push('hash_mismatch');
  const ids = array(document.dimensions).map((row) => row?.id);
  if (DIMENSION_IDS.some((id) => !ids.includes(id))) reasons.push('evidence_gap');
  for (const findingRow of array(document.findings)) {
    if (!findingRow?.recommended_next_action || !findingRow?.owning_repository || !Object.prototype.hasOwnProperty.call(findingRow, 'route') || !Object.prototype.hasOwnProperty.call(findingRow, 'component')) {
      reasons.push('finding_incomplete');
    }
  }
  const unique = [...new Set(reasons)];
  const disposition = unique.includes('hash_mismatch') || unique.includes('evidence_gap') ? 'not_proven' : document.disposition;
  const allowed = unique.length === 0 && document.accepted_built_allowed === true;
  return { valid: unique.length === 0, accepted_built_allowed: allowed, disposition, reasons: unique.map((code) => ({ code })) };
}

export function consumeOwnerHandoffEvidence(document) {
  const validation = validateOwnerHandoffEvidence(document);
  const allowed = validation.valid && document?.accepted_built_allowed === true;
  return {
    schema: OWNER_HANDOFF_EVIDENCE_SCHEMA,
    disposition: allowed ? document.disposition : validation.disposition,
    accepted_built_allowed: allowed,
    reasons: allowed ? [] : (validation.reasons.length > 0 ? validation.reasons : array(document?.findings).map((row) => ({ code: row.status, dimension: row.dimension }))),
  };
}

export function renderOwnerHandoffReportCard(document) {
  const lines = [
    '# Owner handoff report card',
    '',
    `Disposition: \`${document?.disposition || 'not_proven'}\``,
    `Accepted/built allowed: ${document?.accepted_built_allowed ? 'yes' : 'no'}`,
    '',
    '## Dimensions',
  ];
  for (const dimension of array(document?.dimensions)) {
    lines.push(`- ${dimension.id}: \`${dimension.status}\``);
  }
  lines.push('', '## Remaining actions');
  const findings = array(document?.findings);
  if (findings.length === 0) lines.push('- None.');
  for (const row of findings) {
    const scope = `${row.route || ''} ${row.component || ''}`.trim();
    lines.push(`- \`${row.status}\` ${row.dimension}${scope ? ` (${scope})` : ''} — ${row.recommended_next_action} (owning: ${row.owning_repository})`);
  }
  return `${lines.join('\n')}\n`;
}

export function emitFixtureMatrixOwnerHandoffs({ outputDirectory, result }) {
  const root = path.resolve(outputDirectory);
  const handoffs = [];
  for (const fixture of array(result?.fixtures)) {
    const evidence = object(fixture.matrix_evidence);
    const receipt = object(evidence.materialization_receipt);
    const plan = object(receipt.plan_identity || evidence.wordpress_site_plan?.plan_identity);
    const handoffRoot = path.join(root, fixture.fixture_id, 'owner-handoff');
    const document = produceOwnerHandoffEvidence({
      outputDirectory: handoffRoot,
      planIdentity: Object.keys(plan).length > 0 ? plan : { schema: PLAN_IDENTITY_SCHEMA, hash: receipt.plan_hash },
      materializationReceipt: Object.keys(receipt).length > 0 ? receipt : null,
      dimensions: dimensionsFromFixture(fixture),
    });
    handoffs.push({
      fixture_id: fixture.fixture_id,
      path: path.relative(root, path.join(handoffRoot, 'owner-handoff-evidence.json')).split(path.sep).join('/'),
      disposition: document.disposition,
      accepted_built_allowed: document.accepted_built_allowed,
    });
  }
  return { schema: OWNER_HANDOFF_EVIDENCE_SCHEMA, handoffs };
}

function dimensionsFromFixture(fixture) {
  const evidence = object(fixture.matrix_evidence);
  const receipt = object(evidence.materialization_receipt);
  const comparisons = array(fixture.visual_parity_comparisons);
  const visual = visualFromComparisons(comparisons, fixture);
  return {
    visual_acceptance: visual,
    editability_shared_regions: fixture.editability_report || receipt.editability_report,
    editor_presentation_persistence: {
      ...(object(fixture.editor_presentation)),
      interaction: fixture.editor_interaction,
      persistence: fixture.editor_persistence || evidence.editor_persistence,
    },
    media_library_ownership: fixture.media_library || evidence.media_library || object(fixture.import_report).assets,
    link_portability: fixture.link_portability || object(fixture.import_report).source_documents,
    provider_functionality: fixture.provider_functionality || evidence.provider_functionality,
    site_identity_metadata: object(object(fixture.import_report).generated_theme).document_metadata,
    accessibility: fixture.accessibility || evidence.accessibility,
    frontend_performance: fixture.frontend_performance || evidence.frontend_performance,
    deployment_rollback: receipt.rollback,
    owner_tasks: fixture.owner_tasks || evidence.owner_tasks,
  };
}

function visualFromComparisons(comparisons, fixture) {
  const supplied = fixture.visual_acceptance || object(fixture.visual_fidelity);
  if (supplied.desktop && supplied.mobile) return supplied;
  const viewports = comparisons.flatMap((row) => {
    const viewport = object(row.viewport);
    const width = Number(viewport.width || row.width || 0);
    const mismatch = row.mismatch === true || row.status === 'failed' || object(row.visual_parity_artifacts).status === 'failed';
    const passed = row.mismatch === false || row.status === 'passed' || object(row.visual_parity_artifacts).status === 'passed';
    if (!passed && !mismatch) return [];
    return [{ kind: width > 0 && width < 800 ? 'mobile' : 'desktop', status: mismatch ? 'hard_failure' : 'pass' }];
  });
  const desktop = viewports.find((row) => row.kind === 'desktop');
  const mobile = viewports.find((row) => row.kind === 'mobile');
  if (!desktop && !mobile) return supplied;
  return { desktop, mobile };
}

function projectDimension(id, evidence, receipt) {
  const owner = OWNERS[id];
  const action = ACTIONS[id];
  let referenceValue = reference(evidence);
  if (id === 'route_content_completeness') {
    const value = Object.keys(object(receipt)).length > 0 ? receipt : evidence;
    return dimension(id, receiptStatus(value), owner, action, reference(value), '', 'materialization_receipt');
  }
  if (id === 'visual_acceptance') return dimension(id, visualStatus(evidence), owner, action, referenceValue, '', 'visual');
  if (id === 'editability_shared_regions') return dimension(id, editabilityStatus(evidence), owner, action, referenceValue, '', 'editability_report');
  if (id === 'editor_presentation_persistence') return dimension(id, editorStatus(evidence), owner, action, referenceValue, '', 'editor');
  if (id === 'media_library_ownership') return dimension(id, mediaStatus(evidence), owner, action, referenceValue, '', 'media_library');
  if (id === 'link_portability') return dimension(id, linkStatus(evidence), owner, action, referenceValue, '', 'links');
  if (id === 'provider_functionality') return dimension(id, providerStatus(evidence), owner, action, referenceValue, '', 'provider');
  if (id === 'site_identity_metadata') return dimension(id, identityStatus(evidence), owner, action, referenceValue, '', 'site_identity');
  if (id === 'accessibility' || id === 'frontend_performance') return dimension(id, gatedStatus(evidence), owner, action, referenceValue, '', id);
  if (id === 'deployment_rollback') {
    const value = isObject(evidence) ? evidence : receipt.rollback;
    return dimension(id, rollbackStatus(value), owner, action, reference(value), '', 'rollback');
  }
  const ownerTasks = ownerTasksFrom(evidence);
  return { status: ownerTasks.status, owning_repository: owner, evidence_reference: referenceValue, findings: ownerTaskFindings(ownerTasks, owner, action, referenceValue), owner_tasks: ownerTasks };
}

function receiptStatus(evidence) {
  const row = object(evidence);
  if (!['static-site-importer/materialization-receipt/v1', 'static-site-importer/materialization-receipt/v2'].includes(row.schema)) return 'evidence_gap';
  if (row.status === 'failed') return 'hard_failure';
  return row.status === 'completed' ? 'pass' : 'evidence_gap';
}

function visualStatus(evidence) {
  const row = object(evidence);
  const desktop = viewportStatus(row.desktop || row.viewports?.desktop);
  const mobile = viewportStatus(row.mobile || row.viewports?.mobile);
  if (desktop == null || mobile == null) return 'evidence_gap';
  return worse(desktop, mobile);
}

function viewportStatus(evidence) {
  const row = object(evidence);
  if (Object.keys(row).length === 0) return null;
  return normalizePassFail(row.status) || 'evidence_gap';
}

function editabilityStatus(evidence) {
  const row = object(evidence);
  if (!['static-site-importer/editability-report-admission/v1', 'blocks-engine/php-transformer/editability-report/v2'].includes(row.schema)) return 'evidence_gap';
  if (['rejected', 'failed'].includes(row.status)) return 'hard_failure';
  return row.status === 'passed' ? 'pass' : 'evidence_gap';
}

function editorStatus(evidence) {
  const row = object(evidence);
  const presentation = object(row.editor_presentation || row);
  const interaction = object(row.interaction || row.editor_interaction);
  const persistence = object(row.persistence || row.editor_persistence);
  if (!editorPresentationEvidenceComplete(presentation) || !editorInteractionEvidenceComplete(interaction)) return 'evidence_gap';
  if (Object.keys(persistence).length === 0) return 'evidence_gap';
  if (persistence.persisted === true && persistence.reloaded === true) return 'pass';
  return persistence.persisted === false || persistence.reloaded === false ? 'hard_failure' : 'evidence_gap';
}

function mediaStatus(evidence) {
  const row = object(evidence);
  if (!Object.hasOwn(row, 'attachment_count') || !Object.hasOwn(row, 'replaceable_media_count')) return 'evidence_gap';
  if (Number(row.replaceable_media_count) > 0 && Number(row.attachment_count) < 1) return 'hard_failure';
  return 'pass';
}

function linkStatus(evidence) {
  const row = object(evidence);
  if (!Object.hasOwn(row, 'unresolved_internal_count') || !Array.isArray(row.external_inventory)) {
    return Object.hasOwn(row, 'unresolved_internal_count') && Number(row.unresolved_internal_count) > 0 ? 'hard_failure' : 'evidence_gap';
  }
  return Number(row.unresolved_internal_count) > 0 ? 'hard_failure' : 'pass';
}

function providerStatus(evidence) {
  const receipts = object(evidence).receipts;
  if (!Array.isArray(receipts)) return 'evidence_gap';
  for (const item of receipts) {
    const status = normalizePassFail(object(item).status);
    if (status === 'hard_failure') return 'hard_failure';
    if (status !== 'pass') return 'evidence_gap';
  }
  return 'pass';
}

function identityStatus(evidence) {
  const row = object(evidence);
  const title = typeof row.title === 'string' ? row.title.trim() : '';
  if (!title || !Array.isArray(row.placeholders)) return 'evidence_gap';
  if ((Array.isArray(row.environment_dependent_urls) ? row.environment_dependent_urls.length : row.environment_dependent_urls) || row.placeholders.length > 0) return 'required_owner_decision';
  return 'pass';
}

function gatedStatus(evidence) {
  const row = object(evidence);
  if (!row.schema || typeof row.schema !== 'string') return 'evidence_gap';
  return normalizePassFail(row.status) || 'evidence_gap';
}

function rollbackStatus(evidence) {
  const row = object(evidence);
  if (!row.status || typeof row.status !== 'string') return 'evidence_gap';
  if (row.status === 'partial') return 'hard_failure';
  return ['not_requested', 'rolled_back', 'ready'].includes(row.status) ? 'pass' : 'evidence_gap';
}

function ownerTasksFrom(evidence) {
  const row = object(evidence);
  const index = Array.isArray(row.tasks) || isObject(row.tasks) ? row.tasks : row;
  const tasks = OWNER_TASK_IDS.map((id) => {
    const item = isObject(index?.[id]) ? index[id] : array(index).find((entry) => entry?.id === id);
    const status = taskStatus(item);
    return {
      id,
      status,
      persisted: isObject(item) && Object.hasOwn(item, 'persisted') ? Boolean(item.persisted) : null,
      reloaded: isObject(item) && Object.hasOwn(item, 'reloaded') ? Boolean(item.reloaded) : null,
    };
  });
  return { schema: OWNER_TASK_SCHEMA, status: tasks.reduce((current, task) => worse(current, task.status), 'pass'), tasks };
}

function taskStatus(item) {
  const row = object(item);
  if (Object.keys(row).length === 0) return 'evidence_gap';
  if (Object.hasOwn(row, 'persisted') && Object.hasOwn(row, 'reloaded')) {
    if (row.persisted === true && row.reloaded === true) return 'pass';
    if (row.persisted === false || row.reloaded === false) return 'hard_failure';
  }
  return normalizePassFail(row.status) || 'evidence_gap';
}

function ownerTaskFindings(ownerTasks, owner, action, referenceValue) {
  return ownerTasks.tasks.filter((task) => task.status !== 'pass').map((task) => finding('owner_tasks', task.status, '', task.id, owner, action, referenceValue));
}

function dimension(id, status, owner, action, referenceValue, route, component) {
  return {
    status,
    owning_repository: owner,
    evidence_reference: referenceValue,
    findings: status === 'pass' ? [] : [finding(id, status, route, component, owner, action, referenceValue)],
    owner_tasks: null,
  };
}

function finding(dimensionId, status, route, component, owner, action, referenceValue) {
  return { dimension: dimensionId, status, route, component, evidence_reference: referenceValue, owning_repository: owner, recommended_next_action: action };
}

function normalizePassFail(status) {
  if (typeof status !== 'string' || !status) return null;
  if (Object.hasOwn(RANK, status)) return status;
  if (['passed', 'completed', 'success', 'ready'].includes(status)) return 'pass';
  if (['failed', 'rejected', 'mismatch', 'error'].includes(status)) return 'hard_failure';
  return null;
}

function worse(left, right) {
  return (RANK[right] || 0) > (RANK[left] || 0) ? right : left;
}

function planIdentity(value) {
  const row = object(value);
  return { schema: String(row.schema || ''), hash: String(row.hash || '') };
}

function validPlanIdentity(identity) {
  return identity?.schema === PLAN_IDENTITY_SCHEMA && sha256(identity?.hash);
}

function receiptSha256(input, receipt) {
  const supplied = input.materializationReceiptSha256 || input.materialization_receipt_sha256 || receipt.sha256 || receipt.receipt_instance_id;
  if (sha256(supplied)) return supplied;
  const planHash = object(receipt.plan_identity).hash || '';
  const schema = String(receipt.schema || '');
  const status = String(receipt.status || '');
  if (!schema && !status && !planHash) return '';
  return createHash('sha256').update(`${schema}\n${status}\n${planHash}`).digest('hex');
}

function reference(evidence) {
  const row = object(evidence);
  const value = {};
  if (typeof row.schema === 'string' && row.schema) value.schema = row.schema;
  if (typeof row.path === 'string' && row.path) value.path = row.path;
  if (sha256(row.sha256)) value.sha256 = row.sha256;
  return Object.keys(value).length > 0 ? value : null;
}

function sha256(value) {
  return typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
}

function object(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function isObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function array(value) {
  return Array.isArray(value) ? value : [];
}

function writeJson(file, value) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`);
}
