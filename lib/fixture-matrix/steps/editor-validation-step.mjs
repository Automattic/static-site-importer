// Editor-validation recipe step for the Static Site Importer fixture matrix.
//
// Invokes the real `wp.blocks.validateBlock` editor-validation command
// (`wordpress.editor-validate-blocks`, wp-codebox #1597) against each imported
// fixture's content. This replaces the former `wordpress.editor-canvas-probe`
// step, which opened an EMPTY `post-new.php` and therefore never validated
// imported markup — it only reported whether the blank editor rendered any
// invalid-block DOM warnings (always none).
//
// The command accepts `content`/`content-file` to validate inline/file markup,
// or `target`/`post-id`/`url` to open a post. The matrix prefers the most
// concrete imported-content target a fixture carries, in priority order:
//   1. inline `content` (the imported post_content), if present;
//   2. a `content-file` path;
//   3. the imported `post-id`;
//   4. an explicit editor `url` (e.g. `post.php?post=<id>&action=edit`);
//   5. an explicit `target`;
//   6. otherwise `target=front-page` (the default).
//
// IMPORTED-CONTENT TARGETING: the imported front page's post id is not known when
// the recipe is built (the import runs later, inside the sandbox), so the default
// resolves at runtime. SSI's `validate-artifact` import materializes the
// fixture's pages and sets `page_on_front` to the imported home page; wp-codebox's
// `editorOpenTargetFromArgs` + `resolveEditorOpenTarget` turn `target=front-page`
// into `post.php?post=<page_on_front>&action=edit` by querying the running
// WordPress, so the command validates the REAL imported front page's blocks.
// (Previously the default emitted a bare `post-type`, which wp-codebox resolved to
// an EMPTY `post-new.php?post_type=<type>` editor — `total_blocks: 0`, proving
// nothing about imported markup. This is the wiring that closed that gap.)
//
// `wait-selector`/`wait-timeout` are forwarded when provided. The per-block
// `{ name, isValid, issues }` results are read back out by
// `collectEditorValidationDiagnostics` / `collectEditorValidation`.

import {
  EDITOR_VALIDATE_BLOCKS_COMMAND,
  DEFAULT_EDITOR_VALIDATION_TARGET,
} from '../shared/constants.mjs';

function present(value) {
  return value !== undefined && value !== null && String(value).trim() !== '';
}

export function editorBlockValidationStep(input = {}) {
  const fixture = input.fixture || {};

  const content = firstPresent([input.content, fixture.editor_content, fixture.editorContent, fixture.post_content, fixture.postContent, fixture.content]);
  const contentFile = firstPresent([input.contentFile, input.content_file, fixture.editor_content_file, fixture.editorContentFile, fixture.content_file]);
  const postId = firstPresent([input.postId, input.post_id, fixture.editor_post_id, fixture.editorPostId, fixture.post_id, fixture.postId]);
  const url = firstPresent([input.url, input.editorValidationUrl, input.editor_validation_url, fixture.editor_url, fixture.editorUrl]);
  const target = firstPresent([input.target, fixture.editor_target, fixture.editorTarget, fixture.target]);

  const args = [];
  if (content !== undefined) {
    args.push(`content=${content}`);
  } else if (contentFile !== undefined) {
    args.push(`content-file=${contentFile}`);
  } else if (postId !== undefined) {
    args.push(`post-id=${postId}`);
  } else if (url !== undefined) {
    args.push(`url=${url}`);
  } else if (target !== undefined) {
    args.push(`target=${target}`);
  } else {
    // Default to the site's static front page. SSI's import (`validate-artifact`)
    // materializes the fixture's pages and points `page_on_front` at the imported
    // home page, so `target=front-page` lets wp-codebox resolve that exact post id
    // at runtime and open `post.php?post=<page_on_front>&action=edit`. That
    // validates the REAL imported front page's blocks — not the empty
    // `post-new.php?post_type=<type>` editor a bare `post-type` arg opens
    // (total_blocks: 0, proving nothing about imported markup).
    args.push(`target=${DEFAULT_EDITOR_VALIDATION_TARGET}`);
  }

  const waitSelector = firstPresent([input.waitSelector, input.wait_selector, fixture.editor_wait_selector, fixture.editorWaitSelector]);
  if (waitSelector !== undefined) {
    args.push(`wait-selector=${waitSelector}`);
  }
  const waitTimeout = firstPresent([input.waitTimeout, input.wait_timeout, fixture.editor_wait_timeout, fixture.editorWaitTimeout]);
  if (waitTimeout !== undefined) {
    args.push(`wait-timeout=${waitTimeout}`);
  }

  return {
    command: EDITOR_VALIDATE_BLOCKS_COMMAND,
    args,
  };
}

function firstPresent(values) {
  for (const value of values) {
    if (present(value)) {
      return value;
    }
  }
  return undefined;
}
