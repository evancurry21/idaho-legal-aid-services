#!/usr/bin/env node
/**
 * Post-build CSS source-map hygiene for production builds.
 *
 * Why: Laravel Mix's hidden-source-map devtool only suppresses JS
 * sourceMappingURL comments — sass-loader / mini-css-extract emit
 * the CSS sourceMappingURL comment independently. Sentry's release
 * pipeline (scripts/observability/sentry-release.sh) only needs JS
 * source maps for stack-trace symbolication; CSS source maps are
 * unused by Sentry and exposing them publicly leaks source structure.
 *
 * What this does (production only):
 *   1. Strips the trailing /*# sourceMappingURL=... *\/ comment from
 *      every css/*.css file that contains one.
 *   2. Deletes the corresponding css/*.css.map files.
 *
 * Idempotent. Safe to run repeatedly.
 */

const fs = require('fs');
const path = require('path');

const cssDir = path.resolve(__dirname, '..', 'css');

if (!fs.existsSync(cssDir)) {
  console.error(`strip-css-sourcemaps: ${cssDir} not found`);
  process.exit(0);
}

const SOURCE_MAP_COMMENT = /\n?\s*\/\*#\s*sourceMappingURL=[^*]+\*\/\s*$/;
let strippedCount = 0;
let deletedMaps = 0;

for (const entry of fs.readdirSync(cssDir)) {
  const full = path.join(cssDir, entry);
  if (entry.endsWith('.css')) {
    const original = fs.readFileSync(full, 'utf8');
    if (SOURCE_MAP_COMMENT.test(original)) {
      // Preserve a single trailing newline so the file remains POSIX-friendly
      // even though CSS itself does not require it.
      const cleaned = original.replace(SOURCE_MAP_COMMENT, '\n');
      fs.writeFileSync(full, cleaned);
      strippedCount += 1;
    }
  } else if (entry.endsWith('.css.map')) {
    fs.unlinkSync(full);
    deletedMaps += 1;
  }
}

console.log(
  `strip-css-sourcemaps: stripped ${strippedCount} sourceMappingURL ` +
  `comment(s), deleted ${deletedMaps} .css.map file(s).`
);
