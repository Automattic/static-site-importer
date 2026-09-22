import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
  APPLY_REQUEST,
  PLAN_REQUEST,
  RECEIPT_SCHEMA,
  parseArguments,
  parseReceipt,
  receiptContractFailures,
  zipManifestFailures,
} from './run-release-import-gate.mjs';

test('parseReceipt takes the last non-empty stdout line and rejects non-objects', () => {
  const receipt = { schema: RECEIPT_SCHEMA, status: 'completed', steps: 1, response: { success: true } };
  const stdout = `wp-cli progress line\n${JSON.stringify(receipt)}\n`;
  assert.deepEqual(parseReceipt(stdout), receipt);
  assert.equal(parseReceipt(''), null);
  assert.equal(parseReceipt('not json'), null);
  assert.equal(parseReceipt('[1,2,3]'), null);
});

test('receiptContractFailures accepts a completed plan receipt', () => {
  const receipt = {
    schema: RECEIPT_SCHEMA,
    status: 'completed',
    steps: 1,
    response: { success: true, operation: 'plan', plan: { pages: [] } },
  };
  assert.deepEqual(receiptContractFailures(receipt, 'plan'), []);
});

test('receiptContractFailures rejects failed, wrong-schema, and missing-section receipts', () => {
  const failed = { schema: RECEIPT_SCHEMA, status: 'failed', steps: 1, response: { success: false, error: { code: 'x', message: 'y' } } };
  assert.equal(receiptContractFailures(failed, 'apply').length, 3);
  const wrongSchema = { schema: 'other/v1', status: 'completed', response: { success: true, result: {} } };
  assert.equal(receiptContractFailures(wrongSchema, 'result').length, 1);
  const missingSection = { schema: RECEIPT_SCHEMA, status: 'completed', response: { success: true } };
  assert.deepEqual(receiptContractFailures(missingSection, 'plan'), ['response.plan is not an object']);
  assert.deepEqual(receiptContractFailures(null, 'plan'), ['last stdout line does not parse as a JSON object']);
});

const MANIFEST = {
  schema: 'static-site-importer/runtime-package-manifest/v1',
  package: 'static-site-importer',
  package_root: 'static-site-importer',
  profiles: {
    'html-site-import': {
      selectors: [
        { type: 'file', path: 'static-site-importer.php' },
        { type: 'prefix', path: 'includes/' },
      ],
      required_files: ['static-site-importer.php', 'includes/abilities.php'],
    },
  },
};

test('zipManifestFailures accepts a complete listing under the package root', () => {
  const listing = [
    'static-site-importer/',
    'static-site-importer/static-site-importer.php',
    'static-site-importer/runtime-package-manifest.json',
    'static-site-importer/includes/',
    'static-site-importer/includes/abilities.php',
  ];
  assert.deepEqual(zipManifestFailures(listing, MANIFEST), []);
});

test('zipManifestFailures names every missing required file', () => {
  const listing = ['static-site-importer/static-site-importer.php'];
  assert.deepEqual(zipManifestFailures(listing, MANIFEST), [
    'profile required_files entry missing from zip: includes/abilities.php',
  ]);
});

test('zipManifestFailures rejects an unknown profile and a broken manifest', () => {
  assert.deepEqual(zipManifestFailures([], MANIFEST, 'missing-profile'), ['profile missing-profile is missing from the packaged runtime-package-manifest.json']);
  assert.equal(zipManifestFailures([], {}).length, 1);
  assert.equal(zipManifestFailures([], null).length, 1);
});

test('gate requests match the downstream runner acceptance contract', () => {
  assert.deepEqual(PLAN_REQUEST, {
    operation: 'plan',
    source: { type: 'zip', ref: 'request-bundle:website.zip' },
    slug: 'imported-site',
    name: 'Imported Site',
  });
  assert.deepEqual(APPLY_REQUEST, {
    operation: 'apply',
    source: { type: 'zip', ref: 'request-bundle:website.zip' },
    slug: 'imported-site',
    name: 'Imported Site',
    site_title: 'Imported Site',
    activate: true,
    remove_default_content: true,
    materialize_dependencies: true,
    theme_materialization: 'block',
  });
});

test('parseArguments requires zip and output', () => {
  assert.throws(() => parseArguments([]), /--zip/);
  assert.throws(() => parseArguments(['--zip', 'x.zip']), /--output/);
  assert.throws(() => parseArguments(['--zip', 'x.zip', '--output', 'dir', '--wat']), /Unknown argument/);
  const options = parseArguments(['--zip', 'a.zip', '--output', 'ev', '--keep']);
  assert.equal(options.zip, 'a.zip');
  assert.equal(options.output, 'ev');
  assert.equal(options.keep, true);
});
