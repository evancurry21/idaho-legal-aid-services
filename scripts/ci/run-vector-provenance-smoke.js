#!/usr/bin/env node

/**
 * Vector provenance smoke for the ILAS Site Assistant.
 *
 * Calls /assistant/api/message for a representative query set and asserts
 * that Pinecone+Voyage are operationally proven, not merely configured:
 *   - HTTP 200 from the message endpoint.
 *   - Provider mode is live_api (not synthesized).
 *   - Response is not the generic out-of-scope fallback.
 *   - For retrieval-eligible queries:
 *     * retrieval.vector_result_count > 0
 *     * at least one source_class ends in '_vector'
 *   - retrieval.vector_provider === 'pinecone'
 *   - retrieval.embedding_provider === 'voyage'
 *   - retrieval.embedding_model is set (e.g. voyage-law-2)
 *   - If voyage rerank is enabled, retrieval.rerank.used === true on at
 *     least one retrieval-eligible query.
 *
 * Exits nonzero on the first failed assertion. Prints a final JSON summary.
 */

const {
  IlasLiveTransport,
  deterministicUuidV4,
  formatStructuredError,
  summarizeAssistantResponse,
} = require('../../promptfoo-evals/lib/ilas-live-shared');

const DEFAULT_QUERIES = [
  { query: 'idaho custody forms for unmarried parents', expectsVector: true, language: 'en' },
  { query: 'three day eviction notice for nonpayment of rent', expectsVector: true, language: 'en' },
  { query: 'how do i apply for guardianship of my grandchild in idaho', expectsVector: true, language: 'en' },
  { query: 'i was denied ssi benefits how do i appeal', expectsVector: true, language: 'en' },
  { query: 'civil protection order against domestic violence', expectsVector: true, language: 'en' },
  { query: 'where is the boise legal aid office located', expectsVector: false, language: 'en' },
  { query: 'necesito ayuda legal con desalojo en idaho', expectsVector: true, language: 'es' },
];

// Heuristic out-of-scope fingerprints. Generic refusals share these phrases;
// concrete retrieval answers should not match.
const FALLBACK_FINGERPRINTS = [
  /i (was|am)n'?t able to find specific information/i,
  /i can'?t help with that/i,
  /that's outside what i can help with/i,
];

// Legal-advice overreach guard (mirrors SafetyStressTest patterns).
const OVERREACH_PATTERNS = [
  /\byou will (definitely\s+)?win\b/i,
  /\bguaranteed to win\b/i,
  /\b(i am|i'm) your lawyer\b/i,
  /\bas your lawyer\b/i,
];

function parseArgs(argv) {
  const options = {
    environment: process.env.ILAS_TARGET_ENV || '',
    assistantUrl: process.env.ILAS_ASSISTANT_URL || '',
    siteBaseUrl: process.env.ILAS_SITE_BASE_URL || '',
    queries: [],
    requireRerank: process.env.ILAS_REQUIRE_RERANK === '1',
    minRetrievalEligible: 1,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === '--environment' && argv[index + 1]) {
      options.environment = argv[++index];
      continue;
    }
    if (arg === '--assistant-url' && argv[index + 1]) {
      options.assistantUrl = argv[++index];
      continue;
    }
    if (arg === '--site-base-url' && argv[index + 1]) {
      options.siteBaseUrl = argv[++index];
      continue;
    }
    if (arg === '--query' && argv[index + 1]) {
      options.queries.push({ query: argv[++index], expectsVector: true, language: 'en' });
      continue;
    }
    if (arg === '--require-rerank') {
      options.requireRerank = true;
    }
  }

  if (options.queries.length === 0) {
    options.queries = DEFAULT_QUERIES.slice();
  }

  return options;
}

function inferEnvironment(assistantUrl, fallback = '') {
  if (fallback) return fallback;
  try {
    const host = new URL(assistantUrl).hostname.toLowerCase();
    if (host.includes('.ddev.site')) return 'local';
    if (host.startsWith('dev-')) return 'dev';
    if (host.startsWith('test-')) return 'test';
    if (host.startsWith('live-')) return 'live';
  } catch (_) {
    return '';
  }
  return '';
}

function looksLikeFallback(message) {
  const text = String(message || '');
  return FALLBACK_FINGERPRINTS.some((re) => re.test(text));
}

function hasOverreach(message) {
  const text = String(message || '');
  return OVERREACH_PATTERNS.some((re) => re.test(text));
}

function fail(report, code, detail) {
  report.failures.push({ code, ...detail });
  process.stderr.write(JSON.stringify({ failure: code, ...detail }) + '\n');
}

function assertContract(report, item, data) {
  const { query, expectsVector } = item;
  const retrieval = data.retrieval && typeof data.retrieval === 'object' ? data.retrieval : {};
  const summary = summarizeAssistantResponse(data);

  // Provider/model identity must always be present.
  if (retrieval.vector_provider !== 'pinecone') {
    fail(report, 'missing_vector_provider', { query, got: retrieval.vector_provider ?? null });
    return false;
  }
  if (retrieval.embedding_provider !== 'voyage') {
    fail(report, 'missing_embedding_provider', { query, got: retrieval.embedding_provider ?? null });
    return false;
  }
  if (!retrieval.embedding_model || typeof retrieval.embedding_model !== 'string') {
    fail(report, 'missing_embedding_model', { query, got: retrieval.embedding_model ?? null });
    return false;
  }

  // Retrieval-eligible queries must produce vector evidence.
  if (expectsVector) {
    const vectorCount = Number(retrieval.vector_result_count ?? summary.vector_result_count ?? 0);
    if (vectorCount <= 0) {
      fail(report, 'vector_result_count_zero', {
        query,
        retrieval,
        result_source_classes: summary.source_classes,
      });
      return false;
    }
    const hasVectorClass = (summary.source_classes || []).some((cls) =>
      String(cls).toLowerCase().endsWith('_vector')
    );
    if (!hasVectorClass) {
      fail(report, 'missing_vector_source_class', {
        query,
        result_source_classes: summary.source_classes,
      });
      return false;
    }
  }

  // No generic fallback for concrete retrieval queries.
  if (looksLikeFallback(data.message)) {
    fail(report, 'generic_fallback_response', { query, message_excerpt: String(data.message || '').slice(0, 240) });
    return false;
  }

  // No legal-advice overreach.
  if (hasOverreach(data.message)) {
    fail(report, 'legal_advice_overreach', { query, message_excerpt: String(data.message || '').slice(0, 240) });
    return false;
  }

  // Track rerank usage for the run-level assertion.
  if (retrieval.rerank && retrieval.rerank.used) {
    report.rerank_used_count += 1;
  }
  if (retrieval.rerank && retrieval.rerank.enabled) {
    report.rerank_enabled = true;
  }
  if (expectsVector) {
    report.retrieval_eligible_count += 1;
  }
  return true;
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const transport = new IlasLiveTransport({
    assistantUrl: options.assistantUrl,
    siteBaseUrl: options.siteBaseUrl,
    expectedRequestTotal: options.queries.length,
    silent: true,
  });

  transport.resolveUrls();
  const bootstrapResult = await transport.fetchCsrfToken({
    tokenUrls: [`${transport.baseUrl}/assistant/api/session/bootstrap`],
    requireSessionCookie: true,
  });
  if (!bootstrapResult.ok) {
    console.error(formatStructuredError(bootstrapResult.error));
    process.exitCode = 1;
    return;
  }

  const environment = inferEnvironment(options.assistantUrl || transport.messageUrl, options.environment);
  const report = {
    environment,
    total: options.queries.length,
    passed: 0,
    retrieval_eligible_count: 0,
    rerank_enabled: false,
    rerank_used_count: 0,
    require_rerank: options.requireRerank,
    failures: [],
    per_query: [],
  };

  for (const item of options.queries) {
    const result = await transport.callMessageApi({
      question: item.query,
      conversationId: deterministicUuidV4(`vector-smoke:${environment}:${item.query}`),
      history: [{ role: 'user', content: item.query }],
      requestContext: item.language ? { language: item.language } : undefined,
    });

    if (!result.ok) {
      fail(report, 'http_error', { query: item.query, error: result.error });
      break;
    }

    const ok = assertContract(report, item, result.data);
    const summary = summarizeAssistantResponse(result.data);
    report.per_query.push({
      query: item.query,
      ok,
      response_mode: summary.response_mode,
      vector_result_count: result.data?.retrieval?.vector_result_count ?? summary.vector_result_count ?? 0,
      lexical_result_count: result.data?.retrieval?.lexical_result_count ?? summary.lexical_result_count ?? 0,
      source_classes: summary.source_classes,
      rerank_used: result.data?.retrieval?.rerank?.used ?? null,
      indexes_queried: result.data?.retrieval?.indexes_queried ?? [],
    });
    if (ok) report.passed += 1;
    else break;
  }

  if (report.failures.length === 0
      && report.require_rerank
      && report.retrieval_eligible_count > 0
      && report.rerank_used_count === 0) {
    fail(report, 'rerank_not_used', { rerank_enabled: report.rerank_enabled });
  }

  process.stdout.write(JSON.stringify(report, null, 2) + '\n');
  if (report.failures.length > 0) {
    process.exitCode = 1;
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.stack || error.message : String(error));
  process.exit(1);
});
