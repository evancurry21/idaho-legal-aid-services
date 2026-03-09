#!/usr/bin/env node

const {
  evaluateMetricSet,
  findStructuredError,
  parseResultsPassRate,
  renderAssistantFixture,
  summarizeNamedMetric,
} = require('../lib/gate-metrics');

function usage() {
  process.stderr.write(
    'Usage: gate-metrics.js <pass-rate|structured-error|metric-rate|evaluate-thresholds|render-output> ...\n'
  );
}

function requireArg(value, message) {
  if (!value) {
    process.stderr.write(`${message}\n`);
    usage();
    process.exit(2);
  }
}

function formatRate(value) {
  return Number(value || 0).toFixed(1);
}

const [command, ...args] = process.argv.slice(2);

switch (command) {
  case 'pass-rate': {
    const [resultsFile] = args;
    requireArg(resultsFile, 'results file is required');
    const { rate, total, passed } = parseResultsPassRate(resultsFile);
    process.stdout.write(`${formatRate(rate)} ${total} ${passed}\n`);
    break;
  }

  case 'structured-error': {
    const [resultsFile] = args;
    requireArg(resultsFile, 'results file is required');
    const parsed = findStructuredError(resultsFile);
    if (parsed) {
      process.stdout.write(`${parsed.kind} ${parsed.code}\n`);
    } else {
      process.stdout.write('\n');
    }
    break;
  }

  case 'metric-rate': {
    const [resultsFile, metricName] = args;
    requireArg(resultsFile, 'results file is required');
    requireArg(metricName, 'metric name is required');
    const { rate, score, count } = summarizeNamedMetric(resultsFile, metricName);
    process.stdout.write(`${formatRate(rate)} ${score} ${count}\n`);
    break;
  }

  case 'evaluate-thresholds': {
    const [resultsFile, thresholdRaw, minCountRaw, ...metricNames] = args;
    requireArg(resultsFile, 'results file is required');
    requireArg(thresholdRaw, 'threshold is required');
    requireArg(minCountRaw, 'min count is required');
    if (metricNames.length === 0) {
      requireArg('', 'at least one metric name is required');
    }

    const report = evaluateMetricSet(resultsFile, metricNames, {
      threshold: Number(thresholdRaw),
      minCount: Number(minCountRaw),
    });

    for (const metric of report.metrics) {
      process.stdout.write(
        [
          'metric',
          metric.metricName,
          formatRate(metric.rate),
          metric.score,
          metric.count,
          metric.countFail ? 'yes' : 'no',
          metric.fail ? 'yes' : 'no',
        ].join('|') + '\n'
      );
    }

    process.stdout.write(`overall|${report.fail ? 'yes' : 'no'}\n`);
    break;
  }

  case 'render-output': {
    const [fixturePath, siteBaseUrl] = args;
    requireArg(fixturePath, 'fixture path is required');
    const rendered = renderAssistantFixture(fixturePath, siteBaseUrl);
    process.stdout.write(`${JSON.stringify(rendered)}\n`);
    break;
  }

  default:
    usage();
    process.exit(2);
}
