import fs from 'node:fs';
import path from 'node:path';

// Codebox transports action-summary assertions in browserEvidence.summary.
// Artifact-only runs expose the same JSON through files.summary.path.
export function collectAuthoringParityEvidence(payloads, directory) {
  for (const payload of payloads) {
    if (payload.command !== 'wordpress.browser-actions') continue;
    let summary = payload.summary;
    const ref = payload.summaryFile?.path || payload.files?.summary?.path;
    if (!summary?.assertions && ref) {
      const candidates = path.isAbsolute(ref) ? [ref] : [path.resolve(directory, ref), path.resolve(directory, '..', ref)];
      const file = candidates.find(candidate => fs.existsSync(candidate));
      if (file) summary = JSON.parse(fs.readFileSync(file, 'utf8'));
    }
    for (const result of summary?.assertions?.results || []) {
      if (result.kind === 'evaluate' && Array.isArray(result.actual?.authoring_parity_evidence)) {
        return result.actual.authoring_parity_evidence;
      }
    }
  }
  return null;
}
