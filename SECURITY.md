# Security

## Reporting a vulnerability

Please report it privately. Do not open a public issue or pull request for a security problem.

Use GitHub’s private reporting: on this repository, open the **Security** tab, choose **Advisories**, then **Report a vulnerability**. Only the maintainers see the report.

Say what you found, how to reproduce it, and which version you used. If you have a fix in mind, say so, and leave it out of public view until a release is out.

## What to expect

- An acknowledgement within five working days.
- A fix or a plan, and a date, once the report is confirmed.
- Credit in the release notes, unless you would rather not be named.

## What is in scope

This plugin as released, on a supported WordPress and PHP. In particular:

- anything that lets a visitor or a signed-in person read or change what they should not;
- anything that makes the plugin install a package that Pack-Out Spot did not sign;
- anything the plugin sends to another server that `readme.txt` does not describe.

A problem in the shared library can be reported here or on [its repository](https://github.com/campdads/packout-core). A problem with the packout.spot site itself can be reported here too.

## Supported versions

The latest stable version, and the latest beta while one is newer. A fix is released as a new version through the catalog on packout.spot.

## How releases are protected

Every package is signed with an Ed25519 key that is kept outside this repository. The plugin carries the public key and refuses a package whose SHA-256 or signature does not verify. This repository holds no signing key and no credential, and its workflows use none.
