# ILAS Chatbot API Load Testing

Load and latency benchmarks for the `/assistant/api/message` endpoint using k6.

## Quick Start

```bash
# Prerequisites: install k6
brew install k6           # macOS
sudo apt install k6       # Debian/Ubuntu

# Start DDEV
ddev start

# Run the load test
./scripts/load/run-loadtest.sh

# Quick smoke test (1 VU, 10s)
./scripts/load/run-loadtest.sh --quick

# Against a custom URL
./scripts/load/run-loadtest.sh --url https://staging.example.com
```

## Test Configuration

### Scenarios

| Scenario | Description | % of Traffic | Example Queries |
|----------|-------------|--------------|-----------------|
| Short | Greetings, simple interactions | 20% | "hello", "help", "thanks" |
| Navigation | Intent routing to pages | 20% | "how do I apply", "find offices" |
| Retrieval | FAQ/resource search | 60% | "eviction notice", "divorce custody" |

### Concurrency Stages

The test runs through three stages to measure performance under increasing load:

| Stage | VUs | Duration | Description |
|-------|-----|----------|-------------|
| 1 | 1 | 30s | Baseline single-user performance |
| 2 | 5 | 30s | Light concurrent load |
| 3 | 20 | 30s | Peak concurrent load |

### Thresholds

| Metric | Threshold | Rationale |
|--------|-----------|-----------|
| P50 latency | < 500ms | Good user experience |
| P95 latency | < 2000ms | Acceptable for 95% of requests |
| P99 latency | < 5000ms | Max acceptable response time |
| Error rate | < 5% | Reliability target |

## Output

### Console Summary

The test outputs a summary with:
- Total requests and throughput
- P50, P90, P95, P99 latency percentiles
- Error rate and breakdown
- Threshold pass/fail status

### Report Files

Reports are saved to `reports/load/`:
- `loadtest-<timestamp>.json` - Full metrics data
- `loadtest-<timestamp>.md` - Human-readable report with recommendations

## API Endpoint Details

- **URL**: `/assistant/api/message`
- **Method**: `POST`
- **Headers**:
  - `Content-Type: application/json`
  - `X-CSRF-Token: <token>` (fetched from `/session/token`)
- **Body**: `{ "message": "user query" }`

## Performance Optimization Recommendations

If P95 latency exceeds thresholds, consider these optimizations:

### 1. Response Caching (High Impact)

Cache FAQ and resource search results with short TTL:

```php
// In FaqIndex::search()
$cache_key = 'faq_search:' . md5($query);
if ($cached = $this->cache->get($cache_key)) {
  return $cached->data;
}
$results = $this->doSearch($query);
$this->cache->set($cache_key, $results, time() + 300); // 5 min TTL
return $results;
```

### 2. Precomputed FAQ Index (High Impact)

Build inverted index at cache warm-up:

```php
// drush ilas:warm-faq-cache
public function warmFaqCache() {
  $faqs = $this->loadAllFaqs();
  $index = $this->buildInvertedIndex($faqs);
  $this->cache->set('faq_inverted_index', $index, Cache::PERMANENT);
}
```

### 3. Reduce Response Payload (Medium Impact)

Strip unnecessary fields from API responses:
- Remove debug metadata in production
- Paginate results (return top 3, offer "load more")
- Use compact field names in JSON

### 4. Database Query Optimization (Medium Impact)

Add indexes for frequently searched fields:

```sql
-- For FAQ search
ALTER TABLE paragraph__field_faq_question
  ADD FULLTEXT INDEX idx_faq_question (field_faq_question_value);

-- For resource finder
ALTER TABLE node__field_resource_keywords
  ADD INDEX idx_resource_keywords (field_resource_keywords_value(50));
```

### 5. Async LLM Enhancement (Lower Priority)

If LLM enhancement is slow, consider:
- Make it async with streaming response
- Set aggressive timeout (2s max)
- Cache enhanced responses by query hash

### 6. Connection Pooling

Ensure PHP-FPM and database connections are properly pooled:

```ini
; php-fpm.conf
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
```

## Monitoring in Production

After establishing baselines, set up monitoring:

1. **Application Performance Monitoring (APM)**
   - Track P95 latency per endpoint
   - Alert when exceeding 2x baseline

2. **Log Analysis**
   - Track slow query patterns
   - Monitor "no_results" fallback rate

3. **Synthetic Monitoring**
   - Run quick smoke test hourly
   - Alert on degradation

## Files

```
scripts/load/
├── chatbot-api-loadtest.js   # Main k6 test script
├── run-loadtest.sh           # Convenience runner
└── README.md                 # This file

reports/load/
├── .gitignore                # Excludes report files from git
├── loadtest-*.json           # JSON metrics (generated)
└── loadtest-*.md             # Markdown reports (generated)
```

## Advanced Usage

### Custom k6 Options

```bash
# Run with different VU count
k6 run scripts/load/chatbot-api-loadtest.js --vus 10 --duration 60s

# Output to InfluxDB for Grafana dashboards
k6 run scripts/load/chatbot-api-loadtest.js \
  --out influxdb=http://localhost:8086/k6

# Run specific scenario only
k6 run scripts/load/chatbot-api-loadtest.js \
  --scenario high_concurrency
```

### CI/CD Integration

```yaml
# .github/workflows/load-test.yml
load-test:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4
    - uses: grafana/k6-action@v0.3.1
      with:
        filename: scripts/load/chatbot-api-loadtest.js
      env:
        BASE_URL: ${{ secrets.STAGING_URL }}
```
