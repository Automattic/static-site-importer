import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { authoringParityEvidence } from './authoring-parity-evidence.mjs';

export function completeInput(directory, overrides = {}) {
  const source = path.join(directory, 'source.txt');
  const candidate = path.join(directory, 'candidate.txt');
  const diff = path.join(directory, 'diff.txt');
  writeFileSync(source, 'roeeby responsive source\n');
  writeFileSync(candidate, 'roeeby responsive candidate\n');
  writeFileSync(diff, 'roeeby responsive diff\n');
  return {
    outputDirectory: path.join(directory, 'handoff'),
    fixtureId: 'roeeby-responsive-canonical',
    inputIdentity: { schema: 'static-site-importer/input-identity/v1', sha256: 'a'.repeat(64) },
    compilerIdentity: { schema: 'static-site-importer/compiler-identity/v1', name: 'generic-compiler', version: '1' },
    sitePlan: { schema: 'blocks-engine/wordpress-site-plan/v2', pages: [{ route: '/' }], routes: [{ path: '/' }] },
    materializationReceipt: { schema: 'static-site-importer/materialization-receipt/v2', status: 'completed', completed: { materialized_pages: { '/': { post_id: 7 } } } },
    routeEntityMapping: [{ route: '/', entity: '7' }],
    visualEvidence: [{ source_file: source, candidate_file: candidate, diff_file: diff, route: '/', viewport: { width: 1440, height: 900 }, entity: '7' }],
    editorEvidence: [{ route: '/', entity: '7', validation: { schema: 'wp-codebox/editor-validate-blocks/v1', invalid_blocks: 0 } }],
    authoringParityEvidence,
    qualityProjection: { schema: 'static-site-importer/quality-budget-admission/v1', production_status: 'passed' },
    ...overrides,
  };
}

