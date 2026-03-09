#!/usr/bin/env node

const { discoverNodeExtraCaCerts } = require('../lib/node-ca-discovery');

function usage() {
  console.error('Usage: node promptfoo-evals/scripts/discover-node-extra-ca-certs.js --assistant-url <url>');
}

let assistantUrl = '';
for (let i = 2; i < process.argv.length; i++) {
  const arg = process.argv[i];
  if (arg === '--assistant-url') {
    assistantUrl = process.argv[i + 1] || '';
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

if (!assistantUrl) {
  usage();
  process.exit(2);
}

const result = discoverNodeExtraCaCerts({ assistantUrl });
process.stdout.write(`${JSON.stringify(result)}\n`);
process.exit(result.ok ? 0 : 1);
