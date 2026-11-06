import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  editabilityPredicate,
  failureSignature,
  materializationFixture,
  normalizePage,
  reduceFinding,
  reportPredicate,
  runCli,
  visualParityPredicate,
} from './reduce-finding.mjs';
import { parseStylesheet, serializeStylesheet, walkStylesheet } from './reduce-finding/css.mjs';
import { ddmin, splitIntoChunks } from './reduce-finding/ddmin.mjs';
import { contentElements, parseDocument, styleElements, textContent } from './reduce-finding/html.mjs';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const VENDORED_SCHEMA = path.join(root, 'tools/reduce-finding/contracts/php-transformer-visual-parity-fixture.schema.json');
const COMPOSER_SCHEMA = path.join(root, 'vendor/automattic/blocks-engine-php-transformer/docs/contracts/php-transformer-visual-parity-fixture.schema.json');

const NESTED_PAGE = `<!DOCTYPE html>
<html lang="en"><head><title>Nested</title><meta name="x" content="y"></head>
<body class="home">
  <header><nav><a href="/">Home</a><a href="/about">About</a></nav></header>
  <main class="page" id="main">
    <p>Intro copy</p>
    <section id="s1"><h2>Other</h2><p>Other copy</p></section>
    <section class="grid" data-x="1">
      <h2>Title</h2>
      <article class="card-wrap">
        <span>meta</span>
        <div class="card" style="padding: 4px">
          <em class="target">needle in the haystack</em>
          <p>card copy</p>
        </div>
        <img src="a.png" alt="">
      </article>
      <article><p>other</p></article>
    </section>
  </main>
  <footer><p>footer</p></footer>
</body></html>`;

/** Content-element ancestor tags of the first `.target`, outermost first. */
function targetChain(html) {
  const document = parseDocument(html);
  const target = contentElements(document).find(({ node }) => node.attrs.some((attr) => attr.name === 'class' && attr.value.split(' ').includes('target')));
  if (!target) {
    return null;
  }
  const chain = [];
  for (let node = target.node.parentNode; node && node.tagName && node.tagName !== 'body'; node = node.parentNode) {
    chain.unshift(node.tagName);
  }
  return chain;
}

function bodyOf(html) {
  return /<body>([\s\S]*)<\/body>/.exec(html)[1];
}

function stylesOf(html) {
  return styleElements(parseDocument(html)).map((style) => textContent(style));
}

/** True when a rule matching `selector` with `property` sits inside the at-rule chain `atRules`. */
function hasNestedDeclaration(html, atRules, selector, property) {
  let found = false;
  for (const css of stylesOf(html)) {
    const visit = (items, chain) => {
      for (const item of items || []) {
        if (item.type === 'rule' && item.selector === selector && JSON.stringify(chain) === JSON.stringify(atRules)
          && item.items.some((child) => child.type === 'declaration' && child.property === property)) {
          found = true;
        }
        if (item.type === 'at-rule') {
          visit(item.items, [...chain, item.name]);
        }
      }
    };
    visit(parseStylesheet(css).items, []);
  }
  return found;
}

test('ddmin finds a 1-minimal subset', async () => {
  const needed = new Set([3, 11, 12]);
  const result = await ddmin([...Array(16).keys()], async (subset) => [...needed].every((item) => subset.includes(item)));
  assert.deepEqual(result, [3, 11, 12]);
  assert.deepEqual(splitIntoChunks([1, 2, 3, 4, 5], 2), [[1, 2], [3, 4, 5]]);
});

test('a finding nested four levels deep reduces to its minimal ancestor chain', async () => {
  const predicate = (html) => {
    const chain = targetChain(html);
    return Boolean(chain) && ['main', 'section', 'article', 'div'].every((tag, index) => chain[index] === tag) && chain.length === 4;
  };
  const result = await reduceFinding(NESTED_PAGE, predicate);
  assert.equal(result.status, 'reduced');
  assert.equal(result.stopped, 'fixpoint');
  assert.equal(bodyOf(result.html), '<main><section><article><div><em class="target"></em></div></article></section></main>');
  assert.deepEqual(result.counts_after, { elements: 5, css_rules: 0, css_declarations: 0, bytes: Buffer.byteLength(result.html) });
  assert.ok(result.counts_before.elements > 20);
});

test('CSS rules nested in @media reduce, and noise at-rule blocks are removed whole', async () => {
  const page = `<html><head><style>
    /* site styles */
    body { margin: 0; font: 16px/1.5 system-ui }
    .grid { display: block; color: red }
    @layer base { .grid { color: blue } p { margin: 0 } }
    @supports (display: grid) { .other { display: grid } }
    @media (max-width: 600px) {
      .noise { color: green }
      .grid { display: grid; gap: 1rem; color: purple }
      @media (prefers-color-scheme: dark) { .grid { color: white } }
    }
  </style></head><body><div class="grid"><p>a</p><p>b</p></div><p>other</p></body></html>`;
  const predicate = (html) => hasNestedDeclaration(html, ['media'], '.grid', 'display') && /<div class="grid">/.test(html);
  const result = await reduceFinding(page, predicate);
  assert.deepEqual(stylesOf(result.html), ['@media (max-width: 600px) { .grid { display: grid; } }']);
  assert.equal(bodyOf(result.html), '<div class="grid"></div>');
  assert.equal(result.counts_after.css_rules, 2);
  assert.equal(result.counts_after.css_declarations, 1);
});

test('rules nested in @layer and @media reduce together', async () => {
  const page = '<style>@layer components { .x { color: red } @media (min-width: 1px) { .y { gap: 1px; color: red } .z { gap: 2px } } } .y { gap: 3px }</style><p class="y">y</p>';
  const predicate = (html) => hasNestedDeclaration(html, ['layer', 'media'], '.y', 'gap');
  const result = await reduceFinding(page, predicate);
  assert.deepEqual(stylesOf(result.html), ['@layer components { @media (min-width: 1px) { .y { gap: 1px; } } }']);
});

test('raw-text elements survive reduction byte for byte', async () => {
  const script = 'if (a < b && c > d) { document.write("</div><p>"); }';
  const page = `<html><head><title>a <b></title><script>${script}</script></head><body><textarea><b>raw</b></textarea><p title="a > b">noise</p></body></html>`;
  const predicate = (html) => stylesOf(html).length === 0 && html.includes(`<script>${script}</script>`) && /<textarea>&lt;b&gt;raw/.test(html);
  const result = await reduceFinding(page, predicate);
  assert.equal(result.html, `<html><head><script>${script}</script></head><body><textarea>&lt;b&gt;raw&lt;/b&gt;</textarea></body></html>`);
  const textarea = contentElements(parseDocument(result.html)).find(({ node }) => node.tagName === 'textarea');
  assert.equal(textContent(textarea.node), '<b>raw</b>');
});

test('reduction is deterministic', async () => {
  const predicate = (html) => (targetChain(html) || []).includes('article');
  const first = await reduceFinding(NESTED_PAGE, predicate);
  const second = await reduceFinding(NESTED_PAGE, predicate);
  assert.deepEqual(first, second);
});

test('the call budget stops the reduction with a reproducing page', async () => {
  let calls = 0;
  const predicate = (html) => {
    calls++;
    return targetChain(html) !== null;
  };
  const result = await reduceFinding(NESTED_PAGE, predicate, { maxCalls: 5 });
  assert.equal(result.stopped, 'budget');
  assert.equal(result.predicate_calls, 5);
  assert.equal(calls, 5);
  assert.ok(targetChain(result.html));

  const none = await reduceFinding(NESTED_PAGE, predicate, { maxCalls: 0 });
  assert.equal(none.status, 'incomplete');
  assert.equal(none.html, NESTED_PAGE);
});

test('the deadline stops the reduction before the next call', async () => {
  let clock = 0;
  const predicate = (html) => {
    clock += 10;
    return targetChain(html) !== null;
  };
  const result = await reduceFinding(NESTED_PAGE, predicate, { timeoutMs: 35, now: () => clock });
  assert.equal(result.stopped, 'deadline');
  assert.equal(result.predicate_calls, 4);
  assert.ok(targetChain(result.html));
});

test('a predicate that does not reproduce on the input is reported', async () => {
  const result = await reduceFinding('<p>x</p>', () => false);
  assert.equal(result.status, 'not_reproducible');
  assert.equal(result.stopped, null);
  assert.equal(result.predicate_calls, 1);
});

test('signatures ignore names, text and values but not CSS features', () => {
  const finding = { stage: 'materialization', check: 'grid_track_mismatch', block_names: ['core/group'] };
  const a = failureSignature('<figure class="a"><img id="x">hello</figure><style>figure > img { display: block; color: red; --Brand: 1px }</style>', finding);
  const b = failureSignature('<figure class="b"><img id="y">world</figure><style>figure > img { color: blue; display: none; --other: 2px }</style>', finding);
  assert.equal(a.sha256, b.sha256);
  assert.match(a.sha256, /^[0-9a-f]{64}$/);
  assert.deepEqual(a.features.css_properties, ['--*', 'color', 'display']);

  const variants = [
    '<figure><img></figure><style>@media (min-width: 1px) { figure > img { display: block; color: red; --x: 1px } }</style>',
    '<figure><img></figure><style>figure img { display: block; color: red; --x: 1px }</style>',
    '<figure><img></figure><style>figure > img:where(.x) { display: block; color: red; --x: 1px }</style>',
    '<figure><img></figure><style>figure > img { display: block; color: var(--x); --x: 1px }</style>',
    '<figure><img></figure><style>figure > img { display: block; color: red; width: 1px }</style>',
    '<figure><picture><img></picture></figure><style>figure > img { display: block; color: red; --x: 1px }</style>',
  ];
  for (const variant of variants) {
    assert.notEqual(failureSignature(variant, finding).sha256, a.sha256, variant);
  }
  assert.notEqual(failureSignature('<p>x</p>', { stage: 'editability', check: 'raw_html_block_count' }).sha256, failureSignature('<p>x</p>', finding).sha256);
});

test('an editability predicate reduces a raw HTML block finding', async () => {
  // Stand-in for a transformer run: every <iframe> becomes a core/html block.
  const evaluate = (html) => ({
    schema: 'blocks-engine/php-transformer/editability-report/v2',
    metrics: { raw_html_block_count: (html.match(/<iframe/g) || []).length, max_nesting_depth: 1 },
  });
  const page = '<main><h1>Title</h1><section><p>copy</p><div class="embed"><iframe src="https://example.test/v"></iframe></div></section></main>';
  const result = await reduceFinding(page, editabilityPredicate({ evaluate }));
  assert.equal(bodyOf(result.html), '<iframe></iframe>');

  const nesting = reportPredicate({ evaluate, check: { path: 'metrics.max_nesting_depth', op: '>', value: 0 } });
  assert.equal(await nesting(page), true);
  const wrongSchema = editabilityPredicate({ evaluate: () => ({ metrics: { raw_html_block_count: 3 } }) });
  assert.equal(await wrongSchema(page), false);
});

test('a visual parity predicate matches report findings by reason code and severity', async () => {
  const report = (severity) => ({
    schema: 'blocks-engine/php-transformer/visual-parity-report/v1',
    findings: [{ id: 'f1', severity, category: 'layout', summary: 'grid', reason_code: 'grid_track_mismatch' }],
  });
  const predicate = (severity) => visualParityPredicate({ evaluate: () => report(severity), reasonCode: 'grid_track_mismatch', minSeverity: 'error' });
  assert.equal(await predicate('error')(''), true);
  assert.equal(await predicate('warning')(''), false);
});

test('materialization fixtures are valid against the Blocks Engine schema', () => {
  const schemas = [VENDORED_SCHEMA, ...(fs.existsSync(COMPOSER_SCHEMA) ? [COMPOSER_SCHEMA] : [])];
  const signature = failureSignature('<p>x</p>', { check: 'grid_track_mismatch' });
  const fixtures = [materializationFixture(), materializationFixture({ name: 'grid', signature, targetProvided: true })];
  for (const schemaFile of schemas) {
    const schema = JSON.parse(fs.readFileSync(schemaFile, 'utf8'));
    for (const fixture of fixtures) {
      assert.deepEqual(validateSchema(schema, fixture), [], `${schemaFile}: ${fixture.name}`);
    }
  }
  assert.deepEqual(fixtures[0].viewports.map((viewport) => viewport.width), [390, 1440]);
  assert.equal(fixtures[0].source.html_path, 'source.html');
  assert.equal(fixtures[0].target.html_path, 'target.html');
  assert.equal(fixtures[0].target.environment.placeholder, true);
  assert.equal(fixtures[0].metadata.target_status, 'placeholder');
  assert.equal(fixtures[1].target.environment, undefined);
  assert.equal(fixtures[1].metadata.signature.sha256, signature.sha256);

  // The validator itself rejects the old same-file, desktop-only shape's mistakes.
  const schema = JSON.parse(fs.readFileSync(VENDORED_SCHEMA, 'utf8'));
  assert.notDeepEqual(validateSchema(schema, { ...fixtures[0], viewports: [{ id: 'desktop', width: 1440 }] }), []);
  assert.notDeepEqual(validateSchema(schema, { ...fixtures[0], extra: true }), []);
});

test('the CLI writes the reduced source, fixture and reduction summary', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'reduce-finding-cli-'));
  try {
    const input = path.join(directory, 'page.html');
    fs.writeFileSync(input, '<style>.a { color: red } .grid { display: grid; gap: 1px }</style><div class="grid"><p>x</p></div><aside>noise</aside>');
    const output = path.join(directory, 'out');
    const summary = await runCli({
      input,
      output,
      predicate: "grep -q 'display: grid' {candidate} && grep -q 'class=\"grid\"' {candidate}",
      'target-command': "sed 's/div class=\"grid\"/div class=\"wp-block-group grid\"/' {source}",
      'finding-check': 'grid_track_mismatch',
    });
    assert.equal(summary.status, 'reduced');
    const source = fs.readFileSync(path.join(output, 'source.html'), 'utf8');
    assert.equal(source, '<html><head><style>.grid { display: grid; }</style></head><body><div class="grid"></div></body></html>');
    assert.match(fs.readFileSync(path.join(output, 'target.html'), 'utf8'), /wp-block-group grid/);
    const fixture = JSON.parse(fs.readFileSync(path.join(output, 'fixture.json'), 'utf8'));
    assert.equal(fixture.metadata.target_status, 'provided');
    const schema = JSON.parse(fs.readFileSync(VENDORED_SCHEMA, 'utf8'));
    assert.deepEqual(validateSchema(schema, fixture), []);
    const reduction = JSON.parse(fs.readFileSync(path.join(output, 'reduction.json'), 'utf8'));
    assert.equal(reduction.signature.check, 'grid_track_mismatch');
    assert.equal(reduction.html, undefined);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('the CLI reduces against a report command and check expression', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'reduce-finding-cli-'));
  try {
    const input = path.join(directory, 'page.html');
    fs.writeFileSync(input, '<main><p>copy</p><div><iframe src="v"></iframe></div></main>');
    const reporter = path.join(directory, 'report.mjs');
    fs.writeFileSync(reporter, [
      "import fs from 'node:fs';",
      "const html = fs.readFileSync(process.argv[2], 'utf8');",
      "const count = (html.match(/<iframe/g) || []).length;",
      "console.log(JSON.stringify({ schema: 'blocks-engine/php-transformer/editability-report/v2', metrics: { raw_html_block_count: count } }));",
    ].join('\n'));
    const output = path.join(directory, 'out');
    const summary = await runCli({
      input,
      output,
      'report-command': `node ${reporter} {candidate}`,
      check: 'metrics.raw_html_block_count > 0',
      stage: 'editability',
      'finding-check': 'raw_html_block_count',
    });
    assert.equal(summary.signature.stage, 'editability');
    assert.equal(bodyOf(fs.readFileSync(path.join(output, 'source.html'), 'utf8')), '<iframe></iframe>');
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('normalization is idempotent', () => {
  const once = normalizePage(NESTED_PAGE.replace('</head>', '<style>@media print { .a{color:red} }</style></head>'));
  assert.equal(normalizePage(once), once);
  const css = stylesOf(once)[0];
  assert.equal(serializeStylesheet(parseStylesheet(css)), css);
  let rules = 0;
  walkStylesheet(parseStylesheet(css), () => rules++);
  assert.equal(rules, 3);
});

/**
 * Minimal JSON Schema validator for the keywords the fixture contract uses.
 * Unknown keywords fail loudly so a schema change cannot pass silently.
 */
function validateSchema(rootSchema, value) {
  const errors = [];
  const annotations = new Set(['$schema', '$id', 'title', 'description', '$defs', '$comment']);
  const typeOf = (item) => {
    if (Array.isArray(item)) return 'array';
    if (item === null) return 'null';
    if (Number.isInteger(item)) return 'integer';
    return typeof item;
  };
  const check = (schema, item, at) => {
    if (schema.$ref) {
      const target = schema.$ref.replace(/^#\//, '').split('/').reduce((node, key) => node[key], rootSchema);
      check(target, item, at);
      return;
    }
    for (const [keyword, expected] of Object.entries(schema)) {
      switch (keyword) {
        case 'type': {
          const actual = typeOf(item);
          if (!(actual === expected || (expected === 'number' && actual === 'integer'))) errors.push(`${at}: expected ${expected}, got ${actual}`);
          break;
        }
        case 'const':
          if (item !== expected) errors.push(`${at}: expected ${JSON.stringify(expected)}`);
          break;
        case 'enum':
          if (!expected.includes(item)) errors.push(`${at}: ${JSON.stringify(item)} not in enum`);
          break;
        case 'required':
          for (const key of expected) if (typeOf(item) === 'object' && !(key in item)) errors.push(`${at}: missing ${key}`);
          break;
        case 'properties':
          if (typeOf(item) === 'object') for (const [key, child] of Object.entries(expected)) if (key in item) check(child, item[key], `${at}.${key}`);
          break;
        case 'additionalProperties':
          if (typeOf(item) === 'object') {
            for (const key of Object.keys(item)) {
              if (schema.properties && key in schema.properties) continue;
              if (expected === false) errors.push(`${at}: unexpected property ${key}`);
              else if (typeof expected === 'object') check(expected, item[key], `${at}.${key}`);
            }
          }
          break;
        case 'items':
          if (Array.isArray(item)) item.forEach((child, index) => check(expected, child, `${at}[${index}]`));
          break;
        case 'minItems':
          if (Array.isArray(item) && item.length < expected) errors.push(`${at}: fewer than ${expected} items`);
          break;
        case 'minLength':
          if (typeof item === 'string' && item.length < expected) errors.push(`${at}: shorter than ${expected}`);
          break;
        case 'minimum':
          if (typeof item === 'number' && item < expected) errors.push(`${at}: below ${expected}`);
          break;
        case 'maximum':
          if (typeof item === 'number' && item > expected) errors.push(`${at}: above ${expected}`);
          break;
        default:
          if (!annotations.has(keyword)) throw new Error(`unsupported schema keyword ${keyword}`);
      }
    }
  };
  check(rootSchema, value, '$');
  return errors;
}
