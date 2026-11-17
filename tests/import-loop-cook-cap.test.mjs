import test from 'node:test';
import assert from 'node:assert/strict';
import { cookHistory, COOK_NOTE, CAP_NOTICE, DESIGN_NOTICE } from '../tools/import-loop/lib.mjs';

test('Cook history counts failures and design rounds since guidance', () => {
  const note = { body: `${COOK_NOTE}wf-x -->\n### Cook stopped without a pull request` };
  const accept = { body: `${COOK_NOTE}wf-y -->\n**Summary:** Decision: accept — the nesting mirrors the source.` };
  const guidance = { body: 'Root cause and architectural plan: #6160.' };
  const cap = { body: `${CAP_NOTICE}\nThe import loop has stopped cooking this issue.` };
  const design = { body: `${DESIGN_NOTICE}\nDesign round 1 of 2.` };
  for (const [comments, history] of [
    [[], { failed: 0, designs: 0, accepted: false }],
    [[note, note, guidance, note], { failed: 1, designs: 0, accepted: false }],
    [[note, note, cap, note], { failed: 3, designs: 0, accepted: false }],
    [[note, note, design, note, note, design, note], { failed: 1, designs: 2, accepted: false }],
    [[note, note, design, accept], { failed: 1, designs: 1, accepted: true }],
    [[accept, note], { failed: 2, designs: 0, accepted: false }],
    [[note, design, accept, cap, guidance], { failed: 0, designs: 0, accepted: false }],
  ]) assert.deepEqual(cookHistory(comments), history);
});
