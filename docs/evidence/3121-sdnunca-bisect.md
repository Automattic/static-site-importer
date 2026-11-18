# sdnunca trunk regression replay

Primary evidence: [machine-readable bisect output](3121-sdnunca-bisect.json).

The saved sdnunca-s5 capture, pass 3 importer zip, pass 2 evaluation and pass 3
added signatures were replayed between `206290e98f` and `a718034600`. No source
recapture or import mock was used. Five probe imports identify
[Blocks Engine #6356](https://github.com/Automattic/blocks-engine/pull/6356),
`e3429a416ea5897418b95ec07ce21ed7dcd5778d`, as the first regressed commit.
Its immediate first parent is `1e0f58af1f4585d2d3315245dbba6a6829ae5407`.

| signature | previous evaluation | parent probe | culprit probe |
| --- | ---: | ---: | ---: |
| `055bae0c7d93` | 11 | 11 | 27 |
| `bef4614d433d` (motion) | 0 | 0 | 149 |
| `3371170ac8fe` | 0 | 0 | 4 |
| `6d8bf4985b52` | 0 | 0 | 6 |

Every added signature exceeds its previous count at the culprit, and none does
at its parent; the JSON includes all signatures, cells and probe counts.
Probe counts need not equal the original pass exactly: browser-measured
occurrences include motion and reversible editor interactions. Attribution
requires crossing the previous count, not equality with the later pass.

This standalone replay produced evidence only; it did not post attribution
comments on live issues or the culprit PR. Workflow triage posts those after
it has tracked issue URLs.
