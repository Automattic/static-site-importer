import { createHash } from 'node:crypto';
import { authoringParityRuntime, authoringParityExpression } from '../../authoring-parity.mjs';
import { shellToken } from '../shared/utils.mjs';
import { fixtureStepMetadata } from './shared.mjs';

export function authoringParitySteps(fixture, attemptId) {
  const runtime = authoringParityRuntime(createHash('sha256').update(`${attemptId}:${fixture.id}`).digest('hex').slice(0, 32));
  const cli = (code, phase) => ({
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`eval(base64_decode('${Buffer.from(code).toString('base64')}'));`)}`],
    metadata: fixtureStepMetadata(fixture, phase),
  });
  return [
    { ...cli(runtime.stage, 'authoring-parity-stage'), allowFailure: true, advisory: true },
    {
      command: 'wordpress.browser-actions',
      // Codebox only persists evaluate values when an assert is supplied. The
      // null sentinel deliberately differs from the evidence object: intake
      // judges the returned evidence, not this transport assertion.
      allowFailure: true,
      advisory: true,
      args: [
        'viewport=1440x900', 'capture=steps', 'step-timeout=600s', 'timeout=900s',
        `steps-json=${JSON.stringify([
          { kind: 'navigate', url: runtime.path },
          { kind: 'evaluate', expression: authoringParityExpression(runtime.path), assert: null },
        ])}`,
      ],
      metadata: fixtureStepMetadata(fixture, 'authoring-parity'),
    },
    cli(runtime.cleanup, 'authoring-parity-cleanup'),
  ];
}
