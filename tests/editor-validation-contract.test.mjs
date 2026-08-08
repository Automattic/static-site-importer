import assert from 'node:assert/strict';
import test from 'node:test';

import {
  collectEditorValidation,
  collectEditorValidationDiagnostics,
} from '../lib/fixture-matrix/collectors/editor-validation.mjs';

test('fails editor validation when Gutenberg substitutes core/missing for a companion block', () => {
  const payload = {
    schema: 'wp-codebox/editor-validate-blocks/v1',
    results: [
      { name: 'blocks-engine/form-select', isValid: true },
      { name: 'core/missing', originalName: 'blocks-engine/form-input', isValid: true },
    ],
  };

  assert.deepEqual(collectEditorValidation(payload), {
    validation_method: 'wp.blocks.validateBlock',
    validation_provider: 'wordpress-block-editor',
    total_blocks: 2,
    valid_blocks: 1,
    invalid_blocks: 1,
  });
  assert.deepEqual(collectEditorValidationDiagnostics(payload), [
    {
      kind: 'editor_block_invalid',
      block_name: 'blocks-engine/form-input',
      observed_block_name: 'core/missing',
      original_block_name: 'blocks-engine/form-input',
      selector: '',
      source_path: '',
      observed_output: '',
      message: 'Editor reported block "blocks-engine/form-input" as invalid: This block contains unexpected or invalid content.',
    },
  ]);
});
