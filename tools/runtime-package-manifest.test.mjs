import assert from "node:assert/strict"
import { execFileSync } from "node:child_process"
import { readdir, readFile, stat } from "node:fs/promises"
import { dirname, join, relative } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const manifestPath = join(root, "runtime-package-manifest.json")

test("website artifact import profile is complete and capability scoped", async () => {
  const manifest = JSON.parse(await readFile(manifestPath, "utf8"))
  assert.equal(manifest.schema, "static-site-importer/runtime-package-manifest/v1")
  assert.equal(manifest.package, "static-site-importer")
  assert.equal(manifest.package_root, "static-site-importer")

  const profile = manifest.profiles?.["website-artifact-import"]
  assert.ok(profile)
  const homeboy = JSON.parse(await readFile(join(root, "homeboy.json"), "utf8"))
  assert.deepEqual(homeboy.extensions?.wordpress?.settings?.package_profile, {
    manifest: "runtime-package-manifest.json",
    profile: "website-artifact-import",
  })
  assert.deepEqual(
    homeboy.scopes?.release?.include,
    profile.selectors.filter(({ path }) => !path.startsWith("vendor/")).map(({ path }) => path),
    "Homeboy release coverage must match the profile's tracked source selectors",
  )
  assert.deepEqual(profile.abilities, [
    "static-site-importer/import",
    "static-site-importer/materialize-wordpress-site-plan",
    "static-site-importer/validate-artifact",
    "static-site-importer/get-runtime-package-manifest",
  ])

  for (const selector of profile.selectors) {
    assert.ok(["file", "prefix"].includes(selector.type))
    assert.match(selector.path, /^(?!\/)(?!.*(?:^|\/)\.\.?\/)[A-Za-z0-9._\/-]+$/)
    if (selector.type === "prefix") assert.ok(selector.path.endsWith("/"))
    if (selector.type === "file") assert.ok(!selector.path.endsWith("/"))
  }

  const candidates = new Set(execFileSync("git", ["ls-files"], { cwd: root, encoding: "utf8" }).trim().split("\n").filter(Boolean))
  candidates.add("runtime-package-manifest.json")
  for (const path of await listFiles(join(root, "vendor"))) candidates.add(path)
  const selected = [...candidates].filter((path) => profile.selectors.some((selector) => selector.type === "file" ? path === selector.path : path.startsWith(selector.path))).sort()

  for (const required of profile.required_files) assert.ok(selected.includes(required), `missing required runtime file: ${required}`)
  assert.ok(selected.some((path) => path.startsWith("blocks/")))
  assert.ok(selected.some((path) => path.startsWith("vendor/league/")))
  assert.ok(selected.length > profile.required_files.length)

  for (const excluded of ["bench/", "build/", "docs/", "lib/", "node_modules/", "tests/", "tools/", "vendor/automattic/blocks-engine-figma-transformer/"]) {
    assert.equal(selected.some((path) => path.startsWith(excluded)), false, `profile leaked excluded tree: ${excluded}`)
  }
  for (const excluded of ["homeboy-test-manifest.json", "test-manifest.json"]) assert.equal(selected.includes(excluded), false, `profile leaked test manifest: ${excluded}`)
  assert.equal(selected.includes("build-provenance.json"), false, "release profile must not require development build identity")

  if (process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP) {
    const archive = execFileSync("unzip", ["-Z1", process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP], { encoding: "utf8" })
      .trim().split("\n").filter((path) => path && !path.endsWith("/"))
      .map((path) => {
        const prefix = `${manifest.package_root}/`
        assert.ok(path.startsWith(prefix), `archive entry escaped package root: ${path}`)
        return path.slice(prefix.length)
      }).sort()
    assert.deepEqual(archive, selected, "release archive must contain exactly the website-artifact-import profile")
  }
})

async function listFiles(directory) {
  const files = []
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) files.push(...await listFiles(path))
    else if (entry.isFile() && (await stat(path)).isFile()) files.push(relative(root, path).replaceAll("\\", "/"))
  }
  return files
}
