import assert from "node:assert/strict"
import { mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises"
import { tmpdir } from "node:os"
import { basename, join } from "node:path"
import test from "node:test"
import { buildDevelopmentPackage, commandFailureMessage, developmentComposerManifest, overlayWorkingTree, parseArguments, provenance, worktreeIdentity } from "./build-dev-package.mjs"

test("parses explicit Blocks Engine inputs and sensible defaults", () => {
  const defaults = parseArguments([], "/workspace/static-site-importer")
  assert.equal(defaults.blocksEnginePath, "/workspace/blocks-engine")
  assert.equal(defaults.blocksEngineRef, "origin/trunk")
  assert.equal(defaults.outputDir, "/workspace/static-site-importer/build")
  assert.deepEqual(parseArguments(["--blocks-engine-path", "../engine", "--blocks-engine-ref", "feature/head", "--output-dir", "artifacts"], "/workspace/static-site-importer"), {
    blocksEnginePath: "/workspace/engine",
    blocksEngineRef: "feature/head",
    outputDir: "/workspace/static-site-importer/artifacts",
  })
})

test("development Composer metadata uses isolated, non-symlinked transformer snapshots", () => {
  const original = { require: { php: "^8.1" }, repositories: [{ type: "composer", url: "https://repo.packagist.org" }] }
  const overridden = developmentComposerManifest(original, "/tmp/package")
  assert.deepEqual(original, { require: { php: "^8.1" }, repositories: [{ type: "composer", url: "https://repo.packagist.org" }] })
  assert.deepEqual(overridden.repositories.slice(0, 2), [
    { type: "path", url: "/tmp/package/blocks-engine/php-transformer", options: { symlink: false, versions: { "automattic/blocks-engine-php-transformer": "dev-main" } } },
    { type: "path", url: "/tmp/package/blocks-engine/figma-transformer", options: { symlink: false, versions: { "automattic/blocks-engine-figma-transformer": "dev-main" } } },
  ])
  assert.equal(overridden.require["automattic/blocks-engine-php-transformer"], "*@dev")
  assert.equal(overridden.require["automattic/blocks-engine-figma-transformer"], "*@dev")
})

test("provenance binds immutable refs, the dirty identity, lock, and ZIP", async () => {
  const directory = await mkdtemp(join(tmpdir(), "ssi-dev-package-test-"))
  const zip = join(directory, "package.zip")
  await mkdir(directory, { recursive: true })
  await writeFile(zip, "zip fixture")
  const receipt = provenance({
    ssiSha: "a".repeat(40), ssiDiff: "b".repeat(64), blocksEngineSha: "c".repeat(40), blocksEngineRef: "origin/trunk",
    composerLock: Buffer.from("lock fixture"), zip: { path: zip, bytes: await readFile(zip) },
  })
  assert.equal(receipt.schema, "static-site-importer/development-package-provenance/v1")
  assert.equal(receipt.static_site_importer.head, "a".repeat(40))
  assert.equal(receipt.static_site_importer.diff_sha256, "b".repeat(64))
  assert.equal(receipt.blocks_engine.sha, "c".repeat(40))
  assert.match(receipt.composer_lock_sha256, /^[a-f0-9]{64}$/)
  assert.match(receipt.zip.sha256, /^[a-f0-9]{64}$/)
  await rm(directory, { recursive: true, force: true })
})

test("orchestration packages modified and untracked source bytes without changing the caller", async () => {
  const fixture = await mkdtemp(join(tmpdir(), "ssi-dev-package-fixture-"))
  const source = join(fixture, "source")
  const engine = join(fixture, "blocks-engine")
  const output = join(fixture, "output")
  const temporary = join(fixture, "temporary")
  await mkdir(source, { recursive: true })
  await mkdir(engine, { recursive: true })
  await writeFile(join(source, "composer.json"), JSON.stringify({ require: { php: "^8.1" } }))
  await writeFile(join(source, "composer.lock"), "caller lock")
  await writeFile(join(source, "tracked.txt"), "modified tracked bytes")
  await writeFile(join(source, "untracked.txt"), "untracked bytes")
  await mkdir(join(source, "vendor"), { recursive: true })
  await writeFile(join(source, "vendor", "ignored.txt"), "ignored bytes")
  const commands = []
  let extracted = 0
  const result = await buildDevelopmentPackage({ blocksEnginePath: engine, blocksEngineRef: "candidate", outputDir: output }, {
    sourceRoot: source,
    temporaryDirectory: temporary,
    cleanup: async () => {},
    run(command, args, context) {
      commands.push({ command, args, context })
      if (command === "git" && args[0] === "rev-parse") return Buffer.from(context.cwd === source ? `${"a".repeat(40)}\n` : `${"b".repeat(40)}\n`)
      if (command === "git" && args[0] === "status") return Buffer.from(" M tracked.txt\0?? untracked.txt\0")
      if (command === "git" && args[0] === "ls-files") return Buffer.from("composer.json\0composer.lock\0tracked.txt\0untracked.txt\0")
      if (command === "composer") return writeFile(join(context.cwd, "composer.lock"), "temporary lock")
      if (command === "homeboy") return Promise.all([readFile(join(context.cwd, "tracked.txt"), "utf8"), readFile(join(context.cwd, "untracked.txt"), "utf8")]).then(([tracked, untracked]) => mkdir(join(context.cwd, "build"), { recursive: true }).then(() => writeFile(join(context.cwd, "build/static-site-importer.zip"), `${tracked}|${untracked}`)))
      return Buffer.from("")
    },
    async extractArchive(_archive, destination) {
      extracted += 1
      if (extracted === 1) await writeFile(join(destination, "composer.json"), JSON.stringify({ require: { php: "^8.1" } }))
      else await mkdir(join(destination, "php-transformer"), { recursive: true })
    },
  })
  assert.equal(await readFile(join(source, "composer.json"), "utf8"), JSON.stringify({ require: { php: "^8.1" } }))
  assert.equal(await readFile(join(source, "tracked.txt"), "utf8"), "modified tracked bytes")
  assert.equal(await readFile(join(source, "untracked.txt"), "utf8"), "untracked bytes")
  assert.equal(extracted, 2)
  assert.ok(commands.some(({ command, args }) => command === "git" && args.join(" ") === "rev-parse candidate^{commit}"))
  assert.ok(commands.some(({ command, args }) => command === "git" && args.join(" ") === `archive --format=tar --output=${join(temporary, "blocks-engine.tar")} ${"b".repeat(40)} php-transformer figma-transformer`))
  assert.ok(commands.some(({ command, args }) => command === "homeboy" && args.join(" ") === `review --placement local build static-site-importer --path ${join(temporary, "static-site-importer")}`))
  assert.equal(basename(result.zip), `static-site-importer-dev-${"a".repeat(12)}-dirty-${result.receipt.static_site_importer.diff_sha256.slice(0, 12)}-blocks-engine-${"b".repeat(12)}.zip`)
  assert.equal(await readFile(result.zip, "utf8"), "modified tracked bytes|untracked bytes")
  assert.equal(result.receipt.static_site_importer.diff_sha256, await worktreeIdentity(source, ["composer.json", "composer.lock", "tracked.txt", "untracked.txt"]))
  await assert.rejects(() => readFile(join(temporary, "static-site-importer", "vendor", "ignored.txt"), "utf8"), /ENOENT/)
  assert.equal((await readFile(result.provenance, "utf8")).includes("candidate"), true)
  await rm(fixture, { recursive: true, force: true })
})

test("worktree overlay rejects reconstructable directories", async () => {
  const directory = await mkdtemp(join(tmpdir(), "ssi-dev-package-overlay-"))
  await mkdir(join(directory, "source", "vendor"), { recursive: true })
  await assert.rejects(() => overlayWorkingTree(join(directory, "source"), join(directory, "snapshot"), ["vendor/cache.php"]), /reconstructable path/)
  await rm(directory, { recursive: true, force: true })
})

test("nested command failures preserve bounded stdout and stderr evidence", () => {
  const stdoutOnly = commandFailureMessage("homeboy", ["review", "build"], { status: 7, stdout: Buffer.from("structured stdout"), stderr: Buffer.from(""), message: "generic failure" })
  assert.equal(stdoutOnly, "homeboy review build (exit 7) failed: structured stdout")

  const combined = commandFailureMessage("homeboy", ["review", "build"], { status: 1, stdout: Buffer.from("stdout evidence"), stderr: Buffer.from("stderr evidence"), message: "generic failure" })
  assert.match(combined, /stdout evidence\nstderr evidence$/)

  const oversized = commandFailureMessage("homeboy", ["review", "build"], { status: 1, stdout: Buffer.from(`start-${"x".repeat(65536)}-end`), stderr: Buffer.from(""), message: "generic failure" })
  assert.match(oversized, /start-/)
  assert.match(oversized, /truncated \d+ characters/)
  assert.match(oversized, /-end$/)
  assert.ok(oversized.length < 33000)
})
