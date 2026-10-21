# Reducer proofs

Each directory replays one historical defect through `tools/reduce-finding.mjs`
against the real engine code at the fix's parent commit, then checks the result
against the fix commit.

A proof passes when:

1. `page.html`, a realistic page at least 10x the minimal trigger, reproduces before the fix and passes after it.
2. The reducer shrinks it with the proof's predicate, which uses Chromium's computed styles as the oracle.
3. The reduced page still reproduces before the fix, passes after it, and matches the committed `reduced.html`.

`transform.php` runs the Blocks Engine PHP Transformer from a `php-transformer/`
checkout without Composer, so the same predicate can be pointed at any commit.

| Proof | Defect | Before | After | Predicate calls |
| --- | --- | --- | --- | --- |
| `blocks-engine-1914` | `:where()` counted toward specificity, so the engine picked the wrong authored marquee duration | 233 elements, 62 CSS rules | 19 elements, 5 CSS rules | 344 |

Run one with `BLOCKS_ENGINE=/path/to/blocks-engine tools/reduce-finding/proofs/blocks-engine-1914/run.sh`.

## Known limit

Removal is one node at a time, so nodes that only trigger the defect together
stay in the result. In `blocks-engine-1914` the marquee needs two identical halves:
removing any single logo breaks the pattern, so all 16 logos remain although 4 would do.
