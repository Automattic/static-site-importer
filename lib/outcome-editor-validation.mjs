import { collectEditorValidation, collectEditorValidationDiagnostics } from './fixture-matrix/collectors/editor-validation.mjs';

/** Validate saved bytes using the block registry shipped by the installed editor.
 * The authenticated page is never used for logged-out outcome probes.
 */
export async function validateOutcomeDocuments({ page, candidateOrigin, documents }) {
  await page.goto(new URL('/wp-admin/post-new.php', candidateOrigin).href, { waitUntil: 'load' });
  try { return await validateInEditor(page, documents); }
  // A left-open editor keeps its heartbeat refreshing the draft's post lock,
  // which would mutate the site the remaining checks measure.
  finally { await page.goto('about:blank'); }
}

async function validateInEditor(page, documents) {
  if (!await page.evaluate(() => Boolean(window.wp?.blocks?.parse && window.wp?.blocks?.validateBlock))) throw new Error('installed_editor_unavailable');
  await page.waitForFunction(() => window.wp.blocks.getBlockTypes().length > 0);
  const rows = [];
  for (const document of documents) {
    const payload = await page.evaluate(content => {
      const results = [];
      // Issues are console records ({ log, args }) in printf form.
      const format = issue => { const [template, ...args] = Array.isArray(issue?.args) ? issue.args : [String(issue?.message ?? issue)]; return String(template).replace(/%[sdoO]/g, () => { const value = args.shift(); return typeof value === 'string' ? value : JSON.stringify(value); }).slice(0, 2000); };
      const visit = blocks => {
        for (const block of blocks) {
          const [valid, issues] = window.wp.blocks.validateBlock(block);
          // parse() already applied the block's deprecations: a block that only
          // matches a deprecated save opens cleanly and is rewritten on save,
          // so it is a deprecated shape, not an editor-invalid block.
          const isValid = block.isValid !== false;
          results.push({ name: block.name, originalName: block.originalName ?? block.attributes?.originalName, isValid, ...(isValid && !valid ? { deprecated_save_shape: true } : {}), issues: issues.filter(issue => !String(issue?.args?.[0] ?? '').startsWith('Block validation failed')).map(format) });
          visit(block.innerBlocks ?? []);
        }
      };
      visit(window.wp.blocks.parse(content));
      return { validation_method: 'wp.blocks.validateBlock', total_blocks: results.length, valid_blocks: results.filter(row => row.isValid).length, invalid_blocks: results.filter(row => !row.isValid).length, deprecated_save_shapes: results.filter(row => row.deprecated_save_shape).length, results };
    }, document.content);
    rows.push({ id: document.id, type: document.type, validation: collectEditorValidation(payload), diagnostics: collectEditorValidationDiagnostics(payload), results: payload.results });
  }
  return rows;
}
