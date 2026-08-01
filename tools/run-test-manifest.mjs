import { execFileSync, spawnSync } from "node:child_process"
import { existsSync, readFileSync } from "node:fs"
import { dirname, resolve } from "node:path"
import { fileURLToPath } from "node:url"

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const manifestPath = resolve(root, "test-manifest.json")
const homeboyPath = resolve(root, "homeboy-test-manifest.json")
const args = new Set(process.argv.slice(2))
const manifest = JSON.parse(readFileSync(manifestPath, "utf8"))
const environments = new Set(["standalone-php", "wordpress-runtime", "node", "browser-wp-codebox", "operator-only"])

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    run()
  } catch (error) {
    console.error(error.message)
    process.exitCode = 1
  }
}

function run() {
  validateManifest()
  if (args.has("--check")) return

  const selectedEnvironments = args.has("--all")
    ? environments
    : new Set(["standalone-php", "node"])
  const selected = manifest.tests.filter((test) => selectedEnvironments.has(test.environment))
  const results = { passed: [], failed: [], skipped: [] }

  for (const test of selected) {
    if (test.environment === "operator-only") {
      results.skipped.push(`${test.path} (operator-only acceptance: ${commandFor(test).join(" ")})`)
      continue
    }
    if (test.environment === "wordpress-runtime" && !process.env.STATIC_SITE_IMPORTER_WP_CLI) {
      results.skipped.push(`${test.path} (set STATIC_SITE_IMPORTER_WP_CLI to run WordPress runtime tests)`)
      continue
    }
    if (test.environment === "browser-wp-codebox" && !process.env.STATIC_SITE_IMPORTER_THEME_DIR) {
      results.skipped.push(`${test.path} (set STATIC_SITE_IMPORTER_THEME_DIR after a WP Codebox or WordPress import)`)
      continue
    }

    const command = commandFor(test)
    const result = spawnSync(command[0], command.slice(1), { cwd: root, stdio: "inherit", env: process.env })
    if (result.status === 0) results.passed.push(test.path)
    else results.failed.push(test.path)
  }

  for (const environment of environments) {
    const count = manifest.tests.filter((test) => test.environment === environment).length
    const selectedCount = selected.filter((test) => test.environment === environment).length
    console.log(`${environment}: ${selectedCount ? `selected ${selectedCount}` : `skipped ${count}`}`)
  }
  console.log(`passed: ${results.passed.length}; failed: ${results.failed.length}; intentionally skipped: ${results.skipped.length}`)
  for (const skipped of results.skipped) console.log(`SKIP ${skipped}`)
  if (results.failed.length) process.exitCode = 1
}

export function commandFor(test) {
  if (test.command) return test.command
  if (test.environment === "standalone-php") return ["php", test.path]
  if (test.environment === "node") return ["node", "--test", test.path]
  if (test.environment === "wordpress-runtime") return [...process.env.STATIC_SITE_IMPORTER_WP_CLI.split(" "), "eval-file", test.path]
  return ["node", test.path]
}

export function validateManifest(candidate = manifest) {
  if (candidate.schema !== "static-site-importer/test-manifest/v1") fail("unexpected manifest schema")
  const declared = new Set()
  for (const test of candidate.tests) {
    if ("command" in test && (!Array.isArray(test.command) || !test.command.length || test.command.some((argument) => typeof argument !== "string" || !argument.trim()))) {
      fail(`command for ${test.path} must be a non-empty array of non-empty strings`)
    }
    if (!environments.has(test.environment)) fail(`unknown environment for ${test.path}: ${test.environment}`)
    if (declared.has(test.path)) fail(`test is declared more than once: ${test.path}`)
    if (!existsSync(resolve(root, test.path))) fail(`declared test does not exist: ${test.path}`)
    declared.add(test.path)
  }

  const executable = execFileSync("git", ["ls-files", "--cached", "--others", "--exclude-standard", "--", "tests", "tools"], { cwd: root, encoding: "utf8" })
    .trim().split("\n").filter(Boolean)
    .filter(isExecutableTest)
  const exclusions = Object.keys(candidate.exclusions || {})
  for (const path of executable) {
    if (!declared.has(path) && !exclusions.some((pattern) => matches(pattern, path))) fail(`undeclared executable test: ${path}`)
  }

  const homeboy = JSON.parse(readFileSync(homeboyPath, "utf8"))
  const projected = Object.fromEntries(candidate.tests
    .filter((test) => test.environment === "standalone-php")
    .map((test) => [test.path, { environment: test.environment }]))
  if (JSON.stringify(homeboy.tests) !== JSON.stringify(projected)) fail("homeboy-test-manifest.json is not the standalone PHP projection of test-manifest.json")
}

function isExecutableTest(path) {
  return (/^tests\/.*\.(?:php|cjs|mjs|js)$/).test(path) || /^tools\/.*\.test\.mjs$/.test(path)
}

function matches(pattern, path) {
  return pattern.endsWith("/**") ? path.startsWith(pattern.slice(0, -2)) : path === pattern
}

function fail(message) {
  throw new Error(`Test manifest error: ${message}`)
}
