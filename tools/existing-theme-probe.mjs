// Disposable quality evidence for #2810. Retains screenshots and JSON under OUT.
import { mkdir, readdir, readFile, writeFile, symlink } from 'node:fs/promises';
import { resolve, join, relative, extname, dirname } from 'node:path';
import { spawn } from 'node:child_process';
import { chromium } from 'playwright';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';
import { compareVisualParityPngFiles } from '../lib/fixture-matrix/image-comparison.mjs';

const args = process.argv.slice(2);
const hostIndex = args.indexOf('--host');
const host = hostIndex < 0 ? 'twentytwentyfive' : args.splice(hostIndex, 2)[1];
if (!['generated', 'twentytwentyfive'].includes(host)) throw new Error('Unknown --host');
const root = resolve(process.env.FIXTURE_ROOT || 'blocks-engine/fixtures/websites');
const out = resolve(process.env.OUT || `probe-out/${host}`);
await mkdir(join(out, '_cache'), { recursive: true });
const fixtures = args.length ? args : (await readdir(root, { withFileTypes: true })).filter(e => e.isDirectory()).map(e => e.name);
const text = new Set(['.html', '.htm', '.css', '.js', '.mjs', '.json', '.svg', '.txt', '.xml', '.md', '.webmanifest']);
async function walk(dir, base = dir) {
  const files = [];
  for (const e of await readdir(dir, { withFileTypes: true })) {
    const path = join(dir, e.name);
    if (e.isDirectory()) files.push(...await walk(path, base));
    else if (e.name !== 'fixture.json') {
      const bytes = await readFile(path);
      files.push({ path: relative(base, path), ...(text.has(extname(path)) ? { content: bytes.toString() } : { content_base64: bytes.toString('base64') }) });
    }
  }
  return files;
}
const browser = await chromium.launch();
const summary = [];
let port = Number(process.env.PORT || 8100);
try {
  for (const id of fixtures.filter(id => !id.endsWith('.zip'))) {
    const dest = join(out, id);
    const work = join(out, '_work', id);
    await mkdir(dest, { recursive: true });
    await mkdir(work, { recursive: true });
    try { await symlink(join(out, '_cache'), join(work, 'downloads')); } catch (e) { if (e.code !== 'EEXIST') throw e; }
    const wpPort = port++, srcPort = port++;
    const origin = `http://localhost:${wpPort}`;
    const row = { fixture: id, host };
    const servers = [];
    let rt, context;
    try {
      rt = await provisionWordPress({ workDir: work, origin });
      await symlink(resolve(process.env.PLUGIN_ROOT || '.'), join(rt.wpDir, 'wp-content/plugins/static-site-importer'));
      rt.wpRun(['plugin', 'activate', 'static-site-importer']);
      const php = code => rt.wpRun(['eval', code]).stdout;
      const json = code => { const raw = php(code); return JSON.parse(raw.slice(raw.indexOf('{'))); };
      if (process.env.RUN_SMOKE === '1') {
        for (const test of ['smoke-existing-theme-global-styles.php', 'smoke-existing-theme-runtime.php']) {
          await writeFile(join(dest, `${test}.log`), rt.wpRun(['eval-file', resolve('tests', test)]).stdout);
        }
      }
      const files = await walk(join(root, id));
      const entry = files.find(f => f.path === 'index.html')?.path || files.find(f => /\.html?$/.test(f.path))?.path;
      const slug = id.toLowerCase().replace(/[^a-z0-9-]/g, '-');
      async function apply(destination, suffix, sourceFiles, entrypoint) {
        let request = { operation: 'apply', destination, activate: destination === 'generated_theme', slug: `${slug}-${suffix}`, source: { type: 'files', entrypoint, files: sourceFiles } };
        let result;
        for (let i = 0; i < 40; i++) {
          const path = join(dest, `${suffix}-request.json`);
          await writeFile(path, JSON.stringify(request));
          result = json(`wp_set_current_user(1); do_action('wp_abilities_api_categories_init'); do_action('wp_abilities_api_init'); $r=wp_get_ability('static-site-importer/import')->execute(json_decode(file_get_contents(${JSON.stringify(path)}),true)); echo wp_json_encode(is_wp_error($r)?array('wp_error'=>$r->get_error_code(),'message'=>$r->get_error_message(),'data'=>$r->get_error_data()):$r);`);
          if (result.wp_error || !result.continuation) break;
          request = { ...request, source: { type: 'files', import_id: result.import_id } };
          const cp = result.result?.runtime_lifecycle_checkpoint;
          if (cp) Object.assign(request, { runtime_lifecycle_phase: 'resume', runtime_lifecycle_checkpoint: cp, runtime_lifecycle_request_id: result.result?.fresh_runtime?.request_id || '' });
        }
        await writeFile(join(dest, `${suffix}-result.json`), JSON.stringify(result, null, 2));
        return result;
      }
      let addedFiles = files, addedEntry = entry;
      if (host === 'generated') {
        const generated = await apply('generated_theme', 'host', files, entry);
        row.generated = { success: generated.success, error: generated.wp_error || generated.error };
        row.active_theme = rt.wpRun(['theme', 'list', '--status=active', '--field=name']).stdout.trim();
        addedEntry = join(dirname(entry), 'mcp-added-page.html');
        addedFiles = files.filter(f => !/\.html?$/.test(f.path)).concat({ ...files.find(f => f.path === entry), path: addedEntry });
      }
      const result = await apply('existing_theme', 'added', addedFiles, addedEntry);
      row.import = result.wp_error ? 'error' : result.success === false ? 'failed' : 'ok';
      row.failure = result.wp_error || result.failure_reason || result.error || null;
      const site = json(`$out=array(); foreach(get_posts(array('post_type'=>'page','post_status'=>'any','numberposts'=>-1,'meta_key'=>'_static_site_importer_css_section')) as $p){ $c=array('total'=>0,'html'=>0,'freeform'=>0,'missing'=>0,'unregistered'=>0); $w=function($bs)use(&$w,&$c){foreach($bs as $b){$n=$b['blockName'];if(!$n){if(trim($b['innerHTML'])!=='')$c['freeform']++;continue;} $c['total']++;if($n==='core/html')$c['html']++;if($n==='core/missing')$c['missing']++;if(!WP_Block_Type_Registry::get_instance()->is_registered($n))$c['unregistered']++;$w($b['innerBlocks']);}};$w(parse_blocks($p->post_content));$prov=json_decode(get_post_meta($p->ID,'_static_site_importer_provenance',true),true);$out[]=array('id'=>$p->ID,'source'=>$prov['source_path']??'','blocks'=>$c);} echo wp_json_encode(array('pages'=>$out,'global_css_bytes'=>strlen(wp_get_global_styles_custom_css())));`);
      Object.assign(row, site);
      servers.push(spawn('php', ['-S', `localhost:${wpPort}`, '-t', rt.wpDir], { stdio: 'ignore' }), spawn('php', ['-S', `localhost:${srcPort}`, '-t', join(root, id)], { stdio: 'ignore' }));
      await new Promise(r => setTimeout(r, 1200));
      context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      const page = await context.newPage();
      await page.goto(`${origin}/wp-login.php`);
      await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'password'); await page.click('#wp-submit');
      await page.waitForLoadState('networkidle');
      for (const p of row.pages) {
        try {
          const source = join(dest, `${p.id}-source.png`), front = join(dest, `${p.id}-front.png`);
          const sourcePath = p.source?.replace(/^website\//, '');
          await page.goto(`http://localhost:${srcPort}/${sourcePath === addedEntry ? entry : sourcePath || entry}`, { waitUntil: 'networkidle' });
          await page.screenshot({ path: source, fullPage: true });
          await page.goto(`${origin}/?page_id=${p.id}&preview=true`, { waitUntil: 'networkidle' });
          const rootElement = page.locator('[class*="ssi-page-"]').first();
          p.root_width = (await rootElement.boundingBox())?.width;
          await rootElement.screenshot({ path: front, animations: 'disabled', timeout: 5000 });
          p.visual = compareVisualParityPngFiles(source, front, join(dest, `${p.id}-diff.png`));
        } catch (e) { p.probe_error = e.message; }
        try {
          await page.goto(`${origin}/wp-admin/post.php?post=${p.id}&action=edit`);
          await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks()?.length > 0);
          p.editor = await page.evaluate(() => {
            const c = { total: 0, invalid: 0, missing: 0, html: 0 };
            const walk = bs => bs.forEach(b => { c.total++; if (b.isValid === false) c.invalid++; if (b.name === 'core/missing') c.missing++; if (b.name === 'core/html') c.html++; walk(b.innerBlocks || []); });
            walk(window.wp.data.select('core/block-editor').getBlocks()); return c;
          });
          await page.screenshot({ path: join(dest, `${p.id}-editor.png`) });
        } catch (e) { p.probe_error = e.message; }
      }
    } catch (e) { row.error = String(e.stderr || e.message); }
    finally { await context?.close(); servers.forEach(s => s.kill()); await rt?.close(); }
    summary.push(row);
    console.log(JSON.stringify(row));
    await writeFile(join(out, 'summary.json'), JSON.stringify(summary, null, 2));
    const pages = summary.flatMap(r => r.pages || []);
    const ratios = pages.map(p => p.visual?.mismatch_ratio).filter(Number.isFinite).sort((a, b) => a - b);
    await writeFile(join(out, 'totals.json'), JSON.stringify({ fixtures: summary.length, imported: summary.filter(r => r.import === 'ok' && r.pages?.length).length, probe_errors: pages.filter(p => p.probe_error).length, blocks: pages.reduce((sum, p) => { for (const [k, v] of Object.entries(p.blocks)) sum[k] = (sum[k] || 0) + v; return sum; }, {}), editor_invalid: pages.reduce((n, p) => n + (p.editor?.invalid || 0), 0), visual: { measured: ratios.length, min: ratios[0], median: ratios[Math.floor(ratios.length / 2)], max: ratios.at(-1) } }, null, 2));
  }
} finally { await browser.close(); }
