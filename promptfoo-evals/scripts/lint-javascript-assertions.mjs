#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import yaml from 'js-yaml';

const repoRoot = process.cwd();
const evalsRoot = path.join(repoRoot, 'promptfoo-evals');
const testsDir = path.join(repoRoot, 'promptfoo-evals', 'tests');
const require = createRequire(import.meta.url);

function listYamlFiles(explicitArgs) {
  if (explicitArgs.length > 0) {
    return explicitArgs.map((arg) => path.isAbsolute(arg) ? arg : path.join(repoRoot, arg));
  }

  return fs.readdirSync(testsDir)
    .filter((name) => name.endsWith('.yaml') || name.endsWith('.yml'))
    .map((name) => path.join(testsDir, name));
}

function isMultiline(text) {
  return String(text || '').includes('\n');
}

function hasExplicitReturn(jsCode) {
  const lines = String(jsCode || '')
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '' && !line.startsWith('//'));

  return lines.some((line) => /\breturn\b/.test(line));
}

function hasInlineReturn(jsCode) {
  return /\breturn\b/.test(String(jsCode || ''));
}

function isCharEscaped(str, index) {
  let backslashCount = 0;
  let i = index - 1;
  while (i >= 0 && str[i] === '\\') {
    backslashCount++;
    i--;
  }
  return backslashCount % 2 === 1;
}

function findLastStatementSemicolon(code) {
  let inSingleQuote = false;
  let inDoubleQuote = false;
  let inTemplate = false;
  let lastSemiIndex = -1;

  for (let i = 0; i < code.length; i++) {
    const char = code[i];

    if (!isCharEscaped(code, i)) {
      if (char === '\'' && !inDoubleQuote && !inTemplate) {
        inSingleQuote = !inSingleQuote;
      }
      else if (char === '"' && !inSingleQuote && !inTemplate) {
        inDoubleQuote = !inDoubleQuote;
      }
      else if (char === '`' && !inSingleQuote && !inDoubleQuote) {
        inTemplate = !inTemplate;
      }
    }

    if (char === ';' && !inSingleQuote && !inDoubleQuote && !inTemplate) {
      lastSemiIndex = i;
    }
  }

  return lastSemiIndex;
}

function buildFunctionBody(jsCode) {
  const trimmed = String(jsCode || '').trim().replace(/;+\s*$/, '');
  if (/^(const|let|var)\s/.test(trimmed)) {
    const lastSemiIndex = findLastStatementSemicolon(trimmed);
    if (lastSemiIndex !== -1) {
      const statements = trimmed.slice(0, lastSemiIndex + 1);
      const expression = trimmed.slice(lastSemiIndex + 1).trim();
      if (expression !== '') {
        return `${statements} return ${expression}`;
      }
    }
    return trimmed;
  }
  return `return ${trimmed}`;
}

function compileAssertion(jsCode, multiline) {
  try {
    if (multiline) {
      // Promptfoo multiline javascript assertions are evaluated as function body.
      // eslint-disable-next-line no-new-func
      new Function('output', 'context', String(jsCode || ''));
      return null;
    }

    // Promptfoo compiles single-line assertions into a function body and injects
    // return where needed; mirror that behavior for syntax checks.
    // eslint-disable-next-line no-new-func
    new Function('output', 'context', buildFunctionBody(jsCode));
    return null;
  }
  catch (error) {
    return String(error?.message || error || 'Unknown syntax error');
  }
}

function parseExternalJavascriptAssertion(value) {
  const text = String(value || '').trim();
  if (!text.startsWith('file://')) {
    return null;
  }

  const ref = text.slice('file://'.length);
  const match = ref.match(/^(.*\.(?:cjs|js|mjs|ts))(?:\:([\w.]+))?$/);
  if (!match) {
    return {
      filePath: null,
      functionName: null,
      error: 'External javascript assertion must be file://path/to/assertion.js:functionName',
    };
  }

  return {
    filePath: path.isAbsolute(match[1]) ? match[1] : path.join(evalsRoot, match[1]),
    functionName: match[2] || null,
    error: null,
  };
}

function validateExternalJavascriptAssertion(value) {
  const parsed = parseExternalJavascriptAssertion(value);
  if (!parsed) {
    return null;
  }
  if (parsed.error) {
    return parsed.error;
  }
  if (!fs.existsSync(parsed.filePath)) {
    return `External javascript assertion file does not exist: ${path.relative(repoRoot, parsed.filePath)}`;
  }
  if (!parsed.functionName) {
    return null;
  }

  try {
    const moduleExports = require(parsed.filePath);
    const exported = parsed.functionName.split('.').reduce((current, key) => current?.[key], moduleExports);
    return typeof exported === 'function'
      ? null
      : `External javascript assertion function is not exported: ${parsed.functionName}`;
  }
  catch (error) {
    return `External javascript assertion could not be loaded: ${error?.message || error}`;
  }
}

function lintFile(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  const parsed = yaml.load(content);
  const tests = Array.isArray(parsed) ? parsed : [];
  const errors = [];

  tests.forEach((test, testIndex) => {
    const asserts = Array.isArray(test?.assert) ? test.assert : [];
    asserts.forEach((assertion, assertIndex) => {
      if ((assertion?.type || '').toLowerCase() !== 'javascript') {
        return;
      }

      const value = String(assertion?.value || '');
      const externalError = validateExternalJavascriptAssertion(value);
      if (externalError !== null) {
        errors.push({
          file: path.relative(repoRoot, filePath),
          test_index: testIndex,
          assert_index: assertIndex,
          description: test?.description || null,
          metric: assertion?.metric || null,
          message: externalError,
        });
        return;
      }
      if (parseExternalJavascriptAssertion(value)) {
        return;
      }

      const multiline = isMultiline(value);

      if (multiline && !hasExplicitReturn(value)) {
        errors.push({
          file: path.relative(repoRoot, filePath),
          test_index: testIndex,
          assert_index: assertIndex,
          description: test?.description || null,
          metric: assertion?.metric || null,
          message: 'Multiline javascript assertion must contain an explicit return statement.',
        });
      }

      if (!multiline && hasInlineReturn(value)) {
        errors.push({
          file: path.relative(repoRoot, filePath),
          test_index: testIndex,
          assert_index: assertIndex,
          description: test?.description || null,
          metric: assertion?.metric || null,
          message: 'Single-line javascript assertion must be a pure expression and must not contain return.',
        });
      }

      const syntaxError = compileAssertion(value, multiline);
      if (syntaxError !== null) {
        errors.push({
          file: path.relative(repoRoot, filePath),
          test_index: testIndex,
          assert_index: assertIndex,
          description: test?.description || null,
          metric: assertion?.metric || null,
          message: `Javascript assertion has invalid syntax: ${syntaxError}`,
        });
      }
    });
  });

  return errors;
}

const args = process.argv.slice(2);
const yamlFiles = listYamlFiles(args);
const allErrors = yamlFiles.flatMap((filePath) => lintFile(filePath));

if (allErrors.length > 0) {
  console.error('JavaScript assertion lint failed:\n');
  for (const error of allErrors) {
    console.error(`${error.file} [test ${error.test_index}, assert ${error.assert_index}] metric=${error.metric || 'n/a'} ${error.description ? `desc="${error.description}"` : ''}`.trim());
    console.error(`  ${error.message}`);
  }
  process.exit(1);
}

console.log(`JavaScript assertion lint passed (${yamlFiles.length} files).`);
