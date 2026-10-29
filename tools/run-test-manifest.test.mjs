import assert from "node:assert/strict"
import { execFileSync, spawnSync } from "node:child_process"
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from "node:fs"
import { tmpdir } from "node:os"
import { join } from "node:path"
import test from "node:test"
import { commandFor, discoverExecutableTests, validateManifest } from "./run-test-manifest.mjs"

test("discovery omits unstaged deletions but retains tracked and new tests", () => {
  const directory = mkdtempSync(join(tmpdir(), "ssi-test-manifest-"))
  try {
    execFileSync("git", ["init", "--quiet", directory])
    mkdirSync(join(directory, "tests"))
    for (const name of ["retired.php", "retained.php"]) {
      writeFileSync(join(directory, "tests", name), "<?php\n")
    }
    execFileSync("git", ["add", "tests"], { cwd: directory })
    rmSync(join(directory, "tests", "retired.php"))
    writeFileSync(join(directory, "tests", "replacement.php"), "<?php\n")
    assert.deepEqual(discoverExecutableTests(directory).sort(), [
      "tests/replacement.php", "tests/retained.php",
    ])
  } finally {
    rmSync(directory, { recursive: true, force: true })
  }
})

test("explicit command arguments retain spaces", () => {
  const command = ["node", "-e", "process.stdout.write(process.argv[1])", "argument with spaces"]

  assert.deepEqual(commandFor({ command }), command)
  const result = spawnSync(command[0], command.slice(1), { encoding: "utf8" })
  assert.equal(result.status, 0)
  assert.equal(result.stdout, "argument with spaces")
})

test("implicit commands retain their environment defaults", () => {
  assert.deepEqual(commandFor({ path: "tests/example.php", environment: "standalone-php" }), ["php", "tests/example.php"])
  assert.deepEqual(commandFor({ path: "tools/example.test.mjs", environment: "node" }), ["node", "--test", "tools/example.test.mjs"])
})

test("manifest rejects invalid explicit command declarations", () => {
  for (const command of ["node script.mjs", [], ["node", ""], ["node", " "]]) {
    assert.throws(
      () => validateManifest({
        schema: "static-site-importer/test-manifest/v1",
        tests: [{ path: "tools/run-test-manifest.mjs", environment: "node", command }],
      }),
      /command for tools\/run-test-manifest\.mjs must be a non-empty array of non-empty strings/,
    )
  }
})
