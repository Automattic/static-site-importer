// Operator-only: node tests/acceptance/ico-browser.mjs
// Optional: SSI_ICO_BROWSER_EVIDENCE, CHROMIUM_PATH, and NODE_PATH for external dependencies.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { resolve, join } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const { PNG } = require('pngjs');
const root = resolve(process.env.SSI_ICO_BROWSER_EVIDENCE || 'artifacts/ico-browser');
await mkdir(root, { recursive: true });
const evidence = await mkdtemp(join(root, 'run-'));
console.log(`ICO browser evidence: ${evidence}`);

const red = [255, 0, 0, 255];
const green = [0, 255, 0, 255];
const blue = [0, 0, 255, 255];
const yellow = [255, 255, 0, 255];
const transparent = [0, 0, 0, 0];

function ico(frames) {
  const directory = Buffer.alloc(6 + 16 * frames.length);
  directory.writeUInt16LE(1, 2);
  directory.writeUInt16LE(frames.length, 4);
  let offset = directory.length;
  frames.forEach(({ width, height, bits, colors = 0, bytes }, index) => {
    const entry = 6 + 16 * index;
    directory[entry] = width === 256 ? 0 : width;
    directory[entry + 1] = height === 256 ? 0 : height;
    directory[entry + 2] = colors === 256 ? 0 : colors;
    directory.writeUInt16LE(1, entry + 4);
    directory.writeUInt16LE(bits, entry + 6);
    directory.writeUInt32LE(bytes.length, entry + 8);
    directory.writeUInt32LE(offset, entry + 12);
    offset += bytes.length;
  });
  return Buffer.concat([directory, ...frames.map(frame => frame.bytes)]);
}

function pngFrame(size, color) {
  const png = new PNG({ width: size, height: size });
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const rgba = x === 0 && y === 0 ? transparent : y === size - 1 ? yellow : color;
      png.data.set(rgba, (y * size + x) * 4);
    }
  }
  return { width: size, height: size, bits: 32, bytes: PNG.sync.write(png) };
}

function dibFrame(bits) {
  // Odd dimensions exercise DWORD row padding, packed indices and bottom-up rows.
  const width = 17;
  const height = 13;
  const colors = 2 ** bits;
  const palette = bits === 1 ? [red, blue] : [red, green, blue, yellow];
  const xorStride = Math.ceil(width * bits / 32) * 4;
  const maskStride = Math.ceil(width / 32) * 4;
  const bytes = Buffer.alloc(40 + colors * 4 + (xorStride + maskStride) * height);
  bytes.writeUInt32LE(40, 0);
  bytes.writeInt32LE(width, 4);
  bytes.writeInt32LE(height * 2, 8);
  bytes.writeUInt16LE(1, 12);
  bytes.writeUInt16LE(bits, 14);
  bytes.writeUInt32LE((xorStride + maskStride) * height, 20);
  bytes.writeUInt32LE(colors, 32);
  palette.forEach(([r, g, b], index) => bytes.set([b, g, r, 0], 40 + index * 4));
  const xorOffset = 40 + colors * 4;
  const maskOffset = xorOffset + xorStride * height;
  for (let y = 0; y < height; y++) {
    const row = height - 1 - y;
    for (let x = 0; x < width; x++) {
      const index = bits === 1 ? Number(y >= 6) : Number(x >= 8) + 2 * Number(y >= 6);
      const bit = x * bits;
      bytes[xorOffset + row * xorStride + Math.floor(bit / 8)] |= index << (8 - bits - bit % 8);
      if ((x === 0 && y === 0) || (x === width - 1 && y === height - 1)) {
        bytes[maskOffset + row * maskStride + Math.floor(x / 8)] |= 0x80 >> (x % 8);
      }
    }
  }
  return { width, height, bits, colors, bytes };
}

const frames = [pngFrame(16, red), pngFrame(32, green), pngFrame(256, blue)];
const cases = [
  { name: 'png-multiframe', frames, width: 256, height: 256, color: blue },
  ...frames.map((frame, index) => ({ name: `png-${frame.width}`, frames: [frame], width: frame.width, height: frame.height, color: [red, green, blue][index] })),
  ...[1, 4, 8].map(bits => ({ name: `dib-palette-${bits}`, frames: [dibFrame(bits)], width: 17, height: 13, bits })),
];
const fixtures = new Map();
for (const fixture of cases) {
  const bytes = ico(fixture.frames);
  fixtures.set(`/${fixture.name}.ico`, bytes);
  await writeFile(join(evidence, `${fixture.name}.ico`), bytes);
}
const server = createServer((request, response) => {
  const bytes = fixtures.get(request.url);
  if (bytes) {
    response.writeHead(200, { 'Content-Type': 'image/x-icon', 'Cache-Control': 'no-store' });
    response.end(bytes);
  } else if (request.url === '/') {
    response.writeHead(200, { 'Content-Type': 'text/html' });
    response.end('<!doctype html><title>Independent ICO decode</title><body></body>');
  } else {
    response.writeHead(404).end();
  }
});
const results = { status: 'failed', browser: null, cases: [], evidence };
let browser;
try {
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
  results.browser = browser.version();
  const page = await browser.newPage();
  page.setDefaultTimeout(15000);
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  for (const fixture of cases) {
    // Only Chromium decodes the ICO. Node never decodes either the ICO or its PNG output.
    const decoded = await page.evaluate(async name => {
      const image = new Image();
      image.src = `/${name}.ico`;
      await image.decode();
      const canvas = document.createElement('canvas');
      canvas.width = image.naturalWidth;
      canvas.height = image.naturalHeight;
      const context = canvas.getContext('2d', { willReadFrequently: true });
      context.drawImage(image, 0, 0);
      document.body.append(canvas);
      return {
        width: canvas.width, height: canvas.height,
        rgba: Array.from(context.getImageData(0, 0, canvas.width, canvas.height).data),
        png: canvas.toDataURL('image/png').split(',')[1],
      };
    }, fixture.name);
    await writeFile(join(evidence, `${fixture.name}-decoded.png`), Buffer.from(decoded.png, 'base64'));
    const row = { name: fixture.name, width: decoded.width, height: decoded.height, status: 'failed', samples: {} };
    results.cases.push(row);
    assert.equal(decoded.width, fixture.width, `${fixture.name}: intrinsic width / selected frame`);
    assert.equal(decoded.height, fixture.height, `${fixture.name}: intrinsic height / selected frame`);
    const pixel = (x, y) => decoded.rgba.slice((y * decoded.width + x) * 4, (y * decoded.width + x) * 4 + 4);
    row.samples = { transparent: pixel(0, 0), topLeft: pixel(1, 1), topRight: pixel(fixture.width - 2, 1), bottomLeft: pixel(1, fixture.height - 2), bottomRight: pixel(fixture.width - 2, fixture.height - 2) };
    assert.deepEqual(row.samples.transparent, transparent, `${fixture.name}: transparent PNG alpha / DIB AND mask`);
    if (fixture.bits) {
      assert.deepEqual(row.samples.topLeft, red, `${fixture.name}: red palette entry (BGR order)`);
      assert.deepEqual(row.samples.topRight, fixture.bits === 1 ? red : green, `${fixture.name}: packed palette indices`);
      assert.deepEqual(row.samples.bottomLeft, blue, `${fixture.name}: bottom-up rows`);
      assert.deepEqual(row.samples.bottomRight, fixture.bits === 1 ? blue : yellow, `${fixture.name}: palette quadrant`);
      assert.deepEqual(pixel(16, 12), transparent, `${fixture.name}: padded AND mask final pixel`);
    } else {
      assert.deepEqual(row.samples.topLeft, fixture.color, `${fixture.name}: known frame color`);
      assert.deepEqual(pixel(1, fixture.height - 1), yellow, `${fixture.name}: final PNG row`);
    }
    for (let y = 0; y < fixture.height; y++) {
      for (let x = 0; x < fixture.width; x++) {
        let expected;
        if (fixture.bits) {
          expected = (x === 0 && y === 0) || (x === 16 && y === 12) ? transparent
            : fixture.bits === 1 ? (y < 6 ? red : blue)
              : [red, green, blue, yellow][Number(x >= 8) + 2 * Number(y >= 6)];
        } else {
          expected = x === 0 && y === 0 ? transparent : y === fixture.height - 1 ? yellow : fixture.color;
        }
        assert.deepEqual(pixel(x, y), expected, `${fixture.name}: RGBA at ${x},${y}`);
      }
    }
    row.status = 'passed';
    console.log(`PASS ${fixture.name}: ${fixture.width}x${fixture.height}, all RGBA pixels`);
  }
  await page.screenshot({ path: join(evidence, 'chromium.png'), fullPage: true });
  results.status = 'passed';
} catch (error) {
  results.error = error.stack || String(error);
  throw error;
} finally {
  await writeFile(join(evidence, 'results.json'), `${JSON.stringify(results, null, 2)}\n`);
  try { await browser?.close(); } finally {
    if (server.listening) await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
  }
}
