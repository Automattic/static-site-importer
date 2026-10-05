// Editor-chrome runtime editor-URL resolver for the Static Site Importer fixture matrix.
//
// The Codebox `wordpress.editor-canvas-probe` contract only accepts a literal
// `url=<path-or-url>` argument: unlike its sibling editor commands it has no
// post-id/post-slug/front-page target interpreter and installs no WordPress
// admin auth itself. The numeric post ID of an imported surface is only known
// after the import step runs, so the recipe cannot carry a literal admin edit
// URL at build time. This step stages a fixture-matrix-owned, sandbox-disposable
// resolver at the WordPress document root; each chrome probe targets it with the
// surface identity the matrix already tracks, and it redirects the probe browser
// to the real imported post's admin edit URL using the same WordPress resolution
// primitives as the materialized-surface-identity receipt (`page_on_front` for
// the front page, `get_page_by_path` for secondary routes) plus core
// `wp_set_auth_cookie` and `get_edit_post_link`.
/**
 * Internal dependencies
 */
import { shellToken } from '../shared/utils.mjs';
import { fixtureStepMetadata } from './shared.mjs';

// Codebox playground distributions run WordPress at the /wordpress document
// root; uploads paths are routed through WordPress instead of executed, while
// document-root PHP entry points run directly. The resolver is rewritten
// idempotently once per fixture so single-fixture recovery runs stay complete.
export const EDITOR_CHROME_TARGET_RUNTIME_PATH = '/wordpress/editor-chrome-target.php';

// The probe URL is site-root-relative: the runtime path lives at the WordPress
// document root, so its web path drops the /wordpress guest filesystem prefix.
export const EDITOR_CHROME_TARGET_URL_PATH = EDITOR_CHROME_TARGET_RUNTIME_PATH.replace(/^\/wordpress/, '');

const EDITOR_CHROME_TARGET_PHP = `<?php
$document_root = isset($_SERVER['DOCUMENT_ROOT']) && is_file($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php') ? $_SERVER['DOCUMENT_ROOT'] : __DIR__;
require_once $document_root . '/wp-load.php';
$surface = isset($_GET['surface']) ? (string) $_GET['surface'] : '';
$post_type = isset($_GET['post_type']) && preg_match('/^[a-zA-Z0-9_-]+$/', (string) $_GET['post_type']) ? (string) $_GET['post_type'] : 'page';
if ('front-page' === $surface) {
\t$post_id = 'page' === get_option('show_on_front') ? (int) get_option('page_on_front') : 0;
\t$post = $post_id > 0 ? get_post($post_id) : null;
} elseif ('' !== $surface) {
\t$post = get_page_by_path($surface, OBJECT, $post_type);
} else {
\t$post = null;
}
if (!$post instanceof WP_Post || (int) $post->ID <= 0) {
\tstatus_header(404);
\texit('editor_chrome_target_unavailable');
}
$admin = get_user_by('id', 1);
if (!$admin) {
\tstatus_header(500);
\texit('editor_chrome_admin_missing');
}
wp_set_current_user((int) $admin->ID);
wp_set_auth_cookie((int) $admin->ID, true);
$editor_url = get_edit_post_link((int) $post->ID, 'raw');
if (!$editor_url) {
\tstatus_header(500);
\texit('editor_chrome_edit_link_unavailable');
}
wp_safe_redirect($editor_url, 302);
exit;
`;

// Canonical WordPress admin edit URL form, matching the URL the Codebox editor
// commands build from a post-id target. Only used when a numeric post identity
// is actually known; never fabricates one.
export function adminPostEditUrl(postId) {
  return `/wp-admin/post.php?post=${postId}&action=edit`;
}

// Editor URL for a chrome probe whose post ID is only known after import. The
// resolver path is stable and resolves the surface identity at runtime, so it
// never substitutes a front-end source URL or a hardcoded route.
export function editorChromeTargetUrl({ postSlug, surfaceId, postType } = {}) {
  const params = new URLSearchParams();
  params.set('surface', postSlug || surfaceId || 'front-page');
  params.set('post_type', postType || 'page');
  return `${EDITOR_CHROME_TARGET_URL_PATH}?${params.toString()}`;
}

export function editorChromeTargetStep(input = {}) {
  const fixture = input.fixture || {};
  const encoded = Buffer.from(EDITOR_CHROME_TARGET_PHP, 'utf8').toString('base64');
  return {
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`if (${Buffer.byteLength(EDITOR_CHROME_TARGET_PHP, 'utf8')} !== file_put_contents('${EDITOR_CHROME_TARGET_RUNTIME_PATH}', base64_decode('${encoded}', true))) { WP_CLI::error('Failed to stage editor chrome target resolver.'); }`)}`],
    metadata: fixtureStepMetadata(fixture, 'editor-chrome-target', {
      editor_chrome_target_path: EDITOR_CHROME_TARGET_RUNTIME_PATH,
      editor_chrome_target_resolution: 'page_on_front|get_page_by_path+get_edit_post_link',
    }),
  };
}
