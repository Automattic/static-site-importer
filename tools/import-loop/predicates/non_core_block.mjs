// Exit 0 while the Blocks Engine output of a page still emits the generated
// block <prefix>/<subject>, a block outside WordPress core. An editor can only
// change its structure through the importer's own block, not with core tools.
// The defect is gone when the same content is built from core blocks and the
// page renders exactly as before; the acceptance gate holds the whole import to
// pixel parity (see emits.mjs, gates/collateral.mjs).
import { exitWhileEmitted } from './emits.mjs';
exitWhileEmitted();
