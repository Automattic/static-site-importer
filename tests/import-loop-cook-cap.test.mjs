import test from 'node:test';
import assert from 'node:assert/strict';
import { failedCooksSinceGuidance, COOK_NOTE, CAP_NOTICE } from '../tools/import-loop/lib.mjs';

test('failed Cooks count from the last guidance comment, ignoring the cap notice', () => {
  const note = { body: `${COOK_NOTE}wf-x -->\n### Cook stopped without a pull request` };
  const guidance = { body: 'Root cause and architectural plan: #6160.' };
  const cap = { body: `${CAP_NOTICE}\nThe import loop has stopped cooking this issue.` };
  for (const [comments, failed] of [
    [[], 0],
    [[note, note, note], 3],
    [[note, note, guidance, note], 1],
    [[note, note, cap, note], 3],
    [[note, note, cap, guidance], 0],
  ]) assert.equal(failedCooksSinceGuidance(comments), failed);
});
