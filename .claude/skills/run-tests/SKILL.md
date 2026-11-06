---
name: run-tests
description: Run the full verification suite of the card-to-card plugin (PHPUnit, PHPCS, PHPStan, asset build, integration tests in WordPress, HTTP tests, optional Playwright e2e) and report the results honestly. Use after any code change, before saying a change is done, or when asked to test or check the plugin.
---

# Run the test suite

Run these in order and stop to fix (or report) the first real failure. Long steps can run in the
background.

1. **Static and unit** (host PHP, no WordPress):
   ```bash
   composer test
   composer lint      # PHPCS / WordPress standard. phpcbf (composer fix) for whitespace only, then re-check
   composer analyse   # PHPStan level 6
   ```
   Only fix lint/analyse errors in files you touched. Pre-existing ones in files you did not change go in
   the report and stay as they are.

2. **Build** when `admin-src/`, `assets/css/payment.css` or `assets/js/payment.js` changed:
   ```bash
   npm run build
   ```

3. **Integration** (needs the dev site, see `/dev-site`):
   ```bash
   bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/dev/seed.php
   bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/run.php 2>/dev/null | tail -30
   bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/modules.php 2>/dev/null | tail -30
   ```
   Each prints a pass count and the failing assertions (`✗ name → {expected, actual}`).

4. **HTTP** (real requests against localhost:8890):
   ```bash
   bash tests/integration/http.sh
   ```

5. **Browser e2e** (slow, writes `docs/screenshots/`): run only when the payment page, checkout or panel
   UI changed, or when asked.
   ```bash
   node tests/e2e/flow.mjs
   ```
   It overwrites the README screenshots. Check `git status docs/screenshots` afterwards and keep only
   intended changes.

## Report

List each step as passed / failed / skipped with the numbers (e.g. "run.php 212 passed, 0 failed"), quote
failing assertions exactly, and say which steps were not run and why. Never report a step you did not run as
passing.

Other sessions may be editing this checkout at the same time. If a failure is in code you did not touch,
check `git diff` / file times before "fixing" it, and say so in the report.
