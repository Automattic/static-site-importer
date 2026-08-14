import assert from 'node:assert/strict';
import test from 'node:test';

import {
  collectEditorValidation,
  collectEditorValidationDiagnostics,
} from '../lib/fixture-matrix/collectors/editor-validation.mjs';

test('fails editor validation when Gutenberg substitutes core/missing for a declared block', () => {
  const payload = {
    schema: 'wp-codebox/editor-validate-blocks/v1',
    results: [
      { name: 'example/available', isValid: true },
      { name: 'core/missing', originalName: 'example/declared', isValid: true },
    ],
  };

  assert.deepEqual(collectEditorValidation(payload), {
    schema: 'wp-codebox/editor-validate-blocks/v1',
    validation_method: 'wp.blocks.validateBlock',
    validation_provider: 'wordpress-block-editor',
    block_types_registered: 0,
    result_count: 2,
    results_complete: false,
    total_blocks: 2,
    valid_blocks: 1,
    invalid_blocks: 1,
  });
  assert.deepEqual(collectEditorValidationDiagnostics(payload), [
    {
      kind: 'editor_block_invalid',
      block_name: 'example/declared',
      observed_block_name: 'core/missing',
      original_block_name: 'example/declared',
      selector: '',
      source_path: '',
      observed_output: '',
      message: 'Editor reported block "example/declared" as invalid: This block contains unexpected or invalid content.',
    },
  ]);
});
