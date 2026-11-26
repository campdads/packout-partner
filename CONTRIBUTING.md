# Contributing

Thank you for looking. This plugin runs on other people's sites, so a change is judged first by what it does to a site that installs it.

## Before you start

- For a bug, open an issue with what you did, what you expected, and what happened.
- For something the plugin should do and does not, open an issue first. What a person can do is agreed in the [capability list](https://github.com/campdads/packout-core/blob/main/docs/capabilities.md), which this plugin shares with packout.spot, and a new act starts there.
- For a security problem, do not open an issue. See [SECURITY.md](SECURITY.md).

## Making a change

1. Fork the repository and make a branch.
2. `composer install`
3. Make the change. Add or change a check in `tests/` that fails without it.
4. `composer check` and `ci/run.sh` must pass.
5. Open a pull request that says what changes for a person using the plugin, and how you checked it.

Every pull request runs the same checks on GitHub, on the oldest WordPress and PHP the plugin supports and on the newest. They need no secret, so they run on a fork.

## What a change must keep

- **WordPress alone.** The plugin needs no other plugin and no particular theme. It does not call Vectra.
- **`lib/packout-core/` is a copy.** Do not edit it. A change to shared code goes to [the library](https://github.com/campdads/packout-core) and arrives here as a new version.
- **Parity.** An act the capability list gives this plugin has a handler in `src/handlers.php`. An act that belongs to the hub alone has none.
- **A site's records are the site's.** Deactivating removes nothing. Deleting removes nothing unless the site asked.
- **Nothing leaves the site unannounced.** Any request the plugin sends to another server is described in `readme.txt`.
- **Text is translatable**, with the text domain `packout-partner`.
- **Output is escaped and input is checked**, as the WordPress coding standards require. Every form has a nonce and a capability check.

## Style

WordPress coding standards, checked by `composer lint`. Tabs for indentation. Every class, method, and file has a doc comment that says what it is for.

## Versions

The maintainers set the version and tag releases. Do not change the version in a pull request.

## License

By contributing you agree that your contribution is licensed under GPL-2.0-or-later, the license of this repository.
