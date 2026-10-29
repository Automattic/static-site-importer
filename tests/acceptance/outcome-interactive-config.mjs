// Neutral source capture plus explicit source-to-candidate bindings. A missing
// binding leaves its source row unsupported; it cannot shrink the inventory.
// Full-document evidence (outcomes.documents[route]) is route-specific and is
// built by the real-fixture runner; see docs/existing-runtime-acceptance.md.
export const interactiveConfig = {
  colourSchemes: ['light', 'dark'],
  capture: {
    schema: 'static-site-importer/interactive-capture/v1',
    pages: [{
      route: '/', sourceUrl: 'https://source.example/',
      interactionStates: { schema: 'blocks-engine-capture/interaction-states/v2', states: [{ id: 'disclosure', kind: 'disclosure', status: 'captured' }] },
      navigation: [{ id: 'information', destination: '/#fixture-panel' }],
      forms: [{ id: 'contact' }],
    }],
  },
  controls: {
    disclosure: { trigger: '#fixture-disclosure summary', target: '#fixture-disclosure', attribute: 'open', expected: '' },
    information: { trigger: '#fixture-nav a' },
  },
  forms: { contact: { selector: '#fixture-form', provider: 'fixture-local', fields: { Email: 'reader@example.test' }, successSelector: '#fixture-success' } },
};
