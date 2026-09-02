import assert from "node:assert/strict"
import { execFileSync } from "node:child_process"
import { mkdtemp, readdir, readFile, rm, stat } from "node:fs/promises"
import { tmpdir } from "node:os"
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
    [],
    "Homeboy release coverage must clear inherited source selectors",
  )
  assert.deepEqual(
    homeboy.scopes?.release?.exclude,
    ["bench/", "demos/", "homeboy-test-manifest.json", "lib/", "registry/", "rigs/", "test-manifest.json", "tools/"],
    "Homeboy release coverage must exclude tracked development surfaces outside the runtime profile",
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
  for (const path of candidates) {
    try {
      if (!(await stat(join(root, path))).isFile()) candidates.delete(path)
    } catch {
      candidates.delete(path)
    }
  }
  candidates.add("runtime-package-manifest.json")
  for (const path of profile.required_files) {
    assert.ok((await stat(join(root, path))).isFile(), `required runtime file is absent: ${path}`)
    candidates.add(path)
  }
  for (const path of await listFiles(join(root, "vendor"))) candidates.add(path)
  const selected = [...candidates].filter((path) => profile.selectors.some((selector) => selector.type === "file" ? path === selector.path : path.startsWith(selector.path))).sort()

  for (const required of profile.required_files) assert.ok(selected.includes(required), `missing required runtime file: ${required}`)
  assert.equal(selected.some((path) => path.startsWith("blocks/")), false, "runtime profile must not ship the demo block")
  assert.equal(selected.includes("includes/block.php"), false, "runtime profile must not ship the demo block bootstrap")
  assert.ok(selected.some((path) => path.startsWith("vendor/league/")))
  assert.ok(selected.length > profile.required_files.length)

  for (const excluded of ["bench/", "blocks/", "build/", "demos/", "docs/", "lib/", "node_modules/", "tests/", "tools/", "vendor/automattic/blocks-engine-figma-transformer/"]) {
    assert.equal(selected.some((path) => path.startsWith(excluded)), false, `profile leaked excluded tree: ${excluded}`)
  }
  for (const excluded of ["homeboy-test-manifest.json", "test-manifest.json"]) assert.equal(selected.includes(excluded), false, `profile leaked test manifest: ${excluded}`)
  assert.equal(selected.includes("build-provenance.json"), false, "release profile must not require development build identity")

  const bootstrap = await readFile(join(root, "static-site-importer.php"), "utf8")
  assert.doesNotMatch(bootstrap, /includes\/block\.php|static_site_importer_register_block/, "runtime bootstrap must remain UI-free")

})

test("supplied archive matches its selected runtime profile", async () => {
  if (process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP) {
    const manifest = JSON.parse(await readFile(manifestPath, "utf8"))
    const profileName = process.env.STATIC_SITE_IMPORTER_RUNTIME_PROFILE || "website-artifact-import"
    const profile = manifest.profiles?.[profileName]
    assert.ok(profile, `unknown runtime profile: ${profileName}`)
    const archive = execFileSync("unzip", ["-Z1", process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP], { encoding: "utf8" })
      .trim().split("\n").filter((path) => path && !path.endsWith("/"))
      .map((path) => {
        const prefix = `${manifest.package_root}/`
        assert.ok(path.startsWith(prefix), `archive entry escaped package root: ${path}`)
        return path.slice(prefix.length)
      }).sort()
    const selected = await selectedFiles(profile)
    if (archive.includes("build-provenance.json")) selected.push("build-provenance.json")
    assert.deepEqual(archive, selected.sort(), `archive must contain exactly the ${profileName} profile`)
  }
})

test("supplied HTML archive boots its local transformer without optional dependencies", async () => {
  if (!process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP || (process.env.STATIC_SITE_IMPORTER_RUNTIME_PROFILE || "website-artifact-import") !== "html-site-import") return

  const directory = await mkdtemp(join(tmpdir(), "ssi-html-runtime-"))
  try {
    execFileSync("unzip", ["-q", process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP, "-d", directory])
    const packageRoot = join(directory, "static-site-importer")
    const script = String.raw`
      $root = $argv[1];
      foreach (array('vendor/autoload.php', 'vendor/composer', 'vendor/league', 'vendor/symfony', 'vendor/automattic/blocks-engine-figma-transformer') as $path) {
        if (file_exists($root . '/' . $path)) throw new RuntimeException('unexpected dependency: ' . $path);
      }
      require $root . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';
      $plan = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<h1>Lean runtime</h1>')))->toWordPressSitePlanView();
      if (!str_contains(json_encode($plan), 'Lean runtime')) throw new RuntimeException('HTML was not converted to blocks');
      define('ABSPATH', $root . '/');
      class WP_Error { public function __construct(private $code = '', private $message = '') {} public function get_error_code() { return $this->code; } }
      require $root . '/includes/class-static-site-importer-runtime-capabilities.php';
      require $root . '/includes/class-static-site-importer-content-policy.php';
      $markdown = Static_Site_Importer_Content_Policy::validate_artifact(array('files' => array(array('path' => 'notes.md', 'content' => '# unavailable'))));
      if (!($markdown instanceof WP_Error) || 'static_site_importer_source_format_unsupported' !== $markdown->get_error_code()) throw new RuntimeException('Markdown availability was not reported');
    `
    execFileSync("php", ["-r", script, packageRoot], { stdio: "pipe" })
  } finally {
    await rm(directory, { recursive: true, force: true })
  }
})

test("HTML site import profile excludes optional conversion dependencies", async () => {
  const manifest = JSON.parse(await readFile(manifestPath, "utf8"))
  const profile = manifest.profiles?.["html-site-import"]
  assert.ok(profile)
  assert.deepEqual(profile.capabilities, ["html-site-import"])
  assert.deepEqual(profile.abilities, manifest.profiles["website-artifact-import"].abilities)

  const selected = await selectedFiles(profile)
  for (const required of profile.required_files) assert.ok(selected.includes(required), `missing required HTML runtime file: ${required}`)
  assert.ok(selected.some((path) => path.startsWith("vendor/automattic/blocks-engine-php-transformer/")))
  for (const excluded of ["vendor/autoload.php", "vendor/composer/"]) {
    assert.equal(selected.some((path) => path === excluded || path.startsWith(excluded)), false, `HTML profile leaked root Composer runtime: ${excluded}`)
  }
  for (const excluded of ["vendor/automattic/blocks-engine-figma-transformer/", "vendor/league/", "vendor/dflydev/", "vendor/nette/", "vendor/psr/", "vendor/symfony/"]) {
    assert.equal(selected.some((path) => path.startsWith(excluded)), false, `HTML profile leaked excluded dependency: ${excluded}`)
  }
})

async function selectedFiles(profile) {
  const candidates = new Set(execFileSync("git", ["ls-files"], { cwd: root, encoding: "utf8" }).trim().split("\n").filter(Boolean))
  for (const path of candidates) {
    try {
      if (!(await stat(join(root, path))).isFile()) candidates.delete(path)
    } catch {
      candidates.delete(path)
    }
  }
  candidates.add("runtime-package-manifest.json")
  for (const path of profile.required_files) {
    assert.ok((await stat(join(root, path))).isFile(), `required runtime file is absent: ${path}`)
    candidates.add(path)
  }
  for (const path of await listFiles(join(root, "vendor"))) candidates.add(path)
  return [...candidates].filter((path) => profile.selectors.some((selector) => selector.type === "file" ? path === selector.path : path.startsWith(selector.path))).sort()
}

async function listFiles(directory) {
  const files = []
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) files.push(...await listFiles(path))
    else if (entry.isFile() && (await stat(path)).isFile()) files.push(relative(root, path).replaceAll("\\", "/"))
  }
  return files
}
