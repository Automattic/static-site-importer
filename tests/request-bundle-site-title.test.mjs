import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

test('route title edges derive site identity only with a majority', async () => {
  const cases = [
    { label: 'trailing name', titles: ['Research since 1972 | Sylvestre &amp; Co.', 'About | Sylvestre &amp; Co.', 'News | Sylvestre &amp; Co.'], name: 'Sylvestre & Co.', tagline: 'Research since 1972' },
    { label: 'leading name', titles: ['Sylvestre &amp; Co. — Research since 1972', 'Sylvestre &amp; Co. — About', 'Sylvestre &amp; Co. — News'], name: 'Sylvestre & Co.', tagline: 'Research since 1972' },
    { label: 'entry name at opposite edge', titles: ['Sylvestre &amp; Co. | Research since 1972', 'About | Sylvestre &amp; Co.', 'News | Sylvestre &amp; Co.'], name: 'Sylvestre & Co.', tagline: 'Research since 1972' },
    { label: 'no majority', titles: ['Entrypoint | Original', 'About | Other', 'News | Third'], name: 'Entrypoint | Original', tagline: '' },
  ];
  const workDir = await mkdtemp(join(tmpdir(), 'ssi-route-site-title-'));
  let runtime;
  try {
    for (const [index, row] of cases.entries()) {
      runtime = await provisionWordPress({ workDir: join(workDir, `runtime-${index}`) });
      await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
      runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
      const bundle = `bundle-${index}`;
      await mkdir(join(workDir, bundle, 'website'), { recursive: true });
      for (const [route, title] of row.titles.entries()) {
        await writeFile(join(workDir, bundle, 'website', route === 0 ? 'index.html' : `page-${route}.html`), `<!doctype html><html><head><title>${title}</title></head><body><main><h1>Page ${route}</h1><p>Content.</p></main></body></html>`);
      }
      runtime.wpRun(['option', 'update', 'blogdescription', '']);
      const request = join(workDir, `apply-${index}.json`);
      await writeFile(request, JSON.stringify({ operation: 'apply', source: { type: 'files', ref: `request-bundle:${bundle}` }, slug: `route-title-${index}`, name: `Route title ${index}`, activate: true }));
      const receipt = JSON.parse(runtime.wpRun(['static-site-importer', 'import', `--request=${request}`]).stdout.trim().split('\n').at(-1));
      assert.equal(receipt.status, 'completed', `${row.label}: ${JSON.stringify(receipt).slice(0, 500)}`);
      assert.equal(runtime.wpRun(['eval', "echo html_entity_decode( get_option( 'blogname' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );"]).stdout.trim(), row.name, row.label);
      assert.equal(runtime.wpRun(['option', 'get', 'blogdescription']).stdout.trim(), row.tagline, row.label);
      await runtime.close();
      runtime = null;
    }
  } finally {
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});

// A request bundle delivers its files as payload references, so the site title
// must be read through the bundle's payload reader, not inline file content.
test('a request-bundle import names the site from the source document title', async () => {
  const workDir = await mkdtemp(join(tmpdir(), 'ssi-site-title-'));
  let runtime;
  try {
    runtime = await provisionWordPress({ workDir });
    await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
    await mkdir(join(workDir, 'bundle/website'), { recursive: true });
    await writeFile(join(workDir, 'bundle/website/index.html'), '<!doctype html><html><head><title>Northwind Studio</title></head><body><main><h1>Welcome</h1><p>Hand-built furniture.</p></main></body></html>');
    const request = join(workDir, 'apply.json');
    await writeFile(request, JSON.stringify({ operation: 'apply', source: { type: 'files', ref: 'request-bundle:bundle' }, slug: 'northwind', name: 'northwind', activate: true }));
    const receipt = JSON.parse(runtime.wpRun(['static-site-importer', 'import', `--request=${request}`]).stdout.trim().split('\n').at(-1));
    assert.equal(receipt.status, 'completed', JSON.stringify(receipt).slice(0, 500));
    assert.equal(runtime.wpRun(['option', 'get', 'blogname']).stdout.trim(), 'Northwind Studio');
  } finally {
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
