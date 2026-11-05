#!/usr/bin/env bash
# Local test site helper.
#   bin/dev.sh up      start and (first time) install WordPress + WooCommerce + Storefront
#   bin/dev.sh wp …    run WP-CLI
#   bin/dev.sh down    stop
set -euo pipefail
cd "$(dirname "$0")/../dev"
PORT="${CTC_PORT:-8890}"
wp() { docker compose run --rm -T cli wp "$@"; }

case "${1:-up}" in
	up)
		docker compose up -d
		until docker compose run --rm -T cli wp db check >/dev/null 2>&1; do sleep 2; done
		if ! wp core is-installed >/dev/null 2>&1; then
			wp core install --url="http://localhost:${PORT}" --title="فروشگاه آزمایشی" --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
			wp rewrite structure '/%postname%/' --hard
			wp plugin install woocommerce --activate
			wp theme install storefront twentytwentyfive
			wp theme activate storefront
		fi
		wp plugin activate card-to-card
		echo "→ http://localhost:${PORT}/wp-admin  (admin / password)"
		;;
	wp) shift; wp "$@" ;;
	down) docker compose down ;;
	*) echo "usage: bin/dev.sh up|wp|down"; exit 1 ;;
esac
