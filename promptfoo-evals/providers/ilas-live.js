/**
 * ILAS Live Assistant — Promptfoo custom provider
 *
 * Shared transport/runtime lives in `promptfoo-evals/lib/ilas-live-shared.js`.
 * That module uses the current assistant session bootstrap endpoint and keeps a
 * legacy fallback only for older environments:
 *   - /assistant/api/session/bootstrap
 *   - /session/token
 * and appends `[contract_meta]` to rendered provider output.
 */

const {
  DEFAULT_EXPECTED_REQUEST_TOTAL,
  IlasLiveTransport,
  buildIlasProviderMeta,
  deterministicUuidV4,
  formatStructuredError,
  renderAssistantOutput,
} = require('../lib/ilas-live-shared');
const crypto = require('node:crypto');

class IlasLiveProvider {
  constructor(options = {}) {
    this.providerId = options.id || 'ilas-live';
    this.transport = new IlasLiveTransport({
      ...options,
      expectedRequestTotal: process.env.ILAS_EXPECTED_REQUEST_TOTAL || DEFAULT_EXPECTED_REQUEST_TOTAL,
      silent: true,
    });
    this.evalRunId = (process.env.ILAS_EVAL_RUN_ID || '').trim();
  }

  id() {
    return this.providerId;
  }

  getConversationIds(prompt, context) {
    const vars = context?.vars || {};
    const metadata =
      context?.metadata ||
      context?.test?.metadata ||
      context?.testCase?.metadata ||
      vars?.metadata ||
      {};

    const explicitConversationId =
      metadata.conversationId ||
      vars.conversation_id ||
      vars.conversationId ||
      null;

    // The trace identity is the value the assertion library hashes via
    // `computeExpectedConversationHash(context)` — it reads from the same
    // metadata fields. When a fixture provides one, it is the stable identity
    // for the whole multi-turn conversation.
    const traceConversationId = explicitConversationId || null;

    if (!this.evalRunId) {
      const apiConversationId = explicitConversationId || crypto.randomUUID();
      return { apiConversationId, traceConversationId: traceConversationId || apiConversationId };
    }

    // When an evalRunId is set, derive a deterministic UUID for the API so the
    // server-side cache key is stable across re-runs. The trace identity above
    // remains the user-visible conversationId used by assertions.
    //
    // IMPORTANT: when a fixture has an explicit conversationId, the API UUID is
    // a pure function of (evalRunId, explicitConversationId). It must NOT mix
    // in `metadata.turn`, otherwise each turn would get a fresh API
    // conversation_id and the server would lose its multi-turn history.
    if (explicitConversationId) {
      return {
        apiConversationId: deterministicUuidV4(`${this.evalRunId}:${explicitConversationId}`),
        traceConversationId,
      };
    }

    const stableFallbackKey = [
      vars.scenario_id,
      metadata.conversationName,
      metadata.turn,
      context?.testIdx,
      context?.promptIdx,
      vars.question || prompt,
    ]
      .filter((value) => value !== null && value !== undefined && value !== '')
      .join('|');

    const baseKey = stableFallbackKey || crypto.randomUUID();
    const apiConversationId = deterministicUuidV4(`${this.evalRunId}:${baseKey}`);
    return { apiConversationId, traceConversationId: apiConversationId };
  }

  // Back-compat shim: older callers may still invoke getConversationId(); keep
  // it returning the API conversation id.
  getConversationId(prompt, context) {
    return this.getConversationIds(prompt, context).apiConversationId;
  }

  logProgress(question, context) {
    const meta = context?.test?.metadata || context?.metadata || {};
    const turnInfo = meta.turn && meta.totalTurns ? ` T${meta.turn}/${meta.totalTurns}` : '';
    const convoName = meta.conversationName || '';
    const shortQ = question.length > 50 ? `${question.slice(0, 50)}...` : question;
    if (convoName || turnInfo) {
      console.log(`[promptfoo] ${convoName}${turnInfo} - "${shortQ}"`);
    }
  }

  async callApi(prompt, context) {
    this.transport.resolveUrls();

    const question = context?.vars?.question || prompt;
    const { apiConversationId, traceConversationId } = this.getConversationIds(prompt, context);
    const priorHistory = Array.isArray(context?.vars?.history) ? context.vars.history : [];
    const requestContext =
      context?.vars?.request_context && typeof context.vars.request_context === 'object'
        ? context.vars.request_context
        : undefined;
    const history = [...priorHistory, { role: 'user', content: question }];

    this.logProgress(question, context);

    const result = await this.transport.callMessageApi({
      question,
      conversationId: apiConversationId,
      history,
      requestContext,
    });

    if (!result.ok) {
      const providerMeta = buildIlasProviderMeta(
        {},
        this.transport.options.siteBaseUrl,
        {
          providerMode: 'live_api',
          conversationId: traceConversationId,
          transportMeta: result.transport || null,
          errors: [result.error],
        }
      );
      return {
        error: formatStructuredError(result.error),
        metadata: { ilas: providerMeta },
      };
    }

    const providerMeta = buildIlasProviderMeta(
      result.data,
      this.transport.options.siteBaseUrl,
      {
        providerMode: 'live_api',
        conversationId: traceConversationId,
        transportMeta: result.transport || null,
        requestId: result.data?.request_id || null,
      }
    );

    return {
      output: renderAssistantOutput(result.data, this.transport.options.siteBaseUrl, { providerMeta }),
      metadata: { ilas: providerMeta },
      tokenUsage: {},
    };
  }
}

module.exports = IlasLiveProvider;
