---
name: dev-site
description: Start, seed and drive the local Docker WordPress + WooCommerce test site of this plugin (localhost:8890), run WP-CLI in it, and create a card-to-card test order with its private payment link. Use before any manual check, integration test or browser test of the plugin.
---

# Dev site

The site is `dev/docker-compose.yml`: WordPress (php 8.3) + MariaDB + a WP-CLI container, with this repo
bind-mounted at `wp-content/plugins/card-to-card` (PHP edits are live, no copy step).

## Start / check

```bash
docker ps --format '{{.Names}}' | grep card-to-card-wordpress || npm run env   # = bash bin/dev.sh up
bash bin/dev.sh wp plugin list --status=active --fields=name,version
```

- URL `http://localhost:8890` (port override: `CTC_PORT`). Admin `admin` / `password` (local dev values
  from `bin/dev.sh`; never reuse them anywhere else).
- Theme: Storefront (classic). Twenty Twenty-Five is installed for block-theme checks:
  `bash bin/dev.sh wp theme activate twentytwentyfive`. Switch back to `storefront` afterwards.
- Permalinks `/%postname%/`. The payment page is `/<slug>/<id>-<key>/` (default slug `card-pay`).

## Seed demo data (cards + SMS in log mode)

```bash
bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/dev/seed.php
```

## Create a test order and get its payment link

```bash
bash bin/dev.sh wp eval-file wp-content/plugins/card-to-card/tests/integration/make-order.php | tail -1
# → "<order id> <payment url>"   (guest order, phone 09123456789, total 1,463,000)
```

To test the My Account flow you need an order that belongs to a customer:

```bash
# Once: a local test customer (dev-only credentials, same as the admin's).
bash bin/dev.sh wp user create ctc-customer ctc-customer@example.test --role=customer --user_pass=password 2>/dev/null | tail -1
# Give an order (id from make-order.php) to that customer and print its My Account URL:
bash bin/dev.sh wp eval '$o = wc_get_order( 123 ); $o->set_customer_id( get_user_by( "login", "ctc-customer" )->ID ); $o->save(); echo $o->get_view_order_url();' | tail -1
```

Log in as `ctc-customer` in the browser pane (`/my-account/`). The Orders list is `/my-account/orders/`.

## Useful WP-CLI

```bash
bash bin/dev.sh wp wc shop_order get <id> --field=status --user=admin
bash bin/dev.sh wp eval 'echo \CardToCard\Orders::url( wc_get_order( <id> ) );'
bash bin/dev.sh wp eval 'print_r( \CardToCard\Settings::all() );'
bash bin/dev.sh wp eval '\CardToCard\Settings::save( array( "account_action" => true ) );'
bash bin/dev.sh wp action-scheduler run --group=card-to-card       # run queued SMS / deadline jobs now
bash bin/dev.sh wp option get ctc_sms_log --format=json            # SMS «log» driver output
bash bin/dev.sh wp eval 'echo file_get_contents( WP_CONTENT_DIR . "/debug.log" );' | tail -50
```

The CLI prints `Container … Creating/Created` lines on stderr. Use `| tail -1` or `2>/dev/null` when you
need only the value.

## Stop

```bash
bash bin/dev.sh down
```
