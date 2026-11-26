# Pack-Out Spot Partner

Community stewardship for places that need attention, on your own WordPress site.

[Pack-Out Spot](https://packout.spot) is how a community looks after places: someone reports a Spot that needs attention, an organization plans a mission to clean it, and the result is recorded. packout.spot is the hub. This plugin runs the same work on a partner's own site, keeps the partner's records in the partner's database, and can connect the site to the hub so the work is shown there too.

> **Version 1.0.** It has the application (Spots, organizations and teams, campaigns, missions, programs, the calendar, impact, accounts, and the dashboard screens), the connection to packout.spot, which is off until an administrator turns it on, the Spot exchange for a connected site, the setup that makes a site's pages, the field app, joint campaigns with other partners, and the report of a program or a campaign. [CHANGELOG.md](CHANGELOG.md) says what each version has, and the [partner guide](https://packout.spot/partner-guide/) is for the people who run a site.

## Requirements

| | |
| --- | --- |
| WordPress | 6.8 or later. One site; not network-activated. |
| PHP | 8.2 or later, with the sodium extension |
| Other plugins | None |
| Theme | Any |

It cannot run on a site that runs Pack-Out Spot, the hub's own plugin.

## Install

Download the zip from [packout.spot/plugins/packout-partner](https://packout.spot/plugins/packout-partner) or from this repository's [releases](https://github.com/campdads/packout-partner/releases), then upload it under Plugins, Add New Plugin, Upload Plugin.

Do not install from a clone of this repository's source unless you are working on the plugin. A release package is the checked build.

## Updates

The plugin is published from the catalog on packout.spot. Its `Update URI` header tells WordPress to ask the plugin, not WordPress.org, and the plugin asks the catalog.

| Step | What happens |
| --- | --- |
| Check | WordPress checks about twice a day. The plugin posts its file name, its version, and the site's channel to `https://packout.spot/wp-json/vectra/v1/manifest`. The request carries the site's address, as every WordPress update check does. |
| Offer | The catalog answers with a version, a package address, a SHA-256, and an Ed25519 signature. |
| Verify | Before WordPress unpacks anything, the plugin downloads the package and checks the SHA-256 and the signature against the public keys in `lib/packout-core/src/class-release.php`. A package that fails is deleted and the update stops with a reason. |

A site that ticks "Get beta versions" in Settings, Pack-Out Spot is offered versions such as `1.0.0-beta.1`. Other sites are offered stable versions only.

## How it is built

| Path | Holds |
| --- | --- |
| `packout-partner.php` | The plugin header, the version, and the phase the plugin has reached |
| `src/` | The plugin's own code: start-up, installing, settings, the addresses of records, the report form, and its rows in Site Health |
| `src/adapters/` | What the shared library needs from a site, supplied with WordPress alone |
| `src/handlers.php` | The code that performs each act of the capability list |
| `lib/packout-core/` | A copy of the [shared library](https://github.com/campdads/packout-core), at the version `packout-core.lock` names. It holds the application. Never edited here. |
| `lib/dompdf/` | [Dompdf](https://github.com/dompdf/dompdf) 3.1.6 and what it needs, for the PDF report. Never edited here. |
| `languages/` | The templates a translation starts from: the plugin's own text and the library's. `bin/make-pot.sh` writes them. |
| `uninstall.php` | What deleting the plugin removes, when the site asked for that: everything the plugin made, by exact name |
| `tests/` | Checks that run inside WordPress: the plugin's own |
| `tests/core/` | The application's checks, copied from the library with it. The hub runs the same files. Never edited here. |
| `ci/` | The WordPress stack the checks run in |
| `bin/` | Build and check tools |

### Parity with the hub

The plugin is meant to do what packout.spot does, apart from a short list of acts that belong to one side for a stated reason. That list is [`capabilities.php`](https://github.com/campdads/packout-core/blob/main/docs/capabilities.md) in the shared library. `tests/skeleton.php` runs the library's parity check: it fails when an act this plugin should have by now has no handler in `src/handlers.php`.

### The tests

| File | Checks |
| --- | --- |
| `tests/skeleton.php` | The plugin starts, carries the library it names, names nothing outside WordPress, and has code for every act it should |
| `tests/updates.php` | An update is offered, verified, and refused when its hash or signature is wrong |
| `tests/site.php` | What a partner site is: what is installed, the one partner, the acts that are refused, the addresses |
| `tests/report.php` | The report form, sent over HTTP as a visitor, as a script, and as a person who is signed in |
| `tests/journey.php` | One person's way through: signing up, an organization, a team, a program, a campaign, a mission, a Spot, impact, totals |
| `tests/commands.php` | `wp packout`, each command run as a process of its own against a stand-in for the hub, the plugin's rows in Site Health, and that a translation is found and loaded |
| `tests/uninstall.php` | Deleting the plugin: nothing goes while the box is unticked, and with it ticked everything the plugin made goes and nothing else does, including a point-of-sale plugin's `pos_` things |
| `tests/core/phase-*.php` | The application, as the library checks it on both sides |

## Working on it

You need Docker, PHP 8.2 or later, and Composer.

```sh
composer install                          # the coding-standard tools
composer lint                             # WordPress coding standards
composer check                            # versions, the library copy, and standards
ci/run.sh                                 # every test, inside WordPress 6.8 on PHP 8.2
ci/run.sh wordpress:7.1.5-php8.4-apache wordpress:cli-2.12.0-php8.4
php bin/build.php                         # the package, in dist/
```

`ci/run.sh` starts a database and a WordPress site in Docker, installs the plugin, runs `tests/`, and removes the containers. It keeps nothing: the database and the site are in memory.

| Task | How |
| --- | --- |
| Take a new version of the library | In a clean checkout of the library at that tag: `php bin/export.php /path/to/packout-partner`. Commit `lib/packout-core/`, `tests/core/`, and `packout-core.lock` together. |
| Change text a person reads | Run `bin/make-pot.sh` and commit `languages/`. It needs Docker. |
| Release | Set the version in `packout-partner.php` (header and constant), `readme.txt`, and `CHANGELOG.md`. Run `bin/make-pot.sh`. Merge. Tag `v{version}`. |

## Releases

A tag `v1.2.3` or `v1.2.3-beta.1` runs every check, builds the package, and makes a GitHub release with the package and its SHA-256. A tag that does not match the plugin's version stops.

The build is reproducible. Check out a tag, run `php bin/build.php`, and the SHA-256 it prints is the one the release carries.

This repository holds no signing key and no credential. Pack-Out Spot's release pipeline takes the package from the GitHub release and installs it on a test site beside a test hub, twice: on a new site, and on a site that is connected on an earlier released version and updates. Only then does it sign the package and store it in the catalog on packout.spot.

## Contributing and security

[CONTRIBUTING.md](CONTRIBUTING.md) says how to propose a change. Report a security problem privately, as [SECURITY.md](SECURITY.md) describes.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
