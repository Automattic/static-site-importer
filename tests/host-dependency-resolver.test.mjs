import assert from 'node:assert/strict';
import test from 'node:test';

import { buildFixtureMatrixRecipe } from '../lib/fixture-matrix/steps/recipe-builder.mjs';

test('plans and projects provider plugins inside the import recipe', () => {
  const recipe = buildFixtureMatrixRecipe({
    matrix: {
      id: 'phased-dependency-planning',
      fixtures: ['fixture-a', 'fixture-b'].map((id) => ({
        id,
        label: id,
        directory: `/fixtures/${id}`,
        entrypoint: 'index.html',
      })),
    },
    staticSiteImporterPath: '/tmp/static-site-importer',
    artifactsDirectory: '/tmp/artifacts',
    phasedDependencyPlanning: true,
    editorValidation: false,
    visualParity: false,
    attemptId: 'attempt-1',
  });

  const planningSteps = recipe.workflow.steps.filter((step) => step.metadata?.phase === 'dependency-plan');
  const mergeStep = recipe.workflow.steps.find((step) => step.metadata?.phase === 'dependency-plan-merge');
  const typedPlan = recipe.artifacts.typed.find((artifact) => artifact.type === 'static-site-importer/runtime-dependency-plan');
  const mergeIndex = recipe.workflow.steps.indexOf(mergeStep);
  const prepareIndex = recipe.workflow.steps.findIndex((step) => step.metadata?.phase === 'dependency-prepare');

  assert.equal(planningSteps.length, 2);
  assert.deepEqual(mergeStep.pluginInput, {
    artifact: typedPlan.name,
    packages: {
      resolver: 'wordpress.org-latest-stable',
      items: '/entries',
      map: { slug: '/slug', pluginFile: '/plugin_entrypoint' },
    },
  });
  assert.equal(typedPlan.required, true);
  assert.equal(typedPlan.parseJson, true);
  assert.equal(typedPlan.payloadSchema, 'static-site-importer/runtime-dependency-plan/v1');
  assert.ok(mergeIndex > Math.max(...planningSteps.map((step) => recipe.workflow.steps.indexOf(step))));
  assert.ok(prepareIndex > mergeIndex, 'projected plugins must be ready before dependency preparation and import');
  assert.equal(recipe.inputs.extra_plugins.length, 1, 'provider plugins are not host-staged before the runtime starts');

  const mergeCode = [...mergeStep.args[0].matchAll(/([A-Za-z0-9+/=]{100,})/g)]
    .map((match) => Buffer.from(match[1], 'base64').toString('utf8'))
    .find((candidate) => candidate.startsWith('$plans ='));
  assert.ok(mergeCode, 'merge step must contain executable PHP');
  assert.match(mergeCode, /Fixture dependency plan is malformed/);
  assert.match(mergeCode, /Fixture dependency plan contains an unsupported package artifact/);
  assert.match(mergeCode, /'entries' => array_values\(\$entries\)/, 'empty plans produce an empty projected package list');
});
