# Capture binding evidence

Refs #2431

## Problem

The default toolchain on `origin/main` did not bind captured evidence to outcome
inputs. Missing bindings prevented measurement independently of import quality.

## Change

The default now resolves producer-neutral receipt reports, redirects, document
boundaries and preserved interaction identities. Missing evidence remains
unsupported; explicit outcome bindings override automatic declarations.

## Evidence

The committed synthetic capture exercises all five affected checks with real
browser probes, including failing form delivery ownership, unbound identity,
empty cleanup declarations and missing/truncated evidence.

The private fifteen-site corpus was imported into disposable WordPress before
and after the change. No private artifacts are included here. Each cell is
**before → after**; `U` means unsupported, `P` pass and `F` fail.

| Site | Links | Blocks | Head | Controls | Forms | Readability | Full document | Theme |
|---|---|---|---|---|---|---|---|---|
| site A | U→F | P→P | U→U | U→U | U→U | U→U | U→F | P→P |
| site B | F→F | P→P | U→U | U→U | U→F | U→U | U→F | P→F |
| site C | F→F | P→P | U→P | U→U | U→U | F→F | U→F | P→P |
| site D | F→U | P→U | F→U | U→U | U→U | U→U | U→U | P→U |
| site E | U→U | P→U | U→U | U→U | U→U | F→U | U→U | P→U |
| site F | U→F | U→P | U→P | U→U | U→U | U→U | U→U | U→P |
| site G | F→F | P→P | U→P | U→U | U→U | U→U | U→U | P→P |
| site H | F→F | P→P | U→P | U→U | U→F | F→F | U→F | P→F |
| site I | F→F | F→F | U→P | U→U | U→U | U→U | U→F | P→P |
| site J | U→P | U→P | U→U | U→U | U→P | U→U | U→F | U→P |
| site K | U→U | U→U | U→P | U→U | U→U | F→F | U→F | U→U |
| site L | U→F | U→U | U→P | U→U | U→U | U→U | U→F | P→F |
| site M | F→F | P→P | U→P | U→U | U→U | U→U | U→F | P→P |
| site N | F→F | P→P | U→P | U→U | U→P | F→F | U→F | P→P |
| site O | F→F | P→P | U→P | U→U | U→U | U→U | U→F | P→P |

These are observed statuses, not release certification or a claim of causal
improvement in unrelated checks. Some runs lacked runtime evidence entirely
(sites D/E after, F/J before); those cells remain unsupported. Controls still
lack provable transitions/identities in these captures. Form passes can represent
an exhaustive empty inventory, not inferred successful delivery. Newly exercised
aliases also expose failures under the theme-switch probe.

AI assistance: Claude (Anthropic) via Roadie wrote the change and test.
