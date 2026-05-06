const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const test = require('node:test');

const {
  buildIlasProviderMeta,
  deterministicUuidV4,
} = require('../../lib/ilas-live-shared');
const {
  hasGenerationProviderProof,
  hasRetrievalAttemptProof,
  hasSafetyBlockProof,
  hasStableConversationTrace,
  hasSupportedCitation,
} = require('../../lib/ilas-assertions');

const FIXTURE_CONVERSATION_ID = 'conv-fixture-001';

function expectedHash(value) {
  return crypto.createHash('sha256').update(String(value)).digest('hex').slice(0, 20);
}

test('Tier A1: provider meta hashes the trace conversationId, not the API UUID', () => {
  const apiConversationId = deterministicUuidV4(`run-42:${FIXTURE_CONVERSATION_ID}`);
  assert.notEqual(apiConversationId, FIXTURE_CONVERSATION_ID);

  const providerMeta = buildIlasProviderMeta(
    { message: 'hello', type: 'faq' },
    'https://idaholegalaid.org',
    {
      providerMode: 'live_api',
      // The provider passes the trace identity here so assertions hash the
      // same value the test fixture uses.
      conversationId: FIXTURE_CONVERSATION_ID,
    }
  );

  const assertion = hasStableConversationTrace(
    `Hello\n\n[ilas_provider_meta]${JSON.stringify(providerMeta)}`,
    {
      providerResponse: { metadata: { ilas: providerMeta } },
      metadata: { conversationId: FIXTURE_CONVERSATION_ID },
    }
  );
  assert.equal(assertion.pass, true, assertion.reason);
  assert.equal(providerMeta.conversation_id_hash, expectedHash(FIXTURE_CONVERSATION_ID));
});

test('Tier A2: normalizer reads payload.diagnostics.generation for provider proof', () => {
  const providerMeta = buildIlasProviderMeta(
    {
      message: 'We can help with eviction…',
      type: 'topic',
      meta: {
        generation: { provider: 'cohere', model: 'command-a-03-2025', used: true },
      },
    },
    'https://idaholegalaid.org',
    { providerMode: 'live_api', conversationId: FIXTURE_CONVERSATION_ID }
  );

  const assertion = hasGenerationProviderProof('out', {
    providerResponse: { metadata: { ilas: providerMeta } },
    vars: { require_generation_provider: 'cohere' },
  });
  assert.equal(assertion.pass, true, assertion.reason);
  assert.equal(providerMeta.generation.provider, 'cohere');
  assert.equal(providerMeta.generation.used, true);
});

test('Tier A2: normalizer reads payload.diagnostics.safety for safety block proof', () => {
  const providerMeta = buildIlasProviderMeta(
    {
      message: "I can't help with that. Please call the Legal Advice Line.",
      type: 'refusal',
      reason_code: 'safety_legal_advice',
      meta: {
        generation: { provider: 'cohere', used: false },
        safety: { blocked: true, stage: 'pre_generation_block', class: 'legal_advice', reason_code: 'court_script' },
      },
    },
    'https://idaholegalaid.org',
    { providerMode: 'live_api', conversationId: FIXTURE_CONVERSATION_ID }
  );

  const assertion = hasSafetyBlockProof('out', {
    providerResponse: { metadata: { ilas: providerMeta } },
    vars: { require_safety_blocked: true },
  });
  assert.equal(assertion.pass, true, assertion.reason);
  assert.equal(providerMeta.safety.blocked, true);
  assert.equal(providerMeta.safety.stage, 'pre_generation_block');
});

test('Tier A2: normalizer reads payload.diagnostics.retrieval for retrieval-attempt proof', () => {
  const providerMeta = buildIlasProviderMeta(
    {
      message: 'Here are some resources.',
      type: 'topic',
      meta: {
        retrieval: { used: true, attempted: true },
      },
      // No results array — but diagnostics says we attempted, so the assertion
      // should accept it.
    },
    'https://idaholegalaid.org',
    { providerMode: 'live_api', conversationId: FIXTURE_CONVERSATION_ID }
  );

  const assertion = hasRetrievalAttemptProof('out', {
    providerResponse: { metadata: { ilas: providerMeta } },
    vars: { require_retrieval_attempted: true },
  });
  assert.equal(assertion.pass, true, assertion.reason);
});

test('Tier A3: citation topic falls back to source_class slug when not explicit', () => {
  const providerMeta = buildIlasProviderMeta(
    {
      message: 'Eviction info follows.',
      type: 'topic',
      sources: [
        {
          title: 'Idaho Eviction Process',
          url: 'https://idaholegalaid.org/help/housing/eviction',
          source: 'faq',
          source_class: 'faq_lexical_eviction',
          // intentionally no topic field
        },
      ],
    },
    'https://idaholegalaid.org',
    { providerMode: 'live_api', conversationId: FIXTURE_CONVERSATION_ID }
  );

  const assertion = hasSupportedCitation('out', {
    providerResponse: { metadata: { ilas: providerMeta } },
    vars: { expected_topic: 'eviction' },
  });
  assert.equal(assertion.pass, true, assertion.reason);
  assert.equal(providerMeta.citations.supported.length > 0, true);
  assert.equal(providerMeta.citations.supported[0].topic, 'eviction');
});

test('Tier A3: citation topic falls back to URL slug when source_class absent', () => {
  const providerMeta = buildIlasProviderMeta(
    {
      message: 'Custody info follows.',
      type: 'topic',
      sources: [
        {
          title: 'Filing for Custody in Idaho',
          url: 'https://idaholegalaid.org/help/family/custody',
          source: 'resource',
          // no source_class, no topic
        },
      ],
    },
    'https://idaholegalaid.org',
    { providerMode: 'live_api', conversationId: FIXTURE_CONVERSATION_ID }
  );

  const supported = providerMeta.citations.supported;
  assert.equal(supported.length > 0, true);
  assert.equal(supported[0].topic, 'custody');
});

test('Tier A1: when fixture lacks conversationId, normalizer still produces a hash', () => {
  const providerMeta = buildIlasProviderMeta(
    { message: 'hi', type: 'faq' },
    'https://idaholegalaid.org',
    { providerMode: 'live_api', conversationId: 'fallback-uuid' }
  );
  assert.equal(typeof providerMeta.conversation_id_hash, 'string');
  assert.equal(providerMeta.conversation_id_hash.length, 20);
});
