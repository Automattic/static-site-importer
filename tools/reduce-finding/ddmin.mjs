/**
 * Zeller and Hildebrandt's ddmin over an ordered list.
 *
 * `test(subset)` must return true when the subset still reproduces the
 * failure. The full input is assumed to reproduce. The search first tries the
 * empty set, then splits the current list into `granularity` chunks and tries
 * each chunk on its own, then each complement (the list minus one chunk).
 * When nothing reproduces it doubles the granularity, ending with
 * single-element removals. The result is 1-minimal: removing any single
 * remaining element no longer reproduces.
 *
 * @template T
 * @param {T[]} items
 * @param {(subset: T[]) => Promise<boolean>} test
 * @param {{ shouldStop?: () => boolean }} [options]
 * @returns {Promise<T[]>}
 */
export async function ddmin(items, test, { shouldStop = () => false } = {}) {
  let current = [...items];
  if (current.length === 0 || shouldStop()) {
    return current;
  }
  if (await test([])) {
    return [];
  }

  let granularity = 2;
  while (current.length >= 2 && !shouldStop()) {
    const chunks = splitIntoChunks(current, granularity);
    const next = await firstReproducing(chunks, current, granularity, test, shouldStop);
    if (next) {
      current = next.items;
      granularity = next.kind === 'subset' ? 2 : Math.max(granularity - 1, 2);
      continue;
    }
    if (granularity >= current.length) {
      break;
    }
    granularity = Math.min(granularity * 2, current.length);
  }
  return current;
}

async function firstReproducing(chunks, current, granularity, test, shouldStop) {
  for (const chunk of chunks) {
    if (shouldStop()) {
      return null;
    }
    if (await test(chunk)) {
      return { kind: 'subset', items: chunk };
    }
  }
  // With two chunks each complement equals the other chunk, already tested.
  if (granularity === 2) {
    return null;
  }
  for (const chunk of chunks) {
    if (shouldStop()) {
      return null;
    }
    const complement = current.filter((item) => !chunk.includes(item));
    if (await test(complement)) {
      return { kind: 'complement', items: complement };
    }
  }
  return null;
}

export function splitIntoChunks(items, count) {
  const chunks = [];
  let start = 0;
  for (let index = 0; index < count; index++) {
    const end = start + Math.floor((items.length - start) / (count - index));
    if (end > start) {
      chunks.push(items.slice(start, end));
    }
    start = end;
  }
  return chunks;
}
