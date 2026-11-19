// Search a monotone first-parent range. The current pass supplies the bad upper
// bound; verify both sides of the final boundary before attributing anything.
export async function bisectSearch(commits, prev, probe, regressed, baseline = false, onRange = () => {}) {
  const probes = new Map([[prev, Promise.resolve(baseline)]]);
  let low = 0;
  let high = commits.length - 1;
  const at = (commit) => {
    if (!probes.has(commit)) probes.set(commit, Promise.resolve().then(() => probe(commit)));
    return probes.get(commit);
  };
  try {
    if (!commits.length) throw new Error('no non-release commits');
    while (low < high) {
      onRange({ prev: low ? commits[low - 1] : prev, cur: commits[high] });
      const mid = Math.floor((low + high) / 2);
      if (regressed(await at(commits[mid]))) high = mid;
      else low = mid + 1;
    }
    const parent = low ? commits[low - 1] : prev;
    onRange({ prev: parent, cur: commits[low] });
    const [before, after] = await Promise.all([at(parent), at(commits[low])]);
    if (regressed(before) || !regressed(after)) throw new Error('boundary does not reproduce');
    return { status: 'complete', commit: commits[low], parent, before, after };
  } catch (error) {
    return { status: 'inconclusive', reason: error.message, range: { prev: low ? commits[low - 1] : prev, cur: commits[high] } };
  }
}

export function bisectSkip(added, prev, cur) {
  return !added?.length ? 'no added blockers' : !prev ? 'no previous trunk' : prev === cur ? 'unchanged trunk' : null;
}
