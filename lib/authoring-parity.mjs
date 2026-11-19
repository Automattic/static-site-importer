import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';

// Structural parity is deliberately independent of post text and image content.
export const AUTHORING_LANDMARKS = ['title', 'date', 'author', 'terms', 'sidebar', 'related', 'comments', 'contentLeft', 'contentWidth'];

// Archives list as many items as the term holds, so per-item landmarks
// (one date per listed post) compare by presence there, not by count.
export const ARCHIVE_ITEM_LANDMARKS = ['date', 'author', 'terms'];

export function compareAuthoringLandmarks(imported, newPost, { presence = [] } = {}) {
  const differences = AUTHORING_LANDMARKS.filter((key) => {
    const clamp = value => (presence.includes(key) && Number.isInteger(value) ? Math.min(value, 1) : value);
    const a = clamp(imported?.[key]);
    const b = clamp(newPost?.[key]);
    if (key === 'contentLeft' || key === 'contentWidth') return (a ?? null) === null && (b ?? null) === null ? false : !Number.isFinite(a) || !Number.isFinite(b) || Math.abs(a - b) > 4;
    return !Number.isInteger(a) || !Number.isInteger(b) || a < 0 || b < 0 || a !== b;
  });
  return { pass: differences.length === 0, differences };
}

export function authoringParityPasses(evidence) {
  return Array.isArray(evidence) && evidence.length > 0 && new Set(evidence.map(row => row?.cluster)).size === evidence.length && evidence.every(row =>
    typeof row?.cluster === 'string' && row.cluster.length > 0 && row.sibling &&
    typeof row.sibling_template === 'string' && row.sibling_template.length > 0 &&
    typeof row.probe_template === 'string' && row.probe_template.length > 0 &&
    row.pass === true && compareAuthoringLandmarks(row.landmarks?.imported, row.landmarks?.new_post, { presence: row.cluster.startsWith('taxonomy:') ? ARCHIVE_ITEM_LANDMARKS : [] }).pass);
}

// Executed in the frontend, not in the editor. Do not count global header/footer
// widgets as a sidebar or related query. Count regions, not their descendants.
export function authoringLandmarks(title) {
  const visible = el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0 && getComputedStyle(el).visibility !== 'hidden' && getComputedStyle(el).display !== 'none';
  const main = document.querySelector('main, [role="main"]') || document.querySelector('.wp-site-blocks') || document.body;
  const outsideChrome = el => !el.closest('header, footer, [role="banner"], [role="contentinfo"]');
  const elements = selector => [...main.querySelectorAll(selector)].filter(el => visible(el) && (selector !== '.wp-block-query' || outsideChrome(el)));
  const regions = selector => elements(selector).filter(el => !el.parentElement.closest(selector) || !main.contains(el.parentElement.closest(selector))).length;
  const content = main.querySelector('.wp-block-post-content, .entry-content') || (document.body.matches('.archive') ? main.querySelector('.wp-block-query') : null);
  const rect = content && visible(content) ? content.getBoundingClientRect() : null;
  return {
    title: elements('h1, h2').filter(el => el.textContent.trim() === title).length,
    date: regions('.wp-block-post-date, time'),
    author: regions('.wp-block-post-author, .wp-block-post-author-name, [rel="author"]'),
    terms: regions('.wp-block-post-terms, .cat-links, .tags-links'),
    sidebar: regions('aside, [role="complementary"], [class*="sidebar"]'),
    related: regions('.wp-block-query'),
    comments: regions('.wp-block-comments, .wp-block-post-comments-form, #comments'),
    contentLeft: rect ? rect.left : null,
    contentWidth: rect ? rect.width : null,
  };
}

// One browser-side orchestration serves both Codebox and the local import loop.
async function runAuthoringParity(endpoint, readLandmarks, compare, archivePresence) {
  const rpc = async (operation, sibling, evidence) => {
    const response = await fetch(endpoint, { method: 'POST', body: JSON.stringify({ operation, sibling, evidence }) });
    if (!response.ok) throw new Error(`Authoring parity endpoint failed: ${response.status}`);
    return response.json();
  };
  const frame = document.createElement('iframe');
  frame.style.cssText = 'width:1440px;height:900px;border:0;position:absolute;left:0;top:0';
  document.body.append(frame);
  const read = async item => {
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`Authoring parity navigation timed out: ${item.url}`)), 120000);
      frame.onload = () => { clearTimeout(timer); resolve(); };
      frame.src = item.url;
    });
    const win = frame.contentWindow;
    await win.document.fonts.ready;
    await new Promise(resolve => win.requestAnimationFrame(() => win.requestAnimationFrame(resolve)));
    const template = win.document.querySelector('meta[name="ssi-authoring-template"]')?.content;
    if (!template) throw new Error(`No served template for ${item.url}`);
    return { template, landmarks: win.eval(`(${readLandmarks.toString()})(${JSON.stringify(item.title)})`) };
  };
  const evidence = [];
  const clusters = new Set();
  try {
    const siblings = await rpc('list');
    for (const sibling of [...siblings.posts, ...siblings.terms]) {
      const imported = await read(sibling);
      const cluster = `${sibling.taxonomy ? `taxonomy:${sibling.taxonomy}` : sibling.type} × ${imported.template}`;
      if (clusters.has(cluster)) continue;
      clusters.add(cluster);
      try {
        const probe = await rpc('create', sibling);
        const authored = await read(probe);
        const landmarks = { imported: imported.landmarks, new_post: authored.landmarks };
        evidence.push({ cluster, sibling: sibling.url, sibling_template: imported.template, probe_template: authored.template, landmarks,
          ...compare(landmarks.imported, landmarks.new_post, { presence: sibling.taxonomy ? archivePresence : [] }) });
      } finally { await rpc('clean'); }
    }
  } finally {
    frame.remove();
    await rpc('clean');
  }
  await rpc('record', undefined, evidence);
}

const phpValue = value => `json_decode(base64_decode('${Buffer.from(JSON.stringify(value)).toString('base64')}'), true)`;

export function authoringParityRuntime(token = randomUUID().replaceAll('-', ''), fixtureId = null) {
  if (!/^[a-f0-9]{32}$/.test(token)) throw new Error('Invalid authoring parity token');
  const name = `ssi-authoring-${token}.php`;
  const cleanup = `function ssi_authoring_cleanup() {
    foreach (get_posts(['post_type'=>get_post_types(),'post_status'=>array_keys(get_post_stati()),'numberposts'=>-1,'meta_key'=>'_ssi_authoring_probe','meta_value'=>'${token}']) as $post) { wp_delete_post($post->ID, true); }
    foreach (get_option('ssi_authoring_terms_${token}', []) as $term) { wp_delete_term($term[0], $term[1]); }
    delete_option('ssi_authoring_terms_${token}');
  }`;
  const observer = `<?php
${cleanup}
add_filter('template_include', function($path) {
  add_action('wp_head', function() use ($path) {
    global $_wp_current_template_id;
    echo '<meta name="ssi-authoring-template" content="' . esc_attr($_wp_current_template_id ?: basename($path)) . '">';
  });
  return $path;
}, PHP_INT_MAX);
`;
  const endpoint = readFileSync(new URL('./authoring-parity-runtime.php', import.meta.url), 'utf8').replaceAll('SSI_AUTHORING_TOKEN', token);
  return {
    path: `/${name}?token=${token}`,
    stage: `delete_option('ssi_authoring_evidence_${token}'); wp_mkdir_p(WPMU_PLUGIN_DIR); if (false === file_put_contents(WPMU_PLUGIN_DIR . '/${name}', ${phpValue(observer)}) || false === file_put_contents(ABSPATH . '${name}', ${phpValue(endpoint)})) { throw new Exception('Cannot stage authoring parity'); } echo wp_json_encode(['url'=>site_url('/${name}?token=${token}')]);`,
    cleanup: `echo wp_json_encode(['fixture_id'=>${phpValue(fixtureId)},'authoring_parity_evidence'=>get_option('ssi_authoring_evidence_${token}', null)]); delete_option('ssi_authoring_evidence_${token}'); if (!function_exists('ssi_authoring_cleanup')) { ${cleanup} } ssi_authoring_cleanup(); foreach ([ABSPATH . '${name}', WPMU_PLUGIN_DIR . '/${name}'] as $file) { if (is_file($file) && !unlink($file)) { throw new Exception('Cannot remove authoring parity file'); } }`,
  };
}

export function authoringParityExpression(endpoint) {
  return `(async () => { const AUTHORING_LANDMARKS = ${JSON.stringify(AUTHORING_LANDMARKS)}; await (${runAuthoringParity.toString()})(${JSON.stringify(endpoint)}, ${authoringLandmarks.toString()}, ${compareAuthoringLandmarks.toString()}, ${JSON.stringify(ARCHIVE_ITEM_LANDMARKS)}); })()`;
}

export async function collectAuthoringParity({ wp, browser }) {
  const runtime = authoringParityRuntime();
  let page;
  let evidence;
  try {
    const { url } = JSON.parse(wp('eval', runtime.stage));
    page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.goto(url, { waitUntil: 'load', timeout: 120000 });
    await page.evaluate(authoringParityExpression(url));
  } finally {
    try { await page?.close(); } finally {
      evidence = JSON.parse(wp('eval', runtime.cleanup)).authoring_parity_evidence;
    }
  }
  return evidence;
}
