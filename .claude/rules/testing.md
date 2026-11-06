---
paths:
  - "tests/**"
  - "dev/**"
---

# Testing rules

- `tests/unit/` (PHPUnit, `composer test`) covers only pure helpers in `includes/Support/` (no WordPress
  loaded; `tests/unit/bootstrap.php`).
- `tests/integration/run.php` and `modules.php` run inside the real dev site through WP-CLI
  (`bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/<file>.php`). They define
  `CTC_TESTING` (no redirects/exit) and `CTC_SMS_SYNC` (SMS jobs run inline). They use the tiny
  `ctc_ok()` / `ctc_eq()` helpers and print a pass count plus the failures. Run `dev/seed.php` first.
- Every test creates its own products/orders (`ctc_new_order()`), restores the settings it changed with
  `Settings::save()` and deletes what it created. Do not rely on the order of other sections or leave
  state behind.
- `tests/integration/http.sh` hits the running site with curl (headers, upload, nonce, honeypot, wrong key
  404, direct receipt access blocked). `tests/e2e/flow.mjs` is a Playwright script run with
  `node tests/e2e/flow.mjs` that also writes README screenshots to `docs/screenshots/`.
- SMS in tests: driver `log` (or the mocked Melipayamak HTTP answers already in `run.php`). Never put real
  Melipayamak credentials in tests, seeds or commits.
- A behaviour change in the customer flow (payment page, My Account, e-mails, SMS link) needs an assertion
  in `run.php`, and an HTTP check in `http.sh` when it is about what a browser receives.
