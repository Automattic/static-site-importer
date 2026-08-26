import { createHash } from "node:crypto"
import { execFileSync } from "node:child_process"
import { cp, lstat, mkdtemp, mkdir, readFile, readlink, rm, stat, writeFile } from "node:fs/promises"
import { tmpdir } from "node:os"
import { basename, dirname, join, resolve } from "node:path"
import { fileURLToPath } from "node:url"

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const schema = "static-site-importer/development-package-provenance/v1"

export function parseArguments(argv, cwd = process.cwd()) {
  const options = {
    blocksEnginePath: resolve(cwd, "../blocks-engine"),
    blocksEngineRef: "origin/trunk",
    outputDir: resolve(cwd, "build"),
  }
  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index]
    if (value === "--blocks-engine-path") options.blocksEnginePath = resolve(cwd, requiredValue(argv, ++index, value))
    else if (value === "--blocks-engine-ref") options.blocksEngineRef = requiredValue(argv, ++index, value)
    else if (value === "--output-dir") options.outputDir = resolve(cwd, requiredValue(argv, ++index, value))
    else if (value === "--help") options.help = true
    else throw new Error(`Unknown argument: ${value}`)
  }
  return options
}

function requiredValue(argv, index, flag) {
  if (!argv[index] || argv[index].startsWith("--")) throw new Error(`${flag} requires a value`)
  return argv[index]
}

export function developmentComposerManifest(manifest, packageRoot) {
  const packages = [
    ["php-transformer", "automattic/blocks-engine-php-transformer"],
    ["figma-transformer", "automattic/blocks-engine-figma-transformer"],
  ]
  return {
    ...manifest,
    repositories: [
      ...packages.map(([directory, name]) => ({
        type: "path",
        url: join(packageRoot, "blocks-engine", directory),
        options: { symlink: false, versions: { [name]: "dev-main" } },
      })),
      ...(manifest.repositories ?? []),
    ],
    require: {
      ...manifest.require,
      "automattic/blocks-engine-php-transformer": "*@dev",
      "automattic/blocks-engine-figma-transformer": "*@dev",
    },
  }
}

export function provenance({ ssiSha, ssiDiff, blocksEngineSha, blocksEngineRef, composerLock, zip }) {
  return {
    schema,
    command: "npm run build:dev-package",
    static_site_importer: {
      head: ssiSha,
      dirty: ssiDiff !== null,
      diff_sha256: ssiDiff,
    },
    blocks_engine: { ref: blocksEngineRef, sha: blocksEngineSha },
    composer_lock_sha256: digest(composerLock),
    zip: { file: basename(zip.path), sha256: digest(zip.bytes) },
  }
}

export async function overlayWorkingTree(sourceRoot, snapshot, paths) {
  const included = [...new Set(paths.map((path) => validateSourcePath(path)))].sort()
  for (const path of included) {
    const source = join(sourceRoot, path)
    const destination = join(snapshot, path)
    try {
      const metadata = await lstat(source)
      if (metadata.isDirectory()) throw new Error(`tracked directory cannot be packaged as a source file: ${path}`)
      await mkdir(dirname(destination), { recursive: true })
      await cp(source, destination, { force: true, dereference: false, preserveTimestamps: true })
    } catch (error) {
      if (error.code === "ENOENT") await rm(destination, { force: true })
      else throw error
    }
  }
  return included
}

export async function worktreeIdentity(sourceRoot, paths) {
  const entries = []
  for (const path of [...new Set(paths.map((path) => validateSourcePath(path)))].sort()) {
    try {
      const metadata = await lstat(join(sourceRoot, path))
      if (metadata.isDirectory()) throw new Error(`tracked directory cannot be identified as a source file: ${path}`)
      const bytes = metadata.isSymbolicLink() ? Buffer.from(await readlink(join(sourceRoot, path))) : await readFile(join(sourceRoot, path))
      entries.push(`${path}\0${metadata.mode & 0o777}\0${digest(bytes)}\n`)
    } catch (error) {
      if (error.code === "ENOENT") entries.push(`${path}\0missing\n`)
      else throw error
    }
  }
  return digest(entries.join(""))
}

export async function buildDevelopmentPackage(options, dependencies = {}) {
  const sourceRoot = dependencies.sourceRoot ?? root
  const run = dependencies.run ?? runCommand
  const temporaryDirectory = dependencies.temporaryDirectory ?? await mkdtemp(join(tmpdir(), "static-site-importer-dev-package-"))
  const cleanup = dependencies.cleanup ?? (async () => rm(temporaryDirectory, { recursive: true, force: true }))
  const extractArchive = dependencies.extractArchive ?? ((archive, destination) => run("tar", ["-xf", archive, "-C", destination], { cwd: temporaryDirectory }))
  const snapshot = join(temporaryDirectory, "static-site-importer")
  const blocksEngine = join(temporaryDirectory, "blocks-engine")

  try {
    for (const tool of ["git", "tar", "composer", "homeboy"]) await run(tool, ["--version"], { cwd: sourceRoot })
    const ssiSha = text(await run("git", ["rev-parse", "HEAD"], { cwd: sourceRoot }))
    const blocksEngineSha = text(await run("git", ["rev-parse", `${options.blocksEngineRef}^{commit}`], { cwd: options.blocksEnginePath }))
    const status = await run("git", ["status", "--porcelain=v1", "-z"], { cwd: sourceRoot, allowEmpty: true })
    const sourcePaths = text(await run("git", ["ls-files", "-z", "--cached", "--others", "--exclude-standard"], { cwd: sourceRoot })).split("\0").filter(Boolean)
    const ssiDiff = status.length ? await worktreeIdentity(sourceRoot, sourcePaths) : null

    await mkdir(snapshot, { recursive: true })
    const ssiArchive = join(temporaryDirectory, "ssi.tar")
    await run("git", ["archive", "--format=tar", `--output=${ssiArchive}`, ssiSha], { cwd: sourceRoot })
    await extractArchive(ssiArchive, snapshot)
    await overlayWorkingTree(sourceRoot, snapshot, sourcePaths)

    await mkdir(blocksEngine, { recursive: true })
    const blocksArchive = join(temporaryDirectory, "blocks-engine.tar")
    await run("git", ["archive", "--format=tar", `--output=${blocksArchive}`, blocksEngineSha, "php-transformer", "figma-transformer"], { cwd: options.blocksEnginePath })
    await extractArchive(blocksArchive, blocksEngine)

    const composerPath = join(snapshot, "composer.json")
    const manifest = JSON.parse(await readFile(composerPath, "utf8"))
    await writeFile(composerPath, `${JSON.stringify(developmentComposerManifest(manifest, temporaryDirectory), null, 2)}\n`)
    await run("composer", ["update", "automattic/blocks-engine-php-transformer", "automattic/blocks-engine-figma-transformer", "--with-all-dependencies", "--no-dev", "--no-interaction", "--prefer-dist"], { cwd: snapshot })

    await run("homeboy", ["review", "--placement", "local", "build", "static-site-importer", "--path", snapshot], { cwd: snapshot })
    const generatedZip = join(snapshot, "build", "static-site-importer.zip")
    if (!await exists(generatedZip)) throw new Error(`Homeboy completed without the expected artifact: ${generatedZip}`)

    const sourceIdentity = ssiDiff
      ? `${ssiSha.slice(0, 12)}-dirty-${ssiDiff.slice(0, 12)}`
      : ssiSha.slice(0, 12)
    const outputName = `static-site-importer-dev-${sourceIdentity}-blocks-engine-${blocksEngineSha.slice(0, 12)}.zip`
    const outputZip = join(options.outputDir, outputName)
    await mkdir(options.outputDir, { recursive: true })
    await cp(generatedZip, outputZip)
    const receipt = provenance({
      ssiSha,
      ssiDiff,
      blocksEngineSha,
      blocksEngineRef: options.blocksEngineRef,
      composerLock: await readFile(join(snapshot, "composer.lock")),
      zip: { path: outputZip, bytes: await readFile(outputZip) },
    })
    const provenancePath = `${outputZip}.json`
    await writeFile(provenancePath, `${JSON.stringify(receipt, null, 2)}\n`)
    return { zip: outputZip, provenance: provenancePath, receipt }
  } finally {
    await cleanup()
  }
}

function runCommand(command, args, { cwd }) {
  try {
    return execFileSync(command, args, { cwd, encoding: "buffer", stdio: ["ignore", "pipe", "pipe"] })
  } catch (error) {
    throw new Error(commandFailureMessage(command, args, error))
  }
}

export function commandFailureMessage(command, args, error) {
  const status = Number.isInteger(error.status) ? ` (exit ${error.status})` : ""
  const output = [boundedOutput(error.stdout), boundedOutput(error.stderr)].filter(Boolean).join("\n")
  return `${command} ${args.join(" ")}${status} failed: ${output || error.message}`
}

function boundedOutput(output, limit = 32768) {
  const value = output?.toString().trim() ?? ""
  if (value.length <= limit) return value
  const marker = `\n...[truncated ${value.length - limit} characters]...\n`
  const edge = Math.floor((limit - marker.length) / 2)
  return `${value.slice(0, edge)}${marker}${value.slice(-edge)}`
}

function text(value) {
  return value.toString().trim()
}

function digest(value) {
  return createHash("sha256").update(value).digest("hex")
}

function validateSourcePath(path) {
  if (!path || path.startsWith("/") || path.split("/").some((part) => !part || part === "." || part === "..")) throw new Error(`invalid Git source path: ${path}`)
  if (path.split("/").some((part) => [".git", "vendor", "node_modules", "build", ".homeboy-build"].includes(part))) throw new Error(`reconstructable path cannot be packaged from the worktree: ${path}`)
  return path
}

async function exists(path) {
  try {
    return (await stat(path)).isFile()
  } catch {
    return false
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const options = parseArguments(process.argv.slice(2), root)
  if (options.help) {
    console.log("Usage: npm run build:dev-package -- [--blocks-engine-path <path>] [--blocks-engine-ref <ref>] [--output-dir <path>]")
  } else {
    buildDevelopmentPackage(options).then(({ zip, provenance: receipt }) => console.log(`Built ${zip}\nProvenance ${receipt}`)).catch((error) => {
      console.error(`Development package build failed: ${error.message}`)
      process.exitCode = 1
    })
  }
}
