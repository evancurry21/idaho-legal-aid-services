/**
 * @file k6 Load Test Script for ILAS Site Assistant API
 *
 * Tests the /assistant/api/message endpoint with various query types
 * and concurrency levels.
 *
 * Scenarios tested:
 * - Short greeting queries (minimal processing)
 * - Navigation queries (intent routing + URL resolution)
 * - Retrieval-heavy queries (FAQ/resource search)
 *
 * Concurrency levels: 1, 5, 20 virtual users
 *
 * Usage:
 *   k6 run scripts/load/chatbot-api-loadtest.js
 *   k6 run scripts/load/chatbot-api-loadtest.js --out json=reports/load/results.json
 *
 * With custom base URL:
 *   k6 run scripts/load/chatbot-api-loadtest.js -e BASE_URL=https://ilas-pantheon.ddev.site
 */

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// Custom metrics for detailed analysis
const errorRate = new Rate('error_rate');
const shortQueryDuration = new Trend('short_query_duration', true);
const navigationQueryDuration = new Trend('navigation_query_duration', true);
const retrievalQueryDuration = new Trend('retrieval_query_duration', true);
const csrfTokenFetch = new Trend('csrf_token_fetch', true);
const timeoutErrors = new Counter('timeout_errors');
const httpErrors = new Counter('http_errors');

// Configuration
const BASE_URL = __ENV.BASE_URL || 'https://ilas-pantheon.ddev.site';
const API_ENDPOINT = `${BASE_URL}/assistant/api/message`;
const BOOTSTRAP_ENDPOINT = `${BASE_URL}/assistant/api/session/bootstrap`;
const ALLOW_LIVE_LOAD = readBoolean(__ENV.ALLOW_LIVE_LOAD);
const QUICK_MODE = readBoolean(__ENV.QUICK_MODE);
let vuSession = null;

// Test scenarios with realistic query variations
const SCENARIOS = {
  short: [
    { message: 'hello' },
    { message: 'hi' },
    { message: 'help' },
    { message: 'thanks' },
  ],
  navigation: [
    { message: 'how do I apply for help' },
    { message: 'where is your office' },
    { message: 'I need to talk to someone' },
    { message: 'call hotline' },
    { message: 'how to donate' },
    { message: 'find forms' },
  ],
  retrieval: [
    { message: 'eviction notice what do I do' },
    { message: 'my landlord wont fix broken heater' },
    { message: 'divorce custody child support' },
    { message: 'utility shutoff help' },
    { message: 'food stamps denied appeal' },
    { message: 'protection order domestic violence' },
    { message: 'debt collection harassment' },
    { message: 'tenant rights security deposit' },
  ],
};

const stagedScenarios = {
  // Stage 1: Low concurrency warmup (1 VU)
  low_concurrency: {
    executor: 'constant-vus',
    vus: 1,
    duration: '30s',
    startTime: '0s',
    tags: { concurrency: '1' },
  },
  // Stage 2: Medium concurrency (5 VUs)
  medium_concurrency: {
    executor: 'constant-vus',
    vus: 5,
    duration: '30s',
    startTime: '35s',
    tags: { concurrency: '5' },
  },
  // Stage 3: High concurrency (20 VUs)
  high_concurrency: {
    executor: 'constant-vus',
    vus: 20,
    duration: '30s',
    startTime: '70s',
    tags: { concurrency: '20' },
  },
};

const loadThresholds = {
  // Overall thresholds
  http_req_duration: ['p(50)<500', 'p(95)<2000', 'p(99)<5000'],
  http_req_failed: ['rate<0.05'], // Less than 5% errors
  error_rate: ['rate<0.05'],

  // Per-scenario thresholds
  short_query_duration: ['p(50)<200', 'p(95)<500'],
  navigation_query_duration: ['p(50)<300', 'p(95)<1000'],
  retrieval_query_duration: ['p(50)<500', 'p(95)<2000'],
};

// k6 test configuration - staged ramping through concurrency levels by default,
// or one VU for ten seconds when QUICK_MODE=1.
export const options = buildOptions();

function buildOptions() {
  const builtOptions = {
    // TLS config for self-signed DDEV certificates
    insecureSkipTLSVerify: true,
  };

  if (QUICK_MODE) {
    builtOptions.vus = 1;
    builtOptions.duration = '10s';
    return builtOptions;
  }

  builtOptions.scenarios = stagedScenarios;
  builtOptions.thresholds = loadThresholds;
  return builtOptions;
}

// Setup function: validate target and summarize the test. Each VU bootstraps its
// own anonymous session before posting messages.
export function setup() {
  console.log(`\n=== ILAS Site Assistant API Load Test ===`);
  console.log(`Target: ${API_ENDPOINT}`);
  console.log(`Bootstrap: ${BOOTSTRAP_ENDPOINT}`);
  console.log(`Scenarios: short, navigation, retrieval`);
  console.log(QUICK_MODE ? `Quick mode: 1 VU for 10s\n` : `Concurrency stages: 1 → 5 → 20 VUs\n`);

  if (isProductionLikeTarget(BASE_URL) && !ALLOW_LIVE_LOAD) {
    throw new Error(
      'Refusing to run load test against a live/public target. ' +
      'Use a local/staging URL, or set ALLOW_LIVE_LOAD=1 only with explicit production approval.'
    );
  }

  return {};
}

// Main test function
export default function() {
  // Randomly select and run one scenario type per iteration
  const scenarioType = selectScenarioType();

  group(`${scenarioType}_queries`, function() {
    const session = ensureVuSession(false);
    if (!session) {
      errorRate.add(true);
      httpErrors.add(1);
      sleep(1);
      return;
    }

    const queries = SCENARIOS[scenarioType];
    const query = queries[Math.floor(Math.random() * queries.length)];

    const payload = JSON.stringify({
      message: query.message,
      conversation_id: makeConversationId(`${__VU}:${__ITER}:${scenarioType}:${query.message}`),
      context: { history: [] },
    });
    const startTime = Date.now();

    let response = postMessage(payload, session, scenarioType);

    if (response.status === 403) {
      const refreshedSession = ensureVuSession(true);
      if (refreshedSession) {
        response = postMessage(payload, refreshedSession, scenarioType);
      }
    }

    const duration = Date.now() - startTime;

    // Record to appropriate metric
    switch(scenarioType) {
      case 'short':
        shortQueryDuration.add(duration);
        break;
      case 'navigation':
        navigationQueryDuration.add(duration);
        break;
      case 'retrieval':
        retrievalQueryDuration.add(duration);
        break;
    }

    // Check response validity
    const isSuccess = check(response, {
      'status is 200': (r) => r.status === 200,
      'response is JSON': (r) => {
        try {
          JSON.parse(r.body);
          return true;
        } catch (e) {
          return false;
        }
      },
      'response has message field': (r) => {
        try {
          const body = JSON.parse(r.body);
          return body.message !== undefined || body.error !== undefined;
        } catch (e) {
          return false;
        }
      },
      'response time < 5s': (r) => r.timings.duration < 5000,
    });

    // Track errors
    errorRate.add(!isSuccess);

    if (response.status === 0) {
      timeoutErrors.add(1);
    } else if (response.status >= 400) {
      httpErrors.add(1);
    }

    // Log failures for debugging (limited to avoid log spam)
    if (!isSuccess && Math.random() < 0.1) {
      console.log(`[${scenarioType}] Failed: status=${response.status}, query="${query.message}"`);
    }
  });

  // Small pause between requests to simulate realistic traffic
  sleep(0.1 + Math.random() * 0.2);
}

// Weighted random scenario selection
// Retrieval queries are most common in production
function selectScenarioType() {
  const rand = Math.random();
  if (rand < 0.2) return 'short';      // 20% short queries
  if (rand < 0.4) return 'navigation'; // 20% navigation
  return 'retrieval';                   // 60% retrieval-heavy
}

function ensureVuSession(forceRefresh) {
  if (vuSession && !forceRefresh) {
    return vuSession;
  }

  const startTime = Date.now();
  const tokenRes = http.get(BOOTSTRAP_ENDPOINT, {
    headers: { Accept: 'text/plain' },
    tags: { name: 'assistant_session_bootstrap' },
    timeout: '10s',
  });
  csrfTokenFetch.add(Date.now() - startTime);

  const cookies = extractCookies(tokenRes);
  const csrfToken = tokenRes.status === 200 ? String(tokenRes.body || '').trim() : '';

  const isReady = check(tokenRes, {
    'bootstrap status is 200': (r) => r.status === 200,
    'bootstrap returned CSRF token': () => csrfToken.length > 0,
    'bootstrap returned session cookie': () => Object.keys(cookies).length > 0,
  });

  if (!isReady) {
    console.warn(`Warning: Could not bootstrap assistant session (status ${tokenRes.status})`);
    vuSession = null;
    return null;
  }

  vuSession = { csrfToken, cookies };
  return vuSession;
}

function postMessage(payload, session, scenarioType) {
  const params = {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-Token': session.csrfToken,
    },
    tags: {
      name: 'assistant_message',
      scenario: scenarioType,
    },
    timeout: '10s',
  };

  if (Object.keys(session.cookies).length > 0) {
    params.cookies = session.cookies;
  }

  return http.post(API_ENDPOINT, payload, params);
}

function extractCookies(response) {
  const cookies = {};

  for (const name in response.cookies) {
    if (
      Object.prototype.hasOwnProperty.call(response.cookies, name) &&
      response.cookies[name] &&
      response.cookies[name].length > 0
    ) {
      cookies[name] = response.cookies[name][0].value;
    }
  }

  return cookies;
}

function makeConversationId(seed) {
  const hash = hashHex(seed, 32);
  return hash.slice(0, 8) + '-' +
    hash.slice(8, 12) + '-' +
    '4' + hash.slice(13, 16) + '-' +
    '8' + hash.slice(17, 20) + '-' +
    hash.slice(20, 32);
}

function hashHex(seed, length) {
  let hash = 2166136261;
  let output = '';

  while (output.length < length) {
    const input = seed + ':' + output.length;
    for (let i = 0; i < input.length; i++) {
      hash ^= input.charCodeAt(i);
      hash = Math.imul(hash, 16777619);
    }
    output += (hash >>> 0).toString(16).padStart(8, '0');
  }

  return output.slice(0, length);
}

function readBoolean(value) {
  return value === true || value === '1' || value === 'true' || value === 'yes';
}

function isProductionLikeTarget(baseUrl) {
  const match = String(baseUrl).match(/^https?:\/\/([^/:?#]+)/i);
  if (!match) {
    return false;
  }
  const host = match[1].toLowerCase();

  return host === 'idaholegalaid.org' ||
    host === 'www.idaholegalaid.org' ||
    host.indexOf('live-idaho-legal-aid-services') === 0;
}

// Teardown function: Summary output
export function teardown(data) {
  console.log('\n=== Load Test Complete ===');
  console.log('See detailed metrics in the k6 output above.');
  console.log('For JSON output, use: --out json=reports/load/results.json');
}

// Helper function to safely get nested properties (k6 doesn't support optional chaining)
function safeGet(obj, path, defaultVal) {
  var parts = path.split('.');
  var current = obj;
  for (var i = 0; i < parts.length; i++) {
    if (current === null || current === undefined) {
      return defaultVal;
    }
    current = current[parts[i]];
  }
  return current !== null && current !== undefined ? current : defaultVal;
}

// Handle summary and generate custom report
export function handleSummary(data) {
  var timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  var reportPath = 'reports/load/loadtest-' + timestamp + '.json';

  // Safely extract metrics
  var httpReqs = safeGet(data, 'metrics.http_reqs.values.count', 0);
  var errRate = safeGet(data, 'metrics.error_rate.values.rate', 0);
  var httpDur = safeGet(data, 'metrics.http_req_duration.values', {});
  var shortDur = safeGet(data, 'metrics.short_query_duration.values', {});
  var navDur = safeGet(data, 'metrics.navigation_query_duration.values', {});
  var retDur = safeGet(data, 'metrics.retrieval_query_duration.values', {});
  var timeoutErrs = safeGet(data, 'metrics.timeout_errors.values.count', 0);
  var httpErrs = safeGet(data, 'metrics.http_errors.values.count', 0);

  // Extract key metrics for the summary
  var summary = {
    timestamp: new Date().toISOString(),
    config: {
      base_url: BASE_URL,
      endpoint: API_ENDPOINT,
      bootstrap_endpoint: BOOTSTRAP_ENDPOINT,
      scenarios: Object.keys(SCENARIOS),
      concurrency_stages: QUICK_MODE ? [1] : [1, 5, 20],
      quick_mode: QUICK_MODE,
    },
    overall: {
      total_requests: httpReqs,
      error_rate: errRate,
      http_req_duration: {
        avg: httpDur.avg || 0,
        min: httpDur.min || 0,
        max: httpDur.max || 0,
        p50: httpDur['p(50)'] || 0,
        p90: httpDur['p(90)'] || 0,
        p95: httpDur['p(95)'] || 0,
        p99: httpDur['p(99)'] || 0,
      },
    },
    by_scenario: {
      short: {
        p50: shortDur['p(50)'] || 0,
        p95: shortDur['p(95)'] || 0,
        avg: shortDur.avg || 0,
      },
      navigation: {
        p50: navDur['p(50)'] || 0,
        p95: navDur['p(95)'] || 0,
        avg: navDur.avg || 0,
      },
      retrieval: {
        p50: retDur['p(50)'] || 0,
        p95: retDur['p(95)'] || 0,
        avg: retDur.avg || 0,
      },
    },
    errors: {
      timeout_errors: timeoutErrs,
      http_errors: httpErrs,
    },
    thresholds_passed: Object.entries(data.metrics)
      .filter(function(entry) { return entry[1].thresholds; })
      .map(function(entry) {
        return {
          metric: entry[0],
          passed: Object.values(entry[1].thresholds).every(function(t) { return t.ok; }),
        };
      }),
  };

  // Generate markdown report
  var mdReport = generateMarkdownReport(summary);
  var mdReportPath = 'reports/load/loadtest-' + timestamp + '.md';

  var result = {};
  result[reportPath] = JSON.stringify(summary, null, 2);
  result[mdReportPath] = mdReport;
  result.stdout = textSummary(data, { indent: ' ', enableColors: true }) + '\n\n' +
            '=== Custom Summary ===\n' +
            'Total Requests: ' + summary.overall.total_requests + '\n' +
            'Error Rate: ' + (summary.overall.error_rate * 100).toFixed(2) + '%\n' +
            'P50 Latency: ' + summary.overall.http_req_duration.p50.toFixed(0) + 'ms\n' +
            'P95 Latency: ' + summary.overall.http_req_duration.p95.toFixed(0) + 'ms\n' +
            'P99 Latency: ' + summary.overall.http_req_duration.p99.toFixed(0) + 'ms\n\n' +
            'Report saved to: ' + reportPath + '\n';

  return result;
}

// Import for text summary
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.2/index.js';

function generateMarkdownReport(summary) {
  var p95Overall = summary.overall.http_req_duration.p95;
  var errorRate = summary.overall.error_rate * 100;

  // Determine status indicators
  var p95Status = p95Overall < 1000 ? '✅' : (p95Overall < 2000 ? '⚠️' : '❌');
  var errorStatus = errorRate < 1 ? '✅' : (errorRate < 5 ? '⚠️' : '❌');

  var md = '# ILAS Site Assistant API Load Test Report\n\n' +
    '**Generated:** ' + summary.timestamp + '\n' +
    '**Target:** ' + summary.config.endpoint + '\n\n' +
    '## Summary\n\n' +
    '| Metric | Value | Status |\n' +
    '|--------|-------|--------|\n' +
    '| Total Requests | ' + summary.overall.total_requests + ' | - |\n' +
    '| Error Rate | ' + errorRate.toFixed(2) + '% | ' + errorStatus + ' |\n' +
    '| P50 Latency | ' + summary.overall.http_req_duration.p50.toFixed(0) + 'ms | - |\n' +
    '| P95 Latency | ' + summary.overall.http_req_duration.p95.toFixed(0) + 'ms | ' + p95Status + ' |\n' +
    '| P99 Latency | ' + summary.overall.http_req_duration.p99.toFixed(0) + 'ms | - |\n\n' +
    '## Latency by Scenario\n\n' +
    '| Scenario | P50 | P95 | Avg |\n' +
    '|----------|-----|-----|-----|\n' +
    '| Short (greeting) | ' + summary.by_scenario.short.p50.toFixed(0) + 'ms | ' + summary.by_scenario.short.p95.toFixed(0) + 'ms | ' + summary.by_scenario.short.avg.toFixed(0) + 'ms |\n' +
    '| Navigation | ' + summary.by_scenario.navigation.p50.toFixed(0) + 'ms | ' + summary.by_scenario.navigation.p95.toFixed(0) + 'ms | ' + summary.by_scenario.navigation.avg.toFixed(0) + 'ms |\n' +
    '| Retrieval | ' + summary.by_scenario.retrieval.p50.toFixed(0) + 'ms | ' + summary.by_scenario.retrieval.p95.toFixed(0) + 'ms | ' + summary.by_scenario.retrieval.avg.toFixed(0) + 'ms |\n\n' +
    '## Error Breakdown\n\n' +
    '- Timeout Errors: ' + summary.errors.timeout_errors + '\n' +
    '- HTTP Errors (4xx/5xx): ' + summary.errors.http_errors + '\n\n' +
    '## Threshold Results\n\n';

  for (var i = 0; i < summary.thresholds_passed.length; i++) {
    var threshold = summary.thresholds_passed[i];
    var icon = threshold.passed ? '✅' : '❌';
    md += '- ' + icon + ' ' + threshold.metric + '\n';
  }

  md += '\n## Recommendations\n\n';

  // Generate recommendations based on results
  if (p95Overall > 2000) {
    md += '### ❌ High P95 Latency (' + p95Overall.toFixed(0) + 'ms)\n\n' +
      '**Recommended optimizations:**\n\n' +
      '1. **Enable response caching** - Cache FAQ/resource search results with short TTL (60-300s)\n' +
      '2. **Precompute FAQ index** - Build inverted index at cache warm-up instead of runtime\n' +
      '3. **Reduce payload size** - Strip unnecessary fields from API response\n' +
      '4. **Database query optimization** - Add indexes on frequently searched fields\n' +
      '5. **Consider async processing** - Move LLM enhancement to background with streaming response\n\n';
  }

  if (summary.by_scenario.retrieval.p95 > 1500) {
    md += '### ⚠️ Slow Retrieval Queries (P95: ' + summary.by_scenario.retrieval.p95.toFixed(0) + 'ms)\n\n' +
      '**Specific optimizations for retrieval:**\n\n' +
      '1. **Add search result caching** - Cache top FAQ matches per normalized query\n' +
      '2. **Limit search depth** - Cap retrieval to top 3-5 results, paginate if needed\n' +
      '3. **Optimize FaqIndex::search()** - Profile and optimize keyword matching algorithm\n' +
      '4. **Pre-warm hot queries** - Cache responses for common queries at startup\n\n';
  }

  if (errorRate > 1) {
    md += '### ⚠️ Elevated Error Rate (' + errorRate.toFixed(2) + '%)\n\n' +
      '**Investigate:**\n\n' +
      '1. Check Drupal watchdog logs for exceptions\n' +
      '2. Verify CSRF token handling under load\n' +
      '3. Check for database connection pool exhaustion\n' +
      '4. Monitor PHP-FPM worker availability\n\n';
  }

  if (p95Overall <= 1000 && errorRate < 1) {
    md += '### ✅ Performance Within Acceptable Range\n\n' +
      'Current performance is acceptable. Consider these proactive improvements:\n\n' +
      '1. **Set up monitoring** - Track P95 latency in production dashboards\n' +
      '2. **Establish baselines** - Run load tests after each deployment\n' +
      '3. **Plan for scale** - Test at 2x-5x expected peak traffic periodically\n\n';
  }

  return md;
}
