import test from 'node:test';
import assert from 'node:assert/strict';
import { cookHistory, designScope, ownIssue, rerouteScope, COOK_NOTE, CAP_NOTICE, DESIGN_NOTICE, REROUTE_NOTICE, REWORK_NOTE } from '../tools/import-loop/lib.mjs';

test('Cook history counts failures and design rounds since guidance', () => {
  const note = { body: `${COOK_NOTE}wf-x -->\n### Cook stopped without a pull request` };
  const accept = { body: `${COOK_NOTE}wf-a-cook-i1-n0-a1 -->\n**Summary:** Decision: accept — the nesting mirrors the source.` };
  const reroute = { body: `${COOK_NOTE}wf-a-cook-i1-n0-a1 -->\n**Summary:** Decision: reroute to \`Org/capture-repo\` — the badge is host residue the capture keeps.` };
  const guidance = { body: 'Root cause and architectural plan: #6160.' };
  const cap = { body: `${CAP_NOTICE}\nThe import loop has stopped cooking this issue.` };
  const design = { body: `${DESIGN_NOTICE}\n${designScope({ site: 'https://a.test/', instance: 'wf-a' })}\nDesign round 1 of 2.` };
  // A design round on another site's capture accepts nothing here.
  const elsewhere = { body: `${DESIGN_NOTICE}\n${designScope({ site: 'https://b.test/', instance: 'wf-b' })}\nDesign round 1 of 2.` };
  // A refuted PR's rework reports on the PR; triage's issue note is what counts it.
  const rework = { body: `${REWORK_NOTE}\nPass 3 refuted #6023 at head 82aa72eb.` };
  for (const [comments, history] of [
    [[], { failed: 0, designs: 0, accepted: false }],
    [[note, note, guidance, note], { failed: 1, designs: 0, accepted: false }],
    [[note, note, cap, note], { failed: 3, designs: 0, accepted: false }],
    [[note, note, design, note, note, design, note], { failed: 1, designs: 2, accepted: false }],
    [[note, note, design, accept], { failed: 1, designs: 1, accepted: true }],
    [[accept, note], { failed: 2, designs: 0, accepted: false }],
    [[note, design, accept, cap, guidance], { failed: 0, designs: 0, accepted: false }],
    [[rework, rework, design, rework], { failed: 1, designs: 1, accepted: false }],
    [[note, note, elsewhere, accept], { failed: 3, designs: 0, accepted: false }],
    // A reroute holds for its site, and the loop's own notice re-arms nothing.
    [[note, note, design, reroute, { body: `${REROUTE_NOTICE}\nRerouted.` }], { failed: 1, designs: 1, accepted: false, rerouted: { repo: 'capture-repo', note: reroute.body } }],
    [[note, note, elsewhere, reroute], { failed: 3, designs: 0, accepted: false }],
    // Two sites opening rounds together use up neither's rounds.
    [[note, note, design, elsewhere, elsewhere, note], { failed: 1, designs: 1, accepted: false }],
  ]) assert.deepEqual(cookHistory(comments, 'https://a.test/'), history);
});

test('A site tracks a finding on its own rerouted issue, else the shared one', () => {
  const hit = (repo, state, body = '') => ({ url: `${repo}#${state}`, state, repository: { name: repo }, body: `${body}\nLoop signature: \`abc\`` });
  const shared = hit('engine', 'open');
  const mine = (state) => hit('capture', state, rerouteScope({ site: 'https://a.test/', from: shared.url }));
  const theirs = hit('capture', 'open', rerouteScope({ site: 'https://b.test/', from: shared.url }));
  for (const [hits, picked] of [
    [[shared], shared],
    [[shared, mine('open')], mine('open')],
    // A fixed reroute that returns reopens its own record, not the shared one.
    [[shared, mine('closed')], mine('closed')],
    [[theirs, shared], shared],
    [[theirs], null],
    [[hit('elsewhere', 'open')], null],
  ]) assert.deepEqual(ownIssue(hits, 'abc', 'https://a.test/', ['engine', 'capture']), picked);
});
