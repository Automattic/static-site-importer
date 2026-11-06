// Exit 0 while the Blocks Engine output of a page still emits the generated
// companion block <prefix>/<subject>. The importer counts that block as a raw
// HTML companion: captured markup the editor cannot edit as native blocks. The
// defect is gone only when the content converts to native blocks and the
// companion is no longer emitted (see emits.mjs).
import { exitWhileEmitted } from './emits.mjs';
exitWhileEmitted();
