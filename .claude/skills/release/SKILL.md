---
name: release
description: Prepare a new version of the card-to-card plugin — bump the version everywhere, update the changelog, build assets, run the checks and produce dist/card-to-card-<version>.zip. Use when asked to release, bump the version, build the zip or package the plugin.
---

# Release

1. Pick the version (semver). Update **all** of these to the same value:
   - `card-to-card.php`: the `Version:` header and `define( 'CTC_VERSION', '…' )`;
   - `readme.txt`: `Stable tag:`, plus a new `= x.y.z =` entry under `== Changelog ==` (in Persian, one
     bullet per user-visible change);
   - `package.json`: `"version"`.
   `CTC_VERSION` is the cache-buster for every asset and part of the rewrite-rule version, so a bump also
   re-flushes rewrite rules once on every site.
2. If WordPress / WooCommerce versions were tested, update `Tested up to` (readme.txt) and
   `WC tested up to` (plugin header).
3. Run `/run-tests`. Do not package with failures.
4. Build the zip:
   ```bash
   bash bin/zip.sh          # runs npm run build, writes dist/card-to-card-<version>.zip
   unzip -l dist/card-to-card-*.zip | head -40
   ```
   Check the zip has `card-to-card/card-to-card.php`, `includes/`, `templates/`, `assets/admin`,
   `assets/banks`, `assets/fonts`, the `.min` assets, `languages/`, and no `node_modules`, `vendor`,
   `admin-src`, `tests`, `dev`, `.claude` or `CLAUDE.md`. A new runtime folder must be added to `bin/zip.sh`.
5. For the RTL-Theme (راست‌چین) marketplace use the user's `rtl-license` skill on top of this build.
6. Commit only when the user asks ("Release x.y.z"). Tagging and pushing need their go-ahead.
