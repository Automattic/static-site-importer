import assert from "node:assert/strict"
import { spawnSync } from "node:child_process"
import test from "node:test"
import { commandFor, validateManifest } from "./run-test-manifest.mjs"

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
