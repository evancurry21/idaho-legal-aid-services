#!/usr/bin/env node

const { IlasLiveTransport } = require('../lib/ilas-live-shared');

function usage() {
  console.error('Usage: node promptfoo-evals/scripts/preflight-live.js [--question "<text>"]');
}

let question = 'Where is your Boise office?';
for (let i = 2; i < process.argv.length; i++) {
  const arg = process.argv[i];
  if (arg === '--question') {
    question = process.argv[i + 1] || question;
    i++;
    continue;
  }
  if (arg === '-h' || arg === '--help') {
    usage();
    process.exit(0);
  }

  console.error(`Unknown argument: ${arg}`);
  usage();
  process.exit(2);
}

(async () => {
  try {
    const transport = new IlasLiveTransport({
      gateMode: true,
      failFast429: process.env.ILAS_429_FAIL_FAST,
      silent: true,
    });
    const result = await transport.runConnectivityPreflight(question);
    if (!result.ok) {
      process.stdout.write(`${JSON.stringify(result.error)}\n`);
      process.exit(result.error.kind === 'capacity' ? 4 : 3);
    }

    process.stdout.write(`${JSON.stringify(result)}\n`);
    process.exit(0);
  } catch (err) {
    process.stdout.write(
      `${JSON.stringify({
        kind: 'connectivity',
        code: 'preflight_exception',
        message: err?.message || String(err),
      })}\n`
    );
    process.exit(3);
  }
})();
