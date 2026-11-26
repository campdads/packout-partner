#!/usr/bin/env bash
#
# Run every check that needs WordPress.
#
#   ci/run.sh [wordpress image] [wp-cli image]
#
# With no arguments it uses the oldest WordPress and PHP the plugin supports.
# It starts a database and a site in Docker, installs WordPress, activates the
# plugin, runs tests/*.php and tests/core/*.php through WP-CLI, tries the
# activations that must be refused, deletes the plugin both ways, and removes
# the containers. The database
# and the site are in memory, so nothing is kept and no volume is made.
#
# tests/core holds the application's checks, which the library exports to
# each plugin. Some of the tests ask the site for a page over HTTP, from
# inside its container.
#
# Set PACKOUT_REQUIRE_TAGGED_CORE=1 to require that lib/packout-core is a
# tagged version of the library. A release does.
#
# Set CI_PLUGIN_DIR to the directory of an unpacked package to check the
# package instead of this checkout. It must be an absolute path.

set -euo pipefail

cd "$(dirname "$0")"

export CI_WORDPRESS_IMAGE="${1:-wordpress:6.8.3-php8.2-apache}"
export CI_CLI_IMAGE="${2:-wordpress:cli-2.12.0-php8.2}"

# Passwords for this run only. They are never printed.
random() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32 || true; }
CI_DB_PASSWORD="$(random)"
CI_DB_ROOT_PASSWORD="$(random)"
admin_password="$(random)"
export CI_DB_PASSWORD CI_DB_ROOT_PASSWORD

failed=0
plugin=packout-partner
tagged="${PACKOUT_REQUIRE_TAGGED_CORE:-0}"

scratch="$(mktemp -d)"
: > "$scratch/all"

# WP-CLI in the site's container. What it writes to standard error is shown
# and also kept, so a PHP warning from any command fails the run.
wp() {
	local status=0
	docker compose exec -T -u 33:33 -e "PACKOUT_REQUIRE_TAGGED_CORE=${tagged}" wordpress wp "$@" 2> "$scratch/last" || status=$?
	cat "$scratch/last" >> "$scratch/all"
	cat "$scratch/last" >&2
	return "$status"
}
pass() { echo "  ok    $1"; }
fail() { echo "  FAIL  $1"; failed=1; }

finish() {
	status=$?
	if [ "$status" -ne 0 ] || [ "$failed" -ne 0 ]; then
		echo "--- the site's log ---"
		docker compose logs --no-color --tail 40 wordpress 2>/dev/null || true
	fi
	docker compose down --remove-orphans > /dev/null 2>&1 || true
	rm -rf "$scratch"
	if [ "$status" -eq 0 ] && [ "$failed" -ne 0 ]; then
		exit 1
	fi
	exit "$status"
}
trap finish EXIT

echo "WordPress: ${CI_WORDPRESS_IMAGE}"
echo "Plugin:    ${CI_PLUGIN_DIR:-this checkout}"
if ! docker compose up --detach --build --quiet-pull > "$scratch/up" 2>&1; then
	cat "$scratch/up"
	exit 1
fi

echo "Waiting for WordPress"
for _ in $(seq 1 60); do
	if docker compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then
		break
	fi
	sleep 2
done
docker compose exec -T wordpress test -f /var/www/html/wp-config.php

# The image's first start leaves the plugins directory owned by root, because
# Docker made it to mount the plugin into.
docker compose exec -T -u 0 wordpress chown 33:33 /var/www/html/wp-content /var/www/html/wp-content/plugins

wp core install --url=http://localhost --title="Partner checks" --admin_user=checks --admin_email=checks@example.com --admin_password="$admin_password" --skip-email > /dev/null
# A record's page has an address of its own, which needs pretty permalinks.
wp rewrite structure '/%postname%/' > /dev/null
docker compose exec -T -u 33:33 wordpress mkdir -p /var/www/html/wp-content/uploads
echo "WordPress $(wp core version), PHP $(wp eval 'echo PHP_VERSION;')"

# Run one test file. A PHP warning or notice from the plugin fails it, and so
# does a script that ends without saying it passed.
run_test() {
	local file="$1" output status=0
	shift
	echo
	echo "== tests/${file}${1:+ $1}"
	output="$(wp eval-file "wp-content/packout-partner-tests/${file}" "$@" 2>&1)" || status=$?
	echo "$output"
	if [ "$status" -ne 0 ]; then
		fail "tests/${file} failed"
	elif ! grep -q '^Success: [0-9]* checks passed\.$' <<< "$output"; then
		fail "tests/${file} ended without a result"
	elif grep -q '^  FAIL ' <<< "$output"; then
		fail "tests/${file} has a check that failed"
	elif grep -Eq '(Warning|Notice|Deprecated|Fatal error|Parse error):' <<< "$output"; then
		fail "tests/${file} raised a PHP warning or notice"
	fi
}

echo
echo "== Activation"
if wp plugin activate "$plugin" > /dev/null 2>&1 && wp plugin is-active "$plugin"; then
	pass "the plugin activates on a single site"
else
	fail "the plugin activates on a single site"
fi

for file in skeleton.php updates.php site.php report.php journey.php commands.php field.php; do
	run_test "$file"
done

# The application's checks, in the order of their numbers. The names are read first: a command run inside the loop
# would otherwise read the rest of the list as its input.
core_tests="$(cd ../tests/core && ls phase-*.php | sort -t- -k2 -n)"
for file in $core_tests; do
	run_test "core/${file}"
done

echo
echo "== A page of the site, with the plugin active"
page="$(docker compose exec -T wordpress curl --silent --show-error --location --max-time 30 --write-out '\n%{http_code}' http://localhost/ 2>&1 || true)"
if [ "$(tail -n 1 <<< "$page")" = "200" ] && ! grep -Eq '(Warning|Notice|Deprecated|Fatal error|Parse error)</b>:' <<< "$page"; then
	pass "the home page loads with no PHP warning"
else
	fail "the home page loads with no PHP warning"
fi

echo
echo "== Beside Pack-Out Spot"
# A stand-in for the hub's plugin: the one constant this plugin looks for.
wp plugin deactivate "$plugin" > /dev/null
docker compose exec -T -u 33:33 wordpress sh -c 'mkdir -p wp-content/plugins/packout-spot && cat > wp-content/plugins/packout-spot/packout-spot.php' <<'PHP'
<?php
/**
 * Plugin Name: Pack-Out Spot
 * Description: A stand-in for the hub's plugin, for the partner plugin's checks.
 * Version: 0.0.0
 */

define( 'PACKOUT_SPOT_VERSION', '0.0.0' );
PHP
wp plugin activate packout-spot > /dev/null
output="$(wp plugin activate "$plugin" 2>&1)" && status=0 || status=$?
if [ "$status" -ne 0 ] && grep -q 'cannot run together' <<< "$output" && ! wp plugin is-active "$plugin"; then
	pass "activation beside Pack-Out Spot is refused, with the reason"
else
	fail "activation beside Pack-Out Spot is refused, with the reason"
	echo "$output"
fi

# Both active anyway, as when the hub's plugin is installed second.
wp plugin deactivate packout-spot > /dev/null
wp plugin activate "$plugin" > /dev/null
wp plugin activate packout-spot > /dev/null
paused="$(wp eval 'echo PackOut\Partner\Plugin::paused(), "|", class_exists( "PackOut\\Core\\Kernel", false ) ? "library loaded" : "library not loaded";' 2>&1)"
if [ "$paused" = "hub|library not loaded" ]; then
	pass "with both active the plugin pauses and loads nothing"
else
	fail "with both active the plugin pauses and loads nothing"
	echo "$paused"
fi
notice="$(wp eval 'wp_set_current_user( 1 ); ob_start(); do_action( "admin_notices" ); echo ob_get_clean();' 2>&1)"
if grep -q 'notice-warning.*cannot run together' <<< "$notice"; then
	pass "and tells an administrator why"
else
	fail "and tells an administrator why"
fi
wp plugin deactivate packout-spot "$plugin" > /dev/null
docker compose exec -T -u 33:33 wordpress rm -rf wp-content/plugins/packout-spot

echo
echo "== Deleting the plugin"
# tests/uninstall.php makes one of everything the plugin keeps, with things a
# point-of-sale plugin would keep under the same prefix beside it. Then
# uninstall.php runs the way WordPress runs it, first for a site that did not
# ask for its data to be removed and then for one that did. The files are
# mounted read-only, so only what it does to the database and the uploads is
# checked.
delete_plugin() {
	wp eval "define( 'WP_UNINSTALL_PLUGIN', '${plugin}/${plugin}.php' ); include WP_PLUGIN_DIR . '/${plugin}/uninstall.php';" > /dev/null
}
wp plugin activate "$plugin" > /dev/null
run_test uninstall.php seed
wp plugin deactivate "$plugin" > /dev/null
delete_plugin || fail "uninstall.php ran, with the box not ticked"
run_test uninstall.php kept
delete_plugin || fail "uninstall.php ran, with the box ticked"
run_test uninstall.php removed

echo
echo "== A network"
wp core multisite-convert > /dev/null 2>&1
output="$(wp plugin activate "$plugin" --network 2>&1)" && status=0 || status=$?
if [ "$status" -ne 0 ] && grep -q 'whole network' <<< "$output" && ! wp plugin is-active-for-network "$plugin" 2>/dev/null; then
	pass "activation for a whole network is refused, with the reason"
else
	fail "activation for a whole network is refused, with the reason"
	echo "$output"
fi
if wp plugin activate "$plugin" > /dev/null 2>&1 && wp plugin is-active "$plugin"; then
	pass "activation on one site of a network is allowed"
else
	fail "activation on one site of a network is allowed"
fi
run_test skeleton.php

echo
echo "== PHP warnings"
# A complaint about a file inside WP-CLI is WP-CLI's, not the plugin's: its
# bundled libraries are older than PHP 8.4. One about code WP-CLI was given
# to run names "eval()'d code" after the file, and still counts.
complaints="$(grep -E '(Warning|Notice|Deprecated|Fatal error|Parse error):' "$scratch/all" | grep -Ev ' in phar://[^ ]+\.php on line [0-9]+' | sort -u || true)"
if [ -n "$complaints" ]; then
	fail "no command raised a PHP warning or notice"
	cut -c1-300 <<< "$complaints"
else
	pass "no command raised a PHP warning or notice"
fi
# What a page raised while a test asked for it is in the site's log.
logged="$(docker compose exec -T wordpress sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true' | grep -E 'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)' | grep -Ev ' in phar://[^ ]+\.php on line [0-9]+' | sed 's/^\[[^]]*\] //' | sort -u || true)"
if [ -n "$logged" ]; then
	fail "no page raised a PHP warning or notice"
	head -20 <<< "$logged" | cut -c1-300
else
	pass "no page raised a PHP warning or notice"
fi

echo
if [ "$failed" -ne 0 ]; then
	echo "Some checks failed."
else
	echo "Every check passed."
fi
