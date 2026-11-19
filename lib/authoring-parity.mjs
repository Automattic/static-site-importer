import { randomUUID } from 'node:crypto';

// Structural parity is deliberately independent of post text and image content.
export const AUTHORING_LANDMARKS = ['title', 'date', 'author', 'terms', 'sidebar', 'related', 'comments', 'contentLeft', 'contentWidth'];

export function compareAuthoringLandmarks(imported, newPost) {
  const differences = AUTHORING_LANDMARKS.filter((key) => {
    const a = imported?.[key];
    const b = newPost?.[key];
    if (key === 'contentLeft' || key === 'contentWidth') return !Number.isFinite(a) || !Number.isFinite(b) || Math.abs(a - b) > 4;
    return !Number.isInteger(a) || !Number.isInteger(b) || a < 0 || b < 0 || a !== b;
  });
  return { pass: differences.length === 0, differences };
}

export function authoringParityPasses(evidence) {
  return Array.isArray(evidence) && evidence.length > 0 && new Set(evidence.map(row => row?.cluster)).size === evidence.length && evidence.every(row =>
    typeof row?.cluster === 'string' && row.cluster.length > 0 && row.sibling &&
    typeof row.sibling_template === 'string' && row.sibling_template.length > 0 &&
    typeof row.probe_template === 'string' && row.probe_template.length > 0 &&
    row.pass === true && compareAuthoringLandmarks(row.landmarks?.imported, row.landmarks?.new_post).pass);
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

/** wp is the existing WP-CLI runner; browser is the caller's Playwright browser.
 * The temporary MU observer records the template actually served (not merely
 * _wp_page_template, which is empty for template-hierarchy defaults).
 */
export async function collectAuthoringParity({ wp, browser }) {
  const json = code => JSON.parse(wp('eval', code));
  const php = value => `json_decode(base64_decode('${Buffer.from(JSON.stringify(value)).toString('base64')}'), true)`;
  const name = `ssi-authoring-${randomUUID()}.php`;
  const observer = `<?php
add_filter('template_include', function($path) {
  add_action('wp_head', function() use ($path) {
    global $_wp_current_template_id;
    echo '<meta name="ssi-authoring-template" content="' . esc_attr($_wp_current_template_id ?: basename($path)) . '">';
  });
  return $path;
}, PHP_INT_MAX);
`;
  wp('eval', `wp_mkdir_p(WPMU_PLUGIN_DIR); if (false === file_put_contents(WPMU_PLUGIN_DIR . '/' . ${php(name)}, ${php(observer)})) { throw new Exception('Cannot install template observer'); }`);
  let page;
  try {
    page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const siblings = json(`$out=[]; foreach(get_posts(['post_type'=>get_post_types(['public'=>true]),'post_status'=>'publish','numberposts'=>-1,'orderby'=>'ID','order'=>'ASC']) as $p) { if ($p->post_type === 'attachment') continue; $out[]=['id'=>$p->ID,'type'=>$p->post_type,'url'=>get_permalink($p),'title'=>get_the_title($p)]; } echo wp_json_encode($out);`);
    const read = async item => {
      const response = await page.goto(item.url, { waitUntil: 'networkidle', timeout: 120000 });
      if (!response?.ok()) throw new Error(`Authoring parity navigation failed: ${item.url}`);
      const template = await page.locator('meta[name="ssi-authoring-template"]').getAttribute('content');
      if (!template) throw new Error(`No served template for ${item.url}`);
      return { template, landmarks: await page.evaluate(authoringLandmarks, item.title) };
    };
    const clusters = new Set();
    const evidence = [];
    for (const sibling of siblings) {
      const imported = await read(sibling);
      const cluster = `${sibling.type} × ${imported.template}`;
      if (clusters.has(cluster)) continue;
      clusters.add(cluster);
      let probe;
      try {
        probe = json(`$s=get_post(${Number(sibling.id)}); $id=wp_insert_post(['post_type'=>$s->post_type,'post_status'=>'publish','post_title'=>'Authoring parity probe','post_content'=>'<!-- wp:paragraph --><p>Probe body.</p><!-- /wp:paragraph -->','comment_status'=>$s->comment_status],true); if(is_wp_error($id)) throw new Exception($id->get_error_message()); echo wp_json_encode(['id'=>$id,'url'=>get_permalink($id),'title'=>get_the_title($id)]);`);
        wp('eval', `$s=get_post(${Number(sibling.id)}); foreach(get_object_taxonomies($s->post_type) as $tax) { $terms=wp_get_object_terms($s->ID,$tax,['fields'=>'ids']); if(is_wp_error($terms)) throw new Exception($terms->get_error_message()); $result=wp_set_object_terms(${Number(probe.id)},$terms,$tax); if(is_wp_error($result)) throw new Exception($result->get_error_message()); }`);
        const authored = await read(probe);
        const landmarks = { imported: imported.landmarks, new_post: authored.landmarks };
        evidence.push({ cluster, sibling: sibling.url, sibling_template: imported.template, probe_template: authored.template, landmarks, ...compareAuthoringLandmarks(landmarks.imported, landmarks.new_post) });
      } finally {
        if (probe) wp('post', 'delete', String(probe.id), '--force');
      }
    }
    // Taxonomy templates are authoring surfaces too. Use a fresh term and a
    // published item so the archive is served through the real hierarchy.
    const terms = json(`$out=[]; foreach(get_taxonomies(['public'=>true],'objects') as $tax) { foreach(get_terms(['taxonomy'=>$tax->name,'hide_empty'=>true]) as $term) { $url=get_term_link($term); if(!is_wp_error($url)) $out[]=['id'=>$term->term_id,'taxonomy'=>$tax->name,'type'=>$tax->object_type[0],'url'=>$url,'title'=>$term->name]; } } echo wp_json_encode($out);`);
    for (const sibling of terms) {
      const imported = await read(sibling);
      const cluster = `taxonomy:${sibling.taxonomy} × ${imported.template}`;
      if (clusters.has(cluster)) continue;
      clusters.add(cluster);
      let termId;
      let probeId;
      try {
        const term = json(`$t=wp_insert_term('Authoring parity probe ' . wp_generate_uuid4(), ${php(sibling.taxonomy)}); if(is_wp_error($t)) throw new Exception($t->get_error_message()); echo wp_json_encode($t);`);
        termId = term.term_id;
        const probe = json(`$id=wp_insert_post(['post_type'=>${php(sibling.type)},'post_status'=>'publish','post_title'=>'Authoring parity probe','post_content'=>'<!-- wp:paragraph --><p>Probe body.</p><!-- /wp:paragraph -->'],true); if(is_wp_error($id)) throw new Exception($id->get_error_message()); echo wp_json_encode(['id'=>$id]);`);
        probeId = probe.id;
        const archive = json(`wp_set_object_terms(${Number(probeId)}, [${Number(termId)}], ${php(sibling.taxonomy)}); $t=get_term(${Number(termId)},${php(sibling.taxonomy)}); echo wp_json_encode(['url'=>get_term_link($t),'title'=>$t->name]);`);
        const authored = await read(archive);
        const landmarks = { imported: imported.landmarks, new_post: authored.landmarks };
        evidence.push({ cluster, sibling: sibling.url, sibling_template: imported.template, probe_template: authored.template, landmarks, ...compareAuthoringLandmarks(landmarks.imported, landmarks.new_post) });
      } finally {
        try { if (probeId) wp('post', 'delete', String(probeId), '--force'); }
        finally { if (termId) wp('eval', `wp_delete_term(${Number(termId)}, ${php(sibling.taxonomy)});`); }
      }
    }
    return evidence;
  } finally {
    try { await page?.close(); } finally { wp('eval', `unlink(WPMU_PLUGIN_DIR . '/' . ${php(name)});`); }
  }
}
