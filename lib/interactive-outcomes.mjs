// Source inventories are exhaustive capture artifacts, not a list of candidate
// controls discovered after import. Candidate bindings never remove source rows.
const finish = (evidence, failures, unsupported) => ({ status: failures.length ? 'fail' : unsupported.length ? 'unsupported' : 'pass', evidence, failures, unsupported });

export async function interactiveOutcomes({ browser, candidateOrigin, routes, widths, config }) {
  const capture = config.capture;
  const controls = [], controlFailures = [], controlUnsupported = [];
  const forms = [], formFailures = [], formUnsupported = [];
  if (capture?.schema !== 'static-site-importer/interactive-capture/v1' || !Array.isArray(capture.pages) || !routes?.length || !widths?.length || widths.some(width => !Number.isInteger(width) || width < 1)) {
    const missing = { reason: 'exhaustive_interaction_navigation_form_capture_and_widths_required' };
    return { control_inventory: finish([missing], [], [missing]), forms: finish([missing], [], [missing]) };
  }
  for (const route of routes) {
    const pages = capture.pages.filter(page => page.route === route);
    if (pages.length !== 1 || !['blocks-engine-capture/interaction-states/v1', 'blocks-engine-capture/interaction-states/v2'].includes(pages[0].interactionStates?.schema) || !Array.isArray(pages[0].interactionStates?.states) || !Array.isArray(pages[0].navigation) || !Array.isArray(pages[0].forms)) {
      const missing = { route, reason: 'complete_source_route_inventory_required' };
      controlUnsupported.push(missing); formUnsupported.push(missing); continue;
    }
    const source = pages[0];
    try { new URL(source.sourceUrl); } catch {
      const missing = { route, reason: 'source_url_required' };
      controlUnsupported.push(missing); formUnsupported.push(missing); continue;
    }
    const sourceControls = [...source.interactionStates.states, ...source.navigation.map(item => ({ ...item, kind: 'navigation' }))];
    if ([...sourceControls, ...source.forms].some(item => !item?.id) || new Set(sourceControls.map(item => item.id)).size !== sourceControls.length || new Set(source.forms.map(item => item.id)).size !== source.forms.length) {
      const missing = { route, reason: 'unique_source_identities_required' };
      controlUnsupported.push(missing); formUnsupported.push(missing); continue;
    }
    for (const width of widths) {
      for (const control of sourceControls) {
        for (const modality of ['pointer', 'keyboard']) {
          const row = { route, width, id: control.id, kind: control.kind, modality };
          controls.push(row);
          const binding = config.controls?.[control.id];
          if (!binding || binding.unsupportedReason || !['dialog', 'listbox', 'disclosure', 'tabs', 'navigation'].includes(control.kind)) {
            row.status = 'unsupported'; row.reason = binding?.unsupportedReason || 'candidate_transition_binding_unavailable'; controlUnsupported.push(row); continue;
          }
          const probe = await browser.newPage({ viewport: { width, height: 1000 } });
          try {
            probe.setDefaultTimeout(3000);
            const loaded = await probe.goto(new URL(route, candidateOrigin).href);
            if (loaded?.status() !== 200 || new URL(probe.url()).origin !== candidateOrigin) throw new Error('source_route_unavailable');
            const trigger = probe.locator(binding.trigger);
            if (await trigger.count() !== 1 || !await trigger.isVisible()) throw new Error('trigger_missing_or_ambiguous');
            const target = control.kind !== 'navigation' && probe.locator(binding.target);
            const read = async () => binding.attribute ? target.getAttribute(binding.attribute) : target.isVisible();
            const before = target ? await read() : null;
            if (modality === 'pointer') await trigger.click({ timeout: 3000 });
            else {
              // Reach via actual sequential keyboard navigation, not programmatic focus.
              let reached = false;
              for (let index = 0; index < 100; index++) {
                await probe.keyboard.press('Tab');
                if (await trigger.evaluate(node => node === document.activeElement)) { reached = true; break; }
              }
              if (!reached) throw new Error('trigger_not_keyboard_reachable');
              await probe.keyboard.press(binding.key || 'Enter');
            }
            if (control.kind === 'navigation') {
              if (!control.destination) throw new Error('source_destination_required');
              const destination = new URL(control.destination, source.sourceUrl);
              const expected = destination.origin === new URL(source.sourceUrl).origin ? new URL(destination.pathname + destination.search + destination.hash, candidateOrigin).href : destination.href;
              await probe.waitForURL(expected, { timeout: 3000 });
              const response = await probe.request.get(probe.url());
              if (response.status() !== 200) throw new Error('navigation_destination_unavailable');
              if (destination.hash && !await probe.evaluate(hash => !!document.getElementById(decodeURIComponent(hash.slice(1))), destination.hash)) throw new Error('navigation_fragment_missing');
              row.destination = probe.url();
            } else {
              if (binding.expected === undefined) throw new Error('transition_expectation_required');
              await probe.waitForFunction(({ selector, attribute, expected }) => {
                const node = document.querySelector(selector);
                return node && (attribute ? node.getAttribute(attribute) : !!(node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden')) === expected;
              }, { selector: binding.target, attribute: binding.attribute, expected: binding.expected }, { timeout: 3000 });
              const after = await read();
              if (before === after) throw new Error('transition_unchanged');
              row.before = before; row.after = after;
            }
            row.status = 'pass';
          } catch (error) { row.reason = error.message; row.status = 'fail'; controlFailures.push(row); controlUnsupported.push(row); }
          finally { await probe.close(); }
        }
      }
      for (const form of source.forms) {
        const row = { route, width, id: form.id }; forms.push(row);
        const binding = config.forms?.[form.id];
        if (![binding?.recipient, binding?.provider, binding?.ownerHandoff].some(value => typeof value === 'string' && value.trim())) { row.reason = 'recipient_provider_or_owner_handoff_required'; formFailures.push(row); continue; }
        const probe = await browser.newPage({ viewport: { width, height: 1000 } });
        try {
          probe.setDefaultTimeout(3000);
          const loaded = await probe.goto(new URL(route, candidateOrigin).href);
          if (loaded?.status() !== 200 || new URL(probe.url()).origin !== candidateOrigin) throw new Error('source_route_unavailable');
          const candidate = probe.locator(binding.selector);
          if (await candidate.count() !== 1 || !await candidate.evaluate(node => node.tagName === 'FORM')) throw new Error('submission_form_required');
          const action = await candidate.getAttribute('action');
          if (!action || new URL(action, probe.url()).origin !== candidateOrigin) throw new Error('explicit_local_submission_handler_required');
          if (!binding.successSelector) throw new Error('submission_success_assertion_required');
          if (await probe.locator(binding.successSelector).isVisible()) throw new Error('submission_success_already_visible');
          if ((await candidate.getAttribute('method'))?.toLowerCase() !== 'post') throw new Error('post_submission_required');
          if (await candidate.locator('input:not([type="submit"]),select,textarea').evaluateAll(nodes => nodes.some(node => !node.name))) throw new Error('submission_field_names_required');
          for (const [name, value] of Object.entries(binding.fields ?? {})) await candidate.getByRole('textbox', { name, exact: true }).fill(value);
          const responsePromise = probe.waitForResponse(response => response.request().method() === 'POST' && response.url() === new URL(action, new URL(route, candidateOrigin)).href, { timeout: 3000 });
          // Handle the response promise even when validation prevents submission.
          const submitted = responsePromise.catch(() => null);
          await candidate.locator('[type="submit"]').click({ timeout: 3000 });
          const response = await submitted;
          if (!response || response.status() < 200 || response.status() >= 300) throw new Error('submission_handler_failed');
          await probe.locator(binding.successSelector).waitFor({ state: 'visible', timeout: 3000 });
          row.status = 'pass'; row.handler = response.url(); row.recipient = binding.recipient; row.provider = binding.provider; row.owner_handoff = binding.ownerHandoff;
        } catch (error) { row.reason = error.message; row.status = 'fail'; formFailures.push(row); }
        finally { await probe.close(); }
      }
    }
    controls.push({ route, reason: 'source_inventory_enumerated', states: source.interactionStates.states.length, navigation: source.navigation.length });
    forms.push({ route, reason: 'source_inventory_enumerated', forms: source.forms.length });
  }
  return { control_inventory: finish(controls, controlFailures, controlUnsupported), forms: finish(forms, formFailures, formUnsupported) };
}
