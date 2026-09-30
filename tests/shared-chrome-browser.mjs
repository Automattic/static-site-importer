import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, writeFile } from 'node:fs/promises'
import { join, basename } from 'node:path'
import { chromium } from 'playwright'
import pixelmatch from 'pixelmatch'
import { PNG } from 'pngjs'

const evidence = process.env.SSI_SHARED_CHROME_EVIDENCE
const target = process.env.SSI_SHARED_CHROME_URL
assert(evidence && target, 'Run through the disposable shared-chrome acceptance runner')
const inventory = JSON.parse(await readFile(join(evidence, 'runtime-inventory.json'), 'utf8'))
const server = createServer(async (request, response) => {
  const name = basename(new URL(request.url, 'http://source').pathname) || 'index.html'
  if (!['index.html', 'about.html', 'site.css'].includes(name)) return response.writeHead(404).end()
  response.setHeader('Content-Type', name.endsWith('.css') ? 'text/css' : 'text/html')
  response.end(await readFile(join(evidence, 'source', name)))
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const source = `http://127.0.0.1:${server.address().port}`
const browser = await chromium.launch({ headless: true })
const results = { viewports: [], editor: {}, inventory }
const measure = page => page.evaluate(() => {
  const box = selector => {
    const node = document.querySelector(selector)
    if (!node) return null
    const { x, y, width, height } = node.getBoundingClientRect()
    const style = getComputedStyle(node)
    return { x, y, width, height, display: style.display, font: style.font, color: style.color }
  }
  return {
    frame: box('.frame'), header: box('.masthead'), main: box('.content'), footer: box('.colophon'),
    documentHeight: document.documentElement.scrollHeight,
    headerInsideFrame: document.querySelector('.masthead')?.parentElement === document.querySelector('.frame'),
    footerInsideFrame: document.querySelector('.colophon')?.parentElement === document.querySelector('.frame'),
    links: [...document.querySelectorAll('.masthead a')].map(a => a.textContent.trim()),
  }
})
try {
  const context = await browser.newContext()
  const original = await context.newPage()
  const imported = await context.newPage()
  for (const width of [390, 768, 1440]) {
    await original.setViewportSize({ width, height: 900 })
    await imported.setViewportSize({ width, height: 900 })
    await original.goto(source, { waitUntil: 'load' })
    await imported.goto(target, { waitUntil: 'load' })
    await original.evaluate(() => document.fonts.ready)
    await imported.evaluate(() => document.fonts.ready)
    const sourceGeometry = await measure(original)
    const importedGeometry = await measure(imported)
    const sourcePng = PNG.sync.read(await original.screenshot({ path: join(evidence, `source-${width}.png`) }))
    const importedPng = PNG.sync.read(await imported.screenshot({ path: join(evidence, `imported-${width}.png`) }))
    const difference = new PNG({ width, height: 900 })
    const pixels = pixelmatch(sourcePng.data, importedPng.data, difference.data, width, 900, { threshold: 0, includeAA: true })
    await writeFile(join(evidence, `difference-${width}.png`), PNG.sync.write(difference))
    const row = { width, pixels, sourceGeometry, importedGeometry, disclosure: null }
    results.viewports.push(row)
    await writeFile(join(evidence, `imported-${width}.html`), await imported.content())
    if (width === 390) {
      const details = imported.locator('details.drawer')
      assert.equal(await details.count(), 1, 'The actual native disclosure remains in the imported header')
      await details.locator('summary').click()
      row.disclosure = await details.evaluate(node => ({ open: node.open, links: node.querySelectorAll('a').length, visible: [...node.querySelectorAll('a')].every(a => a.getBoundingClientRect().height > 0) }))
      assert(row.disclosure.open && row.disclosure.links >= 2 && row.disclosure.visible, 'Opening the actual summary reveals its own descendant menu')
      await details.locator('summary').click()
      assert.equal(await details.evaluate(node => node.open), false, 'The same control closes the disclosure')
    }
  }

  const admin = await browser.newContext()
  const editor = await admin.newPage()
  await editor.goto(`${target}/wp-login.php`)
  await editor.locator('#user_login').fill('admin')
  await editor.locator('#user_pass').fill('password')
  await Promise.all([editor.waitForURL(/wp-admin/), editor.locator('#wp-submit').click()])
  await editor.goto(`${target}/wp-admin/post.php?post=${inventory.entry}&action=edit`)
  await editor.waitForFunction(() => window.wp?.blocks?.parse && window.wp?.data?.resolveSelect('core'))
  results.editor = await editor.evaluate(async ({ primary, theme, entry }) => {
    const core = wp.data.resolveSelect('core')
    const dispatch = wp.data.dispatch('core')
    const page = await core.getEntityRecord('postType', 'page', entry)
    const pageBlocks = wp.blocks.parse(page.content.raw)
    let validatedBlocks = 0
    const validate = blocks => {
      for (const block of blocks) {
        if (!wp.blocks.getBlockType(block.name) || !wp.blocks.validateBlock(block)[0]) throw new Error(`Editor cannot validate ${block.name}`)
        validatedBlocks++
        validate(block.innerBlocks || [])
      }
    }
    validate(pageBlocks)
    const menu = await core.getEntityRecord('postType', 'wp_navigation', primary)
    const blocks = wp.blocks.parse(menu.content.raw)
    const link = blocks.find(block => block.name === 'core/navigation-link' && block.attributes.label === 'Home')
    if (!link) throw new Error('Primary menu must expose native editable link blocks')
    link.attributes.label = 'Start & More'
    dispatch.editEntityRecord('postType', 'wp_navigation', primary, { content: wp.blocks.serialize(blocks) })
    const saved = await dispatch.saveEditedEntityRecord('postType', 'wp_navigation', primary)
    if (!saved || !saved.content.raw.includes('Start')) throw new Error('Editor data-store navigation save failed')
    const id = `${theme}//footer`
    const footer = await core.getEntityRecord('postType', 'wp_template_part', id)
    dispatch.editEntityRecord('postType', 'wp_template_part', id, { content: footer.content.raw.replace('Shared footer', 'Shared footer edited') })
    const savedFooter = await dispatch.saveEditedEntityRecord('postType', 'wp_template_part', id)
    if (!savedFooter || !savedFooter.content.raw.includes('Shared footer edited')) throw new Error('Editor data-store footer save failed')
    return { menuSaved: true, footerSaved: true, menuId: primary, footerId: id, validatedBlocks }
  }, inventory)
  await editor.reload()
  await editor.waitForFunction(() => window.wp?.data?.resolveSelect('core'))
  results.editor.reloaded = await editor.evaluate(async ({ primary, theme }) => {
    const core = wp.data.resolveSelect('core')
    const menu = await core.getEntityRecord('postType', 'wp_navigation', primary)
    const footer = await core.getEntityRecord('postType', 'wp_template_part', `${theme}//footer`)
    return menu.content.raw.includes('Start') && footer.content.raw.includes('Shared footer edited')
  }, inventory)
  assert(results.editor.reloaded, 'Both edits survive a fresh editor reload')
  for (const url of Object.values(inventory.urls)) {
    await imported.goto(url)
    assert((await imported.locator('.masthead').innerText()).includes('Start'), 'One menu edit propagates to every shared route')
    assert((await imported.locator('.colophon').innerText()).includes('Shared footer edited'), 'One footer edit propagates to every shared route')
  }
  results.pass = results.viewports.every(row => row.pixels === 0 && row.importedGeometry.headerInsideFrame && row.importedGeometry.footerInsideFrame)
  assert(results.pass, 'Shared-chrome acceptance requires exact pixels and preserved rendered containment at all three widths')
} finally {
  await writeFile(join(evidence, 'browser.json'), JSON.stringify(results, null, 2))
  await browser.close()
  await new Promise(resolve => server.close(resolve))
}
console.log(JSON.stringify({ pass: results.pass, viewports: results.viewports.map(({ width, pixels }) => ({ width, pixels })), editor: results.editor }))
