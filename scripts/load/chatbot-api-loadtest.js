/**
 * @file k6 Load Test Script for ILAS Chatbot API
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
const CSRF_ENDPOINT = `${BASE_URL}/session/token`;

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

// k6 test configuration - staged ramping through concurrency levels
export const options = {
  scenarios: {
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
  },
  thresholds: {
    // Overall thresholds
    http_req_duration: ['p(50)<500', 'p(95)<2000', 'p(99)<5000'],
    http_req_failed: ['rate<0.05'], // Less than 5% errors
    error_rate: ['rate<0.05'],

    // Per-scenario thresholds
    short_query_duration: ['p(50)<200', 'p(95)<500'],
    navigation_query_duration: ['p(50)<300', 'p(95)<1000'],
    retrieval_query_duration: ['p(50)<500', 'p(95)<2000'],
  },

  // TLS config for self-signed DDEV certificates
  insecureSkipTLSVerify: true,
};

// Setup function: Get CSRF token for the test
export function setup() {
  console.log(`\n=== ILAS Chatbot API Load Test ===`);
  console.log(`Target: ${API_ENDPOINT}`);
  console.log(`Scenarios: short, navigation, retrieval`);
  console.log(`Concurrency stages: 1 → 5 → 20 VUs\n`);

  const startTime = Date.now();

  // Fetch CSRF token from Drupal
  const tokenRes = http.get(CSRF_ENDPOINT, {
    tags: { name: 'csrf_token' },
  });

  csrfTokenFetch.add(Date.now() - startTime);

  let csrfToken = '';
  if (tokenRes.status === 200) {
    csrfToken = tokenRes.body;
    console.log('CSRF token acquired successfully');
  } else {
    console.warn(`Warning: Could not fetch CSRF token (status ${tokenRes.status})`);
    console.warn('Tests may fail if CSRF protection is enabled');
  }

  return { csrfToken };
}

// Main test function
export default function(data) {
  const csrfToken = data.csrfToken;

  // Headers for API requests
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };

  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  // Randomly select and run one scenario type per iteration
  const scenarioType = selectScenarioType();

  group(`${scenarioType}_queries`, function() {
    const queries = SCENARIOS[scenarioType];
    const query = queries[Math.floor(Math.random() * queries.length)];

    const payload = JSON.stringify(query);
    const startTime = Date.now();

    const response = http.post(API_ENDPOINT, payload, {
      headers: headers,
      tags: {
        name: 'chatbot_message',
        scenario: scenarioType,
      },
      timeout: '10s',
    });

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
      scenarios: Object.keys(SCENARIOS),
      concurrency_stages: [1, 5, 20],
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

  var md = '# ILAS Chatbot API Load Test Report\n\n' +
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
