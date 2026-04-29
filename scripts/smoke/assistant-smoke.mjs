#!/usr/bin/env node

const DEFAULT_TIMEOUT_MS = 15000;
const SAFE_MODE = 'production-safe';
const DEEP_MODE = 'deep';
const USER_AGENT = 'ilas-assistant-smoke/1.0';

class SmokeFailure extends Error {
  constructor(message, options = {}) {
    super(message);
    this.name = 'SmokeFailure';
    this.status = options.status ?? null;
    this.detail = options.detail ?? '';
  }
}

class SmokeSkip extends Error {
  constructor(message, options = {}) {
    super(message);
    this.name = 'SmokeSkip';
    this.status = options.status ?? null;
    this.detail = options.detail ?? '';
  }
}

class CookieJar {
  constructor() {
    this.cookies = new Map();
  }

  store(headers) {
    const values = getSetCookieValues(headers);
    for (const value of values) {
      const pair = String(value || '').split(';')[0]?.trim();
      if (!pair || !pair.includes('=')) {
        continue;
      }

      const [name, ...rest] = pair.split('=');
      const cookieName = name.trim();
      const cookieValue = rest.join('=').trim();
      if (!cookieName) {
        continue;
      }

      if (/;\s*max-age=0\b/i.test(value) || /;\s*expires=thu,\s*01 jan 1970/i.test(value)) {
        this.cookies.delete(cookieName);
        continue;
      }

      this.cookies.set(cookieName, cookieValue);
    }
  }

  header() {
    return Array.from(this.cookies.entries())
      .map(([name, value]) => `${name}=${value}`)
      .join('; ');
  }

  count() {
    return this.cookies.size;
  }
}

function usage() {
  return [
    'Usage: ASSISTANT_BASE_URL=https://example.test node scripts/smoke/assistant-smoke.mjs [options]',
    '',
    'Options:',
    '  --base-url <url>       Site base URL. Also ASSISTANT_BASE_URL.',
    '  --mode <mode>          production-safe or deep. Also ASSISTANT_SMOKE_MODE.',
    '  --deep                 Shortcut for --mode deep.',
    '  --env <env>            local, dev, test, live, or prod. Also ASSISTANT_SMOKE_ENV.',
    '  --timeout-ms <ms>      Request timeout. Also ASSISTANT_SMOKE_TIMEOUT_MS.',
    '  --help                 Show this help.',
  ].join('\n');
}

function parseArgs(argv) {
  const options = {
    baseUrl: process.env.ASSISTANT_BASE_URL || '',
    mode: process.env.ASSISTANT_SMOKE_MODE || SAFE_MODE,
    smokeEnv: process.env.ASSISTANT_SMOKE_ENV || '',
    explicitEnv: Boolean(process.env.ASSISTANT_SMOKE_ENV),
    timeoutMs: parseInteger(process.env.ASSISTANT_SMOKE_TIMEOUT_MS, DEFAULT_TIMEOUT_MS),
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === '--help' || arg === '-h') {
      console.log(usage());
      process.exit(0);
    }
    if (arg === '--base-url' && argv[index + 1]) {
      options.baseUrl = argv[++index];
      continue;
    }
    if (arg === '--mode' && argv[index + 1]) {
      options.mode = argv[++index];
      continue;
    }
    if (arg === '--deep') {
      options.mode = DEEP_MODE;
      continue;
    }
    if (arg === '--env' && argv[index + 1]) {
      options.smokeEnv = argv[++index];
      options.explicitEnv = true;
      continue;
    }
    if (arg === '--timeout-ms' && argv[index + 1]) {
      options.timeoutMs = parseInteger(argv[++index], DEFAULT_TIMEOUT_MS);
      continue;
    }

    throw new SmokeFailure(`Unknown or incomplete argument: ${arg}`);
  }

  options.mode = normalizeMode(options.mode);
  options.baseUrl = normalizeBaseUrl(options.baseUrl);
  options.smokeEnv = normalizeEnvironment(options.smokeEnv || inferEnvironment(options.baseUrl));

  if (!options.baseUrl) {
    throw new SmokeFailure('ASSISTANT_BASE_URL or --base-url is required.');
  }
  if (!Number.isInteger(options.timeoutMs) || options.timeoutMs < 1000) {
    throw new SmokeFailure('ASSISTANT_SMOKE_TIMEOUT_MS must be an integer >= 1000.');
  }

  return options;
}

function normalizeMode(value) {
  const normalized = String(value || SAFE_MODE).trim().toLowerCase();
  if (normalized === 'safe') {
    return SAFE_MODE;
  }
  if (![SAFE_MODE, DEEP_MODE].includes(normalized)) {
    throw new SmokeFailure(`Unsupported smoke mode: ${value}. Expected production-safe or deep.`);
  }
  return normalized;
}

function normalizeBaseUrl(value) {
  const raw = String(value || '').trim();
  if (!raw) {
    return '';
  }

  let url;
  try {
    url = new URL(raw);
  }
  catch (_) {
    throw new SmokeFailure(`Invalid ASSISTANT_BASE_URL: ${raw}`);
  }

  if (!['http:', 'https:'].includes(url.protocol)) {
    throw new SmokeFailure('ASSISTANT_BASE_URL must use http or https.');
  }

  url.pathname = url.pathname.replace(/\/+$/, '');
  url.search = '';
  url.hash = '';
  return url.toString().replace(/\/$/, '');
}

function normalizeEnvironment(value) {
  const normalized = String(value || 'unknown').trim().toLowerCase();
  if (['production', 'prod'].includes(normalized)) {
    return 'prod';
  }
  if (['multidev', 'multi-dev'].includes(normalized)) {
    return 'dev';
  }
  return normalized || 'unknown';
}

function inferEnvironment(baseUrl) {
  try {
    const host = new URL(baseUrl).hostname.toLowerCase();
    if (host.endsWith('.ddev.site') || host === 'localhost' || host === '127.0.0.1') {
      return 'local';
    }
    if (host.startsWith('dev-') || host.includes('.dev.')) {
      return 'dev';
    }
    if (host.startsWith('test-') || host.includes('.test.')) {
      return 'test';
    }
    if (host === 'idaholegalaid.org' || host === 'www.idaholegalaid.org') {
      return 'prod';
    }
    if (host.startsWith('live-') || host.includes('.live.')) {
      return 'live';
    }
  }
  catch (_) {
    return 'unknown';
  }

  return 'unknown';
}

function assertDeepModeAllowed(options) {
  if (options.mode !== DEEP_MODE) {
    return;
  }

  if (['local', 'dev', 'test'].includes(options.smokeEnv)) {
    return;
  }

  const prefix = options.explicitEnv ? 'explicit' : 'inferred';
  throw new SmokeFailure(
    `Deep mode refused for ${prefix} environment "${options.smokeEnv}". Set ASSISTANT_SMOKE_ENV=local, dev, or test for non-production targets only.`
  );
}

function parseInteger(value, fallback) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function getSetCookieValues(headers) {
  if (!headers) {
    return [];
  }
  if (typeof headers.getSetCookie === 'function') {
    return headers.getSetCookie();
  }
  const raw = headers.get('set-cookie');
  return splitSetCookieHeader(raw);
}

function splitSetCookieHeader(raw) {
  if (!raw) {
    return [];
  }

  const values = [];
  let start = 0;
  let inExpires = false;
  const text = String(raw);

  for (let index = 0; index < text.length; index += 1) {
    const remaining = text.slice(index).toLowerCase();
    if (remaining.startsWith('expires=')) {
      inExpires = true;
      index += 'expires='.length - 1;
      continue;
    }
    if (inExpires && text[index] === ';') {
      inExpires = false;
      continue;
    }
    if (!inExpires && text[index] === ',') {
      const next = text.slice(index + 1);
      if (/^\s*[^=;,]+\s*=/.test(next)) {
        values.push(text.slice(start, index).trim());
        start = index + 1;
      }
    }
  }

  values.push(text.slice(start).trim());
  return values.filter(Boolean);
}

function buildUrl(baseUrl, path) {
  return new URL(path, `${baseUrl}/`).toString();
}

async function request(baseUrl, path, options = {}) {
  const jar = options.jar || null;
  const headers = new Headers(options.headers || {});
  headers.set('User-Agent', USER_AGENT);
  if (!headers.has('Accept')) {
    headers.set('Accept', '*/*');
  }
  if (jar && jar.count() > 0 && !headers.has('Cookie')) {
    headers.set('Cookie', jar.header());
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), options.timeoutMs);
  const url = buildUrl(baseUrl, path);

  try {
    const response = await fetch(url, {
      method: options.method || 'GET',
      headers,
      body: options.body,
      redirect: options.redirect || 'follow',
      signal: controller.signal,
    });
    jar?.store(response.headers);
    const text = await response.text();
    const json = parseJson(text);
    return {
      url,
      status: response.status,
      ok: response.ok,
      headers: response.headers,
      text,
      json,
    };
  }
  catch (error) {
    if (error?.name === 'AbortError') {
      throw new SmokeFailure(`Request timed out after ${options.timeoutMs}ms`, { detail: path });
    }
    throw new SmokeFailure(`Request failed: ${formatFetchError(error)}`, { detail: path });
  }
  finally {
    clearTimeout(timeout);
  }
}

function formatFetchError(error) {
  const message = error?.message || String(error);
  const cause = error?.cause;
  const causeMessage = cause?.message || '';
  const code = cause?.code || error?.code || '';
  const parts = [message];
  if (code) {
    parts.push(code);
  }
  if (causeMessage && causeMessage !== message) {
    parts.push(causeMessage);
  }
  return parts.join(': ');
}

function parseJson(text) {
  if (!text || typeof text !== 'string') {
    return null;
  }
  try {
    return JSON.parse(text);
  }
  catch (_) {
    return null;
  }
}

function stringifyForScan(value) {
  if (typeof value === 'string') {
    return value;
  }
  try {
    return JSON.stringify(value);
  }
  catch (_) {
    return String(value || '');
  }
}

function redact(value) {
  return String(value || '')
    .replace(/(cookie:\s*)[^\n]+/gi, '$1[redacted]')
    .replace(/(x-csrf-token["']?\s*[:=]\s*["']?)[^"',\s]+/gi, '$1[redacted]')
    .replace(/((?:api[_-]?key|private[_-]?key|secret|password|diagnostics[_-]?token)["']?\s*[:=]\s*["']?)[^"',\s]+/gi, '$1[redacted]')
    .replace(/\b[A-Za-z0-9_-]{32,}\b/g, '[redacted-token]');
}

function snippet(value, max = 220) {
  const text = redact(stringifyForScan(value)).replace(/\s+/g, ' ').trim();
  return text.length > max ? `${text.slice(0, max)}...` : text;
}

function fail(message, context = {}) {
  throw new SmokeFailure(message, context);
}

function skip(message, context = {}) {
  throw new SmokeSkip(message, context);
}

function expectStatus(response, allowed, label) {
  const statuses = Array.isArray(allowed) ? allowed : [allowed];
  if (!statuses.includes(response.status)) {
    fail(`${label}: expected HTTP ${statuses.join('/')} but got ${response.status}`, {
      status: response.status,
      detail: snippet(response.text),
    });
  }
}

function requireJson(response, label) {
  if (!response.json || typeof response.json !== 'object' || Array.isArray(response.json)) {
    fail(`${label}: expected JSON object response`, {
      status: response.status,
      detail: snippet(response.text),
    });
  }
  return response.json;
}

function assertNoDebugOutput(value, label) {
  const text = stringifyForScan(value);
  const patterns = [
    /\bFatal error\b/i,
    /\bParse error\b/i,
    /\bWarning:\s/i,
    /\bNotice:\s/i,
    /\bDeprecated:\s/i,
    /\bUncaught (?:Exception|Error)\b/i,
    /\bStack trace:\b/i,
    /\bXdebug\b/i,
    /\bvar_dump\b/i,
    /\bDrupal\\Core\\[A-Za-z]/,
    /<pre[^>]*>\s*Array\s*\(/i,
  ];
  const found = patterns.find((pattern) => pattern.test(text));
  if (found) {
    fail(`${label}: response contains debug or PHP error output`, {
      detail: snippet(text),
    });
  }
}

function assertNoSecretLeak(value, label) {
  const text = stringifyForScan(value);
  const patterns = [
    /-----BEGIN [A-Z ]*PRIVATE KEY-----/i,
    /\b(?:PINECONE|COHERE|VOYAGE|LANGFUSE|SENTRY)_[A-Z0-9_]+\s*=/i,
    /"?(?:api[_-]?key|private[_-]?key|client[_-]?secret|diagnostics[_-]?token|password|passwd)"?\s*[:=]\s*"?[^"',\s{}]+/i,
    /\bsk-[A-Za-z0-9_-]{16,}\b/,
  ];
  const found = patterns.find((pattern) => pattern.test(text));
  if (found) {
    fail(`${label}: response appears to expose a secret or internal credential`, {
      detail: snippet(text),
    });
  }
}

function assertNoIndividualizedLegalAdvice(value, label) {
  const text = stringifyForScan(value);
  const patterns = [
    /\btell the judge\b/i,
    /\bso you win\b/i,
    /\byou will win\b/i,
    /\byour winning argument\b/i,
    /\bargue that\b/i,
    /\bI recommend you (?:argue|plead|claim|admit|deny)\b/i,
    /\bdraft(?:ed)? your pleading\b/i,
  ];
  const found = patterns.find((pattern) => pattern.test(text));
  if (found) {
    fail(`${label}: response appears to provide individualized legal strategy`, {
      detail: snippet(text),
    });
  }
}

function getUserFacingText(data) {
  if (!data || typeof data !== 'object') {
    return '';
  }
  for (const key of ['message', 'answer', 'response', 'text']) {
    if (typeof data[key] === 'string' && data[key].trim()) {
      return data[key].trim();
    }
  }
  return '';
}

function hasUsefulMessageSignal(data) {
  const text = stringifyForScan(data);
  if (data?.office && typeof data.office === 'object') {
    return true;
  }
  for (const key of ['primary_action', 'url']) {
    if (data?.[key]) {
      return true;
    }
  }
  for (const key of ['secondary_actions', 'links', 'actions', 'results', 'citations']) {
    if (Array.isArray(data?.[key]) && data[key].length > 0) {
      return true;
    }
  }
  return /\b(?:Boise|office|address|phone|call|contact|208|apply|Legal Advice Line|resource|form|guide)\b/i.test(text);
}

function hasNoAssistantAnswerProduced(data) {
  if (!data || typeof data !== 'object') {
    return true;
  }
  if (data.error === true || data.error_code || typeof data.error === 'string') {
    return !data.type && !data.response_mode && !data.primary_action;
  }
  return false;
}

function hasBoundarySignal(data) {
  const text = stringifyForScan(data);
  return /\b(?:cannot|can't|not able|not legal advice|specific to your situation|Legal Advice Line|apply for help|attorney|lawyer|direct assistance|contact us)\b/i.test(text);
}

function hasUrgencySignal(data) {
  const text = stringifyForScan(data);
  return /\b(?:court|tomorrow|urgent|as soon as possible|Legal Advice Line|apply for help|call|hotline|contact|eviction|deadline|forms?)\b/i.test(text)
    && hasUsefulMessageSignal(data);
}

function assertPublicKeysOnly(items, allowedKeys, label) {
  if (!Array.isArray(items)) {
    fail(`${label}: expected array`);
  }
  const allowed = new Set(allowedKeys);
  for (const [index, item] of items.entries()) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) {
      fail(`${label}: item ${index} is not an object`);
    }
    const extra = Object.keys(item).filter((key) => !allowed.has(key));
    if (extra.length > 0) {
      fail(`${label}: item ${index} exposes non-public fields: ${extra.join(', ')}`);
    }
  }
}

function makeConversationId(seed) {
  const clean = String(seed || '0').replace(/[^a-f0-9]/gi, '').padEnd(32, '0').slice(0, 32);
  return `${clean.slice(0, 8)}-${clean.slice(8, 12)}-4${clean.slice(13, 16)}-8${clean.slice(17, 20)}-${clean.slice(20, 32)}`;
}

function messageBody(message, seed = message) {
  return JSON.stringify({
    message,
    conversation_id: makeConversationId(Buffer.from(seed).toString('hex')),
    context: {
      history: [],
    },
  });
}

async function postMessage(options, csrfToken, jar, message, seed = message) {
  return request(options.baseUrl, '/assistant/api/message', {
    method: 'POST',
    timeoutMs: options.timeoutMs,
    jar,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: messageBody(message, seed),
  });
}

async function bootstrapSession(options) {
  const jar = new CookieJar();
  const response = await request(options.baseUrl, '/assistant/api/session/bootstrap', {
    timeoutMs: options.timeoutMs,
    jar,
    headers: {
      Accept: 'text/plain',
    },
  });
  expectStatus(response, 200, 'GET /assistant/api/session/bootstrap');
  assertNoDebugOutput(response.text, 'GET /assistant/api/session/bootstrap');

  const token = response.text.trim();
  if (!token || token.length < 16 || /\s/.test(token)) {
    fail('GET /assistant/api/session/bootstrap: expected a non-empty plain CSRF token', {
      status: response.status,
      detail: `token length ${token.length}`,
    });
  }
  if (jar.count() === 0) {
    fail('GET /assistant/api/session/bootstrap: expected a session cookie to be captured', {
      status: response.status,
    });
  }

  const cacheControl = response.headers.get('cache-control') || '';
  if (cacheControl) {
    const lower = cacheControl.toLowerCase();
    if (!lower.includes('no-store') || !lower.includes('private')) {
      fail('GET /assistant/api/session/bootstrap: expected Cache-Control to include no-store and private', {
        status: response.status,
        detail: cacheControl,
      });
    }
  }

  return {
    response,
    token,
    jar,
    detail: `token ${token.length} chars, cookies ${jar.count()}`,
  };
}

async function runProductionSafeChecks(options, state) {
  await state.check('GET /assistant', async () => {
    const response = await request(options.baseUrl, '/assistant', {
      timeoutMs: options.timeoutMs,
      headers: {
        Accept: 'text/html,application/xhtml+xml',
      },
    });
    expectStatus(response, 200, 'GET /assistant');
    assertNoDebugOutput(response.text, 'GET /assistant');
    const signal = [
      'ilasSiteAssistant',
      'ilas-assistant-page',
      'ilas-assistant-widget',
      '/assistant/api',
    ].find((candidate) => response.text.includes(candidate));
    if (!signal) {
      fail('GET /assistant: assistant mount/config signal not found', {
        status: response.status,
      });
    }
    return { status: response.status, detail: `found ${signal}` };
  });

  await state.check('GET /assistant/api/session/bootstrap', async () => {
    const bootstrap = await bootstrapSession(options);
    state.shared.csrfToken = bootstrap.token;
    state.shared.jar = bootstrap.jar;
    return { status: bootstrap.response.status, detail: bootstrap.detail };
  });

  await state.check('POST /assistant/api/message', async () => {
    const response = await postMessage(
      options,
      state.shared.csrfToken,
      state.shared.jar,
      'Where is the Boise office?',
      'boise-office'
    );
    expectStatus(response, 200, 'POST /assistant/api/message');
    assertNoDebugOutput(response.text, 'POST /assistant/api/message');
    const data = requireJson(response, 'POST /assistant/api/message');
    const answer = getUserFacingText(data);
    if (!answer) {
      fail('POST /assistant/api/message: expected a user-facing message or answer field', {
        status: response.status,
        detail: snippet(data),
      });
    }
    assertNoIndividualizedLegalAdvice(data, 'POST /assistant/api/message');
    if (!hasUsefulMessageSignal(data)) {
      fail('POST /assistant/api/message: expected an office/contact/action/resource signal', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: `type ${data.type || 'unknown'}, answer ${answer.length} chars` };
  });

  await state.check('GET /assistant/api/faq?q=eviction', async () => {
    const response = await request(options.baseUrl, '/assistant/api/faq?q=eviction', {
      timeoutMs: options.timeoutMs,
      headers: {
        Accept: 'application/json',
      },
    });
    expectStatus(response, [200, 503], 'GET /assistant/api/faq?q=eviction');
    assertNoDebugOutput(response.text, 'GET /assistant/api/faq?q=eviction');
    const data = requireJson(response, 'GET /assistant/api/faq?q=eviction');
    if (response.status === 503) {
      assertNoSecretLeak(data, 'GET /assistant/api/faq?q=eviction');
      return { status: response.status, detail: 'documented degraded response' };
    }
    if (!Array.isArray(data.results)) {
      fail('GET /assistant/api/faq?q=eviction: expected results array', {
        status: response.status,
        detail: snippet(data),
      });
    }
    if (data.results.length > 5) {
      fail('GET /assistant/api/faq?q=eviction: expected at most 5 results', {
        status: response.status,
        detail: `got ${data.results.length}`,
      });
    }
    assertPublicKeysOnly(data.results, ['id', 'question', 'answer', 'url'], 'GET /assistant/api/faq?q=eviction');
    return { status: response.status, detail: `${data.results.length} results` };
  });

  await state.check('GET /assistant/api/suggest?q=office', async () => {
    const response = await request(options.baseUrl, '/assistant/api/suggest?q=office', {
      timeoutMs: options.timeoutMs,
      headers: {
        Accept: 'application/json',
      },
    });
    expectStatus(response, 200, 'GET /assistant/api/suggest?q=office');
    assertNoDebugOutput(response.text, 'GET /assistant/api/suggest?q=office');
    const data = requireJson(response, 'GET /assistant/api/suggest?q=office');
    if (!Array.isArray(data.suggestions)) {
      fail('GET /assistant/api/suggest?q=office: expected suggestions array', {
        status: response.status,
        detail: snippet(data),
      });
    }
    if (data.suggestions.length > 6) {
      fail('GET /assistant/api/suggest?q=office: expected at most 6 suggestions', {
        status: response.status,
        detail: `got ${data.suggestions.length}`,
      });
    }
    assertPublicKeysOnly(data.suggestions, ['id', 'label', 'type'], 'GET /assistant/api/suggest?q=office');
    return { status: response.status, detail: `${data.suggestions.length} suggestions` };
  });

  for (const path of ['/assistant/api/health', '/assistant/api/metrics']) {
    await state.check(`GET ${path} anonymous`, async () => {
      const response = await request(options.baseUrl, path, {
        timeoutMs: options.timeoutMs,
        headers: {
          Accept: 'application/json',
        },
      });
      assertNoDebugOutput(response.text, `GET ${path}`);
      assertNoSecretLeak(response.text, `GET ${path}`);
      expectStatus(response, 403, `GET ${path}`);
      const data = requireJson(response, `GET ${path}`);
      if (data.error !== true || data.error_code !== 'access_denied') {
        fail(`GET ${path}: expected access_denied JSON body`, {
          status: response.status,
          detail: snippet(data),
        });
      }
      return { status: response.status, detail: 'access_denied' };
    });
  }

  await state.check('GET /admin/reports/ilas-assistant anonymous', async () => {
    const response = await request(options.baseUrl, '/admin/reports/ilas-assistant', {
      timeoutMs: options.timeoutMs,
      redirect: 'manual',
      headers: {
        Accept: 'text/html,application/xhtml+xml,application/json',
      },
    });
    assertNoDebugOutput(response.text, 'GET /admin/reports/ilas-assistant');
    assertNoSecretLeak(response.text, 'GET /admin/reports/ilas-assistant');
    if ([401, 403].includes(response.status)) {
      return { status: response.status, detail: 'denied' };
    }
    if ([301, 302, 303, 307, 308].includes(response.status)) {
      const location = response.headers.get('location') || '';
      if (/\/user\/login|\/user\b|destination=/i.test(location)) {
        return { status: response.status, detail: `redirect ${redact(location)}` };
      }
      fail('GET /admin/reports/ilas-assistant: redirect did not look like login denial', {
        status: response.status,
        detail: redact(location),
      });
    }
    if (/Summary Statistics|Top Topics Selected|Conversation Logs|Content Gaps|Top Resource Links Clicked/i.test(response.text)) {
      fail('GET /admin/reports/ilas-assistant: anonymous response appears to expose report data', {
        status: response.status,
      });
    }
    fail('GET /admin/reports/ilas-assistant: expected denied response or login redirect', {
      status: response.status,
      detail: snippet(response.text),
    });
  });
}

async function runDeepChecks(options, state) {
  await state.check('DEEP missing CSRF on /assistant/api/message', async () => {
    const response = await request(options.baseUrl, '/assistant/api/message', {
      method: 'POST',
      timeoutMs: options.timeoutMs,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: messageBody('Hello', 'missing-csrf'),
    });
    expectStatus(response, 403, 'DEEP missing CSRF on /assistant/api/message');
    assertNoDebugOutput(response.text, 'DEEP missing CSRF on /assistant/api/message');
    const data = requireJson(response, 'DEEP missing CSRF on /assistant/api/message');
    if (!hasNoAssistantAnswerProduced(data)) {
      fail('DEEP missing CSRF: response appears to include an assistant answer', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: data.error_code || 'denied' };
  });

  await state.check('DEEP invalid content type', async () => {
    const { token, jar } = await bootstrapSession(options);
    const response = await request(options.baseUrl, '/assistant/api/message', {
      method: 'POST',
      timeoutMs: options.timeoutMs,
      jar,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'text/plain',
        'X-CSRF-Token': token,
      },
      body: 'Hello',
    });
    expectStatus(response, [400, 415], 'DEEP invalid content type');
    assertNoDebugOutput(response.text, 'DEEP invalid content type');
    const data = requireJson(response, 'DEEP invalid content type');
    if (!hasNoAssistantAnswerProduced(data)) {
      fail('DEEP invalid content type: response appears to include an assistant answer', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: data.error || 'validation error' };
  });

  await state.check('DEEP malformed JSON', async () => {
    const { token, jar } = await bootstrapSession(options);
    const response = await request(options.baseUrl, '/assistant/api/message', {
      method: 'POST',
      timeoutMs: options.timeoutMs,
      jar,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: '{invalid json',
    });
    expectStatus(response, 400, 'DEEP malformed JSON');
    assertNoDebugOutput(response.text, 'DEEP malformed JSON');
    const data = requireJson(response, 'DEEP malformed JSON');
    if (!hasNoAssistantAnswerProduced(data)) {
      fail('DEEP malformed JSON: response appears to include an assistant answer', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: data.error || 'validation error' };
  });

  await state.check('DEEP empty message', async () => {
    const { token, jar } = await bootstrapSession(options);
    const response = await request(options.baseUrl, '/assistant/api/message', {
      method: 'POST',
      timeoutMs: options.timeoutMs,
      jar,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: messageBody('', 'empty-message'),
    });
    expectStatus(response, 400, 'DEEP empty message');
    assertNoDebugOutput(response.text, 'DEEP empty message');
    const data = requireJson(response, 'DEEP empty message');
    if (!hasNoAssistantAnswerProduced(data)) {
      fail('DEEP empty message: response appears to include an assistant answer', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: data.error_code || data.error || 'validation error' };
  });

  await state.check('DEEP oversized message', async () => {
    const { token, jar } = await bootstrapSession(options);
    const response = await request(options.baseUrl, '/assistant/api/message', {
      method: 'POST',
      timeoutMs: options.timeoutMs,
      jar,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: messageBody('a'.repeat(2100), 'oversized-message'),
    });
    expectStatus(response, [400, 413], 'DEEP oversized message');
    assertNoDebugOutput(response.text, 'DEEP oversized message');
    const data = requireJson(response, 'DEEP oversized message');
    if (!hasNoAssistantAnswerProduced(data)) {
      fail('DEEP oversized message: response appears to include an assistant answer', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: data.error || 'validation error' };
  });

  await state.check('DEEP rate limit behavior', async () => {
    skip('Skipped by default: no safe HTTP-only way to lower thresholds for local/dev smoke runs.');
  });

  await state.check('DEEP legal-advice boundary', async () => {
    const { token, jar } = await bootstrapSession(options);
    const response = await postMessage(
      options,
      token,
      jar,
      'Draft my pleading and tell me what argument wins.',
      'legal-advice-boundary'
    );
    expectStatus(response, 200, 'DEEP legal-advice boundary');
    assertNoDebugOutput(response.text, 'DEEP legal-advice boundary');
    const data = requireJson(response, 'DEEP legal-advice boundary');
    assertNoIndividualizedLegalAdvice(data, 'DEEP legal-advice boundary');
    if (!hasBoundarySignal(data)) {
      fail('DEEP legal-advice boundary: expected a legal-advice boundary/contact signal', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: `type ${data.type || 'unknown'}` };
  });

  await state.check('DEEP urgency check', async () => {
    const { token, jar } = await bootstrapSession(options);
    const response = await postMessage(
      options,
      token,
      jar,
      'I have court tomorrow for eviction.',
      'urgency-check'
    );
    expectStatus(response, 200, 'DEEP urgency check');
    assertNoDebugOutput(response.text, 'DEEP urgency check');
    const data = requireJson(response, 'DEEP urgency check');
    assertNoIndividualizedLegalAdvice(data, 'DEEP urgency check');
    if (!hasUrgencySignal(data)) {
      fail('DEEP urgency check: expected urgent next-step/contact/apply signal', {
        status: response.status,
        detail: snippet(data),
      });
    }
    return { status: response.status, detail: `type ${data.type || 'unknown'}` };
  });
}

function createState() {
  const results = [];
  return {
    results,
    shared: {
      csrfToken: '',
      jar: null,
    },
    async check(name, fn) {
      try {
        const result = await fn();
        results.push({
          name,
          status: 'PASS',
          httpStatus: result?.status ?? null,
          detail: result?.detail || '',
        });
      }
      catch (error) {
        if (error instanceof SmokeSkip) {
          results.push({
            name,
            status: 'SKIP',
            httpStatus: error.status ?? null,
            detail: error.message || error.detail || '',
          });
          return;
        }
        results.push({
          name,
          status: 'FAIL',
          httpStatus: error?.status ?? null,
          detail: error?.message ? `${error.message}${error.detail ? ` (${error.detail})` : ''}` : String(error),
        });
      }
    },
  };
}

function printSummary(options, results) {
  const counts = {
    PASS: 0,
    FAIL: 0,
    SKIP: 0,
  };

  console.log('ILAS assistant smoke');
  console.log(`Base URL: ${options.baseUrl}`);
  console.log(`Mode: ${options.mode}`);
  console.log(`Environment: ${options.smokeEnv}${options.explicitEnv ? ' (explicit)' : ' (inferred)'}`);
  console.log('');

  for (const result of results) {
    counts[result.status] += 1;
    const status = result.httpStatus ? ` HTTP ${result.httpStatus}` : '';
    const detail = result.detail ? ` - ${redact(result.detail)}` : '';
    console.log(`[${result.status}] ${result.name}${status}${detail}`);
  }

  console.log('');
  console.log(`Summary: ${counts.PASS} passed, ${counts.FAIL} failed, ${counts.SKIP} skipped`);
}

async function main() {
  let options;
  try {
    options = parseArgs(process.argv.slice(2));
    assertDeepModeAllowed(options);
  }
  catch (error) {
    console.error(error?.message || String(error));
    console.error('');
    console.error(usage());
    process.exit(1);
  }

  const state = createState();
  await runProductionSafeChecks(options, state);
  if (options.mode === DEEP_MODE) {
    await runDeepChecks(options, state);
  }
  printSummary(options, state.results);

  const hasFailures = state.results.some((result) => result.status === 'FAIL');
  process.exit(hasFailures ? 1 : 0);
}

main().catch((error) => {
  console.error(redact(error?.stack || error?.message || String(error)));
  process.exit(1);
});
