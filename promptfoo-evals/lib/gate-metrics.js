const fs = require('node:fs');

const { parseStructuredError, renderAssistantOutput } = require('./ilas-live-shared');

function loadJsonFile(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function readPromptfooResults(resultsInput) {
  if (typeof resultsInput === 'string') {
    return loadJsonFile(resultsInput);
  }
  return resultsInput || {};
}

function getPromptMetrics(resultsInput) {
  const data = readPromptfooResults(resultsInput);
  const prompts = data?.results?.prompts;
  return Array.isArray(prompts) ? prompts : [];
}

function getResultRows(resultsInput) {
  const data = readPromptfooResults(resultsInput);
  const rows = data?.results?.results || data?.results || [];
  return Array.isArray(rows) ? rows : [];
}

function roundRate(rate) {
  return Number(rate.toFixed(1));
}

function parseResultsPassRate(resultsInput) {
  const rows = getResultRows(resultsInput);
  const total = rows.length;
  const passed = rows.filter((row) => row && row.success).length;
  const rate = total > 0 ? roundRate((100 * passed) / total) : 0;

  return { rate, total, passed };
}

function findStructuredError(resultsInput) {
  const rows = getResultRows(resultsInput);
  const sources = [];

  for (const row of rows) {
    sources.push(
      row?.error,
      row?.response?.error,
      row?.failureReason,
      row?.gradingResult?.reason
    );
  }

  for (const value of sources) {
    const parsed = parseStructuredError(value);
    if (parsed) {
      return parsed;
    }
  }

  return null;
}

function summarizeNamedMetric(resultsInput, metricName) {
  let score = 0;
  let count = 0;

  for (const prompt of getPromptMetrics(resultsInput)) {
    const namedScores = prompt?.metrics?.namedScores || {};
    const namedCounts = prompt?.metrics?.namedScoresCount || {};
    if (!Object.prototype.hasOwnProperty.call(namedCounts, metricName)) {
      continue;
    }

    score += Number(namedScores[metricName] || 0);
    count += Number(namedCounts[metricName] || 0);
  }

  const rate = count > 0 ? roundRate((score * 100) / count) : 0;
  return { metricName, rate, score, count };
}

function evaluateMetricThreshold(resultsInput, metricName, options = {}) {
  const threshold = Number(options.threshold ?? 0);
  const minCount = Number(options.minCount ?? 0);
  const summary = summarizeNamedMetric(resultsInput, metricName);
  const countFail = !Number.isFinite(summary.count) || summary.count < minCount;
  const fail =
    countFail ||
    !Number.isFinite(summary.rate) ||
    summary.rate < threshold;

  return {
    ...summary,
    threshold,
    minCount,
    countFail,
    fail,
  };
}

function evaluateMetricSet(resultsInput, metricNames, options = {}) {
  const metrics = metricNames.map((metricName) =>
    evaluateMetricThreshold(resultsInput, metricName, options)
  );

  return {
    threshold: Number(options.threshold ?? 0),
    minCount: Number(options.minCount ?? 0),
    fail: metrics.some((metric) => metric.fail),
    metrics,
  };
}

function parseContractMetaLine(output) {
  const line = String(output || '')
    .split(/\r?\n/)
    .find((candidate) => candidate.startsWith('[contract_meta]'));

  if (!line) {
    return null;
  }

  try {
    return JSON.parse(line.slice('[contract_meta]'.length));
  } catch (_) {
    return null;
  }
}

function renderAssistantFixture(fixturePath, siteBaseUrl) {
  const payload = loadJsonFile(fixturePath);
  const output = renderAssistantOutput(payload, siteBaseUrl);
  const contractMeta = parseContractMetaLine(output);

  return {
    output,
    hasContractMetaLine: Boolean(contractMeta),
    contractMeta,
  };
}

module.exports = {
  evaluateMetricSet,
  evaluateMetricThreshold,
  findStructuredError,
  getPromptMetrics,
  getResultRows,
  parseContractMetaLine,
  parseResultsPassRate,
  readPromptfooResults,
  renderAssistantFixture,
  summarizeNamedMetric,
};
