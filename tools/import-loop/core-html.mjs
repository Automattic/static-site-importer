// Core HTML diagnostics use source structure, never generated IDs or hashes.
export function coreHtmlFindings(importReport, routes) {
  return (importReport.diagnostics || []).filter((d) => d.type === 'core_html_block').flatMap((d) => {
    const slug = /^posts\/page-(.+)\.post_content$/.exec(d.source_path || '')?.[1];
    const mapped = slug === 'home' ? '/' : slug ? `/${slug}/` : null;
    const evidence = { selector: d.selector, source_path: d.source_path, html_excerpt: d.html_excerpt };
    return (routes.includes(mapped) ? [mapped] : routes).map((route) => ({
      stage: 'editability', check: 'core_html_block_count', owner_repo: 'static-site-importer',
      subject: `${d.tag_name || 'unknown'}:${d.reason_code || 'core_html_block'}`, evidence, route,
    }));
  });
}
