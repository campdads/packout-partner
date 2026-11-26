#!/usr/bin/env bash
#
# Write the translation templates: languages/packout-partner.pot for the
# plugin's own words, and languages/packout-core.pot for the words of the
# library it carries, which are most of what a person reads.
#
#   bin/make-pot.sh
#
# WP-CLI reads the PHP and the JavaScript, in a container, so nothing is
# installed. Run it before a release, after the library has been exported
# into lib/packout-core, and commit what it writes.
#
# A translator makes packout-partner-<locale>.mo and packout-core-<locale>.mo
# from these, and `wp i18n make-json` for the words in the scripts, and puts
# them in the plugin's languages directory or in wp-content/languages/plugins.

set -euo pipefail

cd "$(dirname "$0")/.."
image="${POT_CLI_IMAGE:-wordpress:cli-2.12.0-php8.2}"
mkdir -p languages

pot() {
	docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp --volume "$PWD:/plugin" --workdir /plugin --entrypoint php "$image" -d memory_limit=1G /usr/local/bin/wp i18n make-pot "$@" --skip-audit --quiet
}

pot . languages/packout-partner.pot --domain=packout-partner --slug=packout-partner --exclude=lib,vendor,tests,ci,build,bin
# The libraries the plugin carries whole (the map, the chart, the PDF writer) have no words of the plugin's.
pot lib/packout-core languages/packout-core.pot --domain=packout-core --slug=packout-core --package-name="Pack-Out Spot library" --exclude=assets/lib,assets/js/chart.umd.min.js,lib,vendor

for file in languages/packout-partner.pot languages/packout-core.pot; do
	# The date changes with every run and says nothing a translator needs.
	sed -i.bak '/^"POT-Creation-Date: /d' "$file" && rm -f "$file.bak"
	echo "${file}: $(grep -c '^msgid ' "$file") strings"
done
