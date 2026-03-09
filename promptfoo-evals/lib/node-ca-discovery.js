const childProcess = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

function uniqueExistingPaths(entries, fsImpl = fs) {
  const seen = new Set();
  const output = [];

  for (const entry of entries) {
    if (!entry?.path) {
      continue;
    }
    const candidate = path.normalize(String(entry.path).trim());
    if (!candidate || seen.has(candidate)) {
      continue;
    }
    if (!fsImpl.existsSync(candidate)) {
      continue;
    }
    seen.add(candidate);
    output.push({
      path: candidate,
      source: entry.source || 'unknown',
    });
  }

  return output;
}

function getMkcertCaroot(execFileSync = childProcess.execFileSync) {
  try {
    const output = execFileSync('mkcert', ['-CAROOT'], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
    return output || '';
  } catch (_) {
    return '';
  }
}

function getWindowsMkcertRoots(fsImpl = fs) {
  const usersRoot = '/mnt/c/Users';
  if (!fsImpl.existsSync(usersRoot)) {
    return [];
  }

  try {
    return fsImpl.readdirSync(usersRoot, { withFileTypes: true })
      .filter((entry) => entry.isDirectory())
      .map((entry) => path.join(usersRoot, entry.name, 'AppData', 'Local', 'mkcert', 'rootCA.pem'))
      .filter((candidate) => fsImpl.existsSync(candidate));
  } catch (_) {
    return [];
  }
}

function listNodeExtraCaCandidates({
  env = process.env,
  homeDir = os.homedir(),
  mkcertCaroot = '',
  windowsRoots = getWindowsMkcertRoots(),
  fsImpl = fs,
} = {}) {
  const entries = [];

  if (env.NODE_EXTRA_CA_CERTS) {
    entries.push({
      path: env.NODE_EXTRA_CA_CERTS,
      source: 'env',
    });
  }

  if (mkcertCaroot) {
    entries.push({
      path: path.join(mkcertCaroot, 'rootCA.pem'),
      source: 'mkcert_caroot',
    });
  }

  entries.push({
    path: path.join(homeDir, '.local', 'share', 'mkcert', 'rootCA.pem'),
    source: 'known_local',
  });
  entries.push({
    path: path.join(homeDir, 'AppData', 'Local', 'mkcert', 'rootCA.pem'),
    source: 'known_local',
  });

  windowsRoots.forEach((candidate) => {
    entries.push({
      path: candidate,
      source: 'windows_mkcert',
    });
  });

  return uniqueExistingPaths(entries, fsImpl);
}

function validateNodeExtraCaCandidate(candidatePath, assistantUrl, runner = childProcess.spawnSync) {
  const bootstrapUrl = new URL('/assistant/api/session/bootstrap', assistantUrl).toString();
  const probe = [
    "fetch(process.env.ILAS_TLS_TEST_URL, { headers: { Accept: 'text/plain' } })",
    "  .then(async (response) => {",
    "    if (!response.ok) {",
    "      console.error(`HTTP ${response.status}`);",
    "      process.exit(1);",
    "    }",
    "    const token = (await response.text()).trim();",
    "    if (!token) {",
    "      console.error('EMPTY_TOKEN');",
    "      process.exit(1);",
    "    }",
    "    process.stdout.write('ok');",
    "  })",
    "  .catch((error) => {",
    "    console.error(error && error.stack ? error.stack : String(error));",
    "    process.exit(1);",
    "  });",
  ].join('\n');

  const result = runner(process.execPath, ['-e', probe], {
    env: {
      ...process.env,
      NODE_EXTRA_CA_CERTS: candidatePath,
      ILAS_TLS_TEST_URL: bootstrapUrl,
    },
    encoding: 'utf8',
  });

  if (result.status === 0) {
    return { ok: true };
  }

  return {
    ok: false,
    reason: (result.stderr || result.stdout || '').trim(),
  };
}

function discoverNodeExtraCaCerts({
  assistantUrl,
  env = process.env,
  homeDir = os.homedir(),
  execFileSync = childProcess.execFileSync,
  validateCandidate = validateNodeExtraCaCandidate,
  fsImpl = fs,
} = {}) {
  const mkcertCaroot = getMkcertCaroot(execFileSync);
  const candidates = listNodeExtraCaCandidates({
    env,
    homeDir,
    mkcertCaroot,
    windowsRoots: getWindowsMkcertRoots(fsImpl),
    fsImpl,
  });

  for (const candidate of candidates) {
    const result = validateCandidate(candidate.path, assistantUrl);
    if (result.ok) {
      return {
        ok: true,
        path: candidate.path,
        source: candidate.source,
      };
    }
  }

  return {
    ok: false,
    code: 'tls_untrusted',
    candidates,
  };
}

module.exports = {
  discoverNodeExtraCaCerts,
  getMkcertCaroot,
  getWindowsMkcertRoots,
  listNodeExtraCaCandidates,
  uniqueExistingPaths,
  validateNodeExtraCaCandidate,
};
