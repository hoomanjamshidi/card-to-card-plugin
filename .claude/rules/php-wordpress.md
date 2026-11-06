---
paths:
  - "includes/**/*.php"
  - "templates/**/*.php"
  - "card-to-card.php"
  - "uninstall.php"
---

# PHP / WordPress rules

## Compatibility
- PHP 8.0 is the minimum: no enums, readonly properties, `never`, first-class callable syntax `f(...)`, or
  `new` in initializers. `str_starts_with` / `str_contains` / `match` / union types are fine.
- WordPress 6.4+ and WooCommerce 8+. Orders may live in HPOS tables: use `wc_get_order()`, `wc_get_orders()`,
  `$order->get_meta()`, `update_meta_data()` + `save()` / `save_meta_data()`. Never use `get_post_meta`,
  `WP_Query` on `shop_order`, or `$post->ID` as an order.
- Call `$order->save()` (or `save_meta_data()`) after `update_meta_data()`. `update_status()` saves the
  order itself and fires `woocommerce_order_status_*`.

## Coding standard (phpcs.xml.dist = WordPress standard)
- Tabs, spaces inside parentheses, Yoda conditions, `array()` (not `[]`) in plugin code, full docblocks
  with `@param` / `@return` (phpstan level 6 needs array shapes like `array<string, mixed>`).
- Globals and hooks are prefixed `ctc_` (functions, options, transients, actions, filters) or namespaced
  `CardToCard`. Template variables are prefixed `$ctc_`.
- Hook callbacks receive loosely typed values from WordPress: accept untyped params and cast inside
  (`(array) $actions`, `$order instanceof \WC_Order`), as the existing code does.
- Strings: `__( '…', 'card-to-card' )` with a `/* translators: */` comment for every placeholder.

## Security (every change)
- Escape at output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`. A `phpcs:ignore` for
  `OutputNotEscaped` is only acceptable for `Util::icon()`, bundled SVG and already escaped builders, and it
  must say why.
- Input: `wp_unslash` then sanitize (`sanitize_text_field`, `sanitize_key`, `absint`, `Persian::latin` /
  `Persian::only_digits` for digits typed in Persian).
- State-changing requests need a nonce **and** a capability check. Admin: `manage_woocommerce` (settings,
  SMS) or `edit_shop_orders` (orders, receipts). The only unauthenticated write is the receipt POST on the
  payment page, which is gated by the HMAC key, a per-order nonce, the honeypot and rate limits.
- The customer payment link must keep working for guests (no login), so never add `is_user_logged_in()` or
  ownership checks to the payment page. Do add an ownership check
  (`get_current_user_id() === $order->get_customer_id()`) anywhere that shows an order based on a
  logged-in user's context instead of the key.
- Redirect with `wp_safe_redirect()` + `exit`. POST handlers redirect with 303 (PRG). Skip the redirect
  when `CTC_TESTING` is defined (integration tests).

## Settings
- New setting: add the default in `Settings::defaults()` (its type is the schema), plus `$choices` /
  `$ranges` in `Settings::sanitize()` when needed, the TS type in `admin-src/lib/types.ts` and a control in
  the panel. Read it with `Settings::get( 'key' )`. It is cached per request, so call `Settings::flush()` in
  tests after writing the option directly.

## Templates
- `templates/*.php` can be overridden by themes (`yourtheme/card-to-card/`). Add new `$args` keys instead of
  renaming or removing them, and read optional keys with `! empty( $args['x'] )` / `?? ''` so an old theme
  copy keeps working.
- Render templates only through `Payment_Page::part()` / `Payment_Page::locate()`.
