# Contract, version 1

The payloads a partner site sends to the hub: their schemas, and fixtures of each. Both plugins carry these files and check against them, so the two ends cannot drift apart.

| Schema | Sent with | Holds |
| --- | --- | --- |
| `register.json` | `POST /partners/register`, unsigned | The site's address, name, administrator's email address, public key, and the version of the partner terms it accepted |
| `signed.json` | `POST /partners/verify`, `POST /partners/terms`, `POST /partners/disconnect` | The versions, and for those two the terms version or whether to keep the history |
| `key.json` | `POST /partners/key` | A new public key, signed with the old one |
| `profile.json` | `PUT /partners/profile` | What the partner says about itself |
| `listings.json` | `POST /partners/listings` | Up to 200 public records: organizations, teams, programs, campaigns, and missions |
| `stats.json` | `POST /partners/stats` | Up to 200 snapshots: totals for one scope on one day, and for a partner or an organization the figures its overview shows |
| `spots.json` | `POST /partners/spots` | Up to 50 Spots, each in one of three states: `awaiting`, `published`, or `removed` |
| `adoptions.json` | `POST /partners/adoptions` | One thing said about a Spot of the hub's that a mission of the site took on: `adopt`, `start`, `complete`, or `release` |
| `place-requests.json` | `POST /partners/place-requests` | An organization of the site asks to adopt a Place the hub lists |
| `campaigns.json` | `POST /partners/campaigns/{id}/accept`, `/leave`, `/invite`, `/remove` | One thing said about a joint campaign: the site's `organization` that answers an invitation or leaves, or the `participant` a host invites or takes out |
| `claims.json` | `POST /partners/claims` | The site's `organization` and the `address` of the hub organization that is the same one, or `withdraw` to take that back |
| `move.json` | `POST /partners/move` | The site's new address, and a `challenge` the site made |
| `recover.json` | `POST /partners/recover`, unsigned | The `site` id alone to ask for the email, or with a new `public_key` and a `challenge` to finish |

Every payload says its `contract`, its `core_version`, and its `plugin_version`.

A schema lists every field. A field it does not list is a fault, so a person's name or address cannot be sent in a field nobody defined. The figures of an overview are checked as figures: numbers, true or false, a day or a time, under short plain keys. No sentence fits.

A schema whose payload comes in several shapes names the field that says which (`select`) and gives the fields of each shape (`variants`). A Spot's shape follows its `state`, and what is said about an adoption follows its `act`. A field of another shape is a fault, so a Spot awaiting review cannot carry a title.

`fixtures/` holds one payload that fits each schema, and `fixtures/invalid.json` holds payloads that must be refused, each with the path of its first fault. `tests/contract-test.php` checks both.

`PackOut\Core\Contract::fault( $name, $payload )` is the check. It understands the part of JSON Schema these files use and nothing more.

`PACKOUT_CORE_CONTRACT` in `load.php` is the version a site speaks. It changes only when a payload changes shape. The hub accepts its own version and the one before it.

## Sites and hubs of different versions

A site updates when its owner chooses, so the hub always serves sites older than itself. While the contract's number stays the same, two things hold:

- **What an earlier version sent is still accepted.** A field is never taken away or made narrower, and a field that was optional never becomes required.
- **What a version adds is declared.** A new schema, or a new field of an old one, is something a hub older than the site would refuse. So the hub is released first, and a site is offered a version that sends something new only after the hub takes it.

`tests/compat/` holds the schemas and fixtures as each released version carried them, and `tests/compat-test.php` checks both: every earlier version's fixtures against this version's schemas, and this version's fixtures against every earlier version's schemas, where the only faults allowed are the additions the test lists by version. `php bin/freeze-contract.php` keeps the contract as it stands under the version `load.php` names. It is run in the change that alters a schema or a fixture, and it refuses to write over a version that has a tag.

| Version | Added |
| --- | --- |
| 0.7.0 | `register`, `signed`, `key`, `profile`, `listings`, `stats` |
| 0.8.0 | `spots`, `adoptions`, `place-requests` |
| 0.9.0 | `images` in `profile` |
| 0.11.0 | `campaigns`; `joint` on a campaign in `listings` |
| 0.12.0 | `claims`, `move`, `recover` |
| 0.13.1 | `group_type` and `program_type` on a listing |

A change that cannot keep the first rule raises `PACKOUT_CORE_CONTRACT`, and the hub goes on accepting the version before it.

## The routes

All are under `/wp-json/packout/v1/partners` on the hub. The client is `PackOut\Core\App\Connection` and `PackOut\Core\App\Outbox`; the routes are in the hub's plugin.

| Route | Signed | Does |
| --- | --- | --- |
| `GET /terms` | No | The version and address of the partner terms in force, and whether the hub is taking new sites |
| `POST /register` | No | Registers the site. Answers `201` with the `site` id, a `challenge`, and `state: pending` |
| `POST /verify` | Yes | Asks the hub to fetch the challenge from the site now. Answers as `GET /me` |
| `GET /me` | Yes | How the connection stands: `state`, `confirmed`, `proved`, `partner_url`, `minimum_version`, `current_version`, `contract`, `terms_version`, `terms_url`, `terms_required`, `terms_by`, `claims`, `notices`, `now` |
| `POST /terms` | Yes | Accepts the terms in force |
| `PUT /profile` | Yes | The partner's own name, description, region, website, and page |
| `POST /listings` | Yes | A batch of listings. Answers one result for each item |
| `POST /stats` | Yes | A batch of snapshots. Answers one result for each item |
| `POST /spots` | Yes | A batch of Spots. Answers one result for each item, with how the Spot stands on the hub and the photographs the hub wants |
| `GET /spots/index` | Yes | What the hub holds of the site's Spots, 500 to a page: each one's identifier, revision, and state. `after` asks for the next page |
| `POST /images` | Yes | One image, as the whole body. What it is of is in the address |
| `GET /changes` | Yes | What changed on the hub since a time the site names: a Spot hidden, sent back, or shown again, a possible duplicate, an adoption ended or confirmed |
| `POST /adoptions` | Yes | Takes on a Spot of the hub's, starts on it, reports the work, or lets it go |
| `POST /place-requests` | Yes | Asks that an organization adopt a Place |
| `GET /campaigns` | Yes | The joint campaigns that concern the site: the ones its organizations are invited to, take part in, or left, and the ones it hosts |
| `POST /campaigns/{id}/accept`, `/leave` | Yes | Answers an invitation for one of the site's organizations, or leaves. Answers as `GET /campaigns`, with the `result` |
| `POST /campaigns/{id}/invite`, `/remove` | Yes | The site that hosts the campaign invites an organization the hub lists, or takes one out. Answers as `GET /campaigns`, with the `result` |
| `POST /claims` | Yes | Says an organization of the site is the same as one kept on the hub, or takes that back. Answers as `GET /me` |
| `POST /move` | Yes | The site's address changed. The hub asks the site its challenge at the new address before it changes anything. Answers as `GET /me` |
| `POST /recover` | No | Starts, and then finishes, the recovery of a lost key |
| `GET /export` | Yes | Everything the hub holds from the site |
| `POST /key` | Yes, with the old key | Replaces the site's public key |
| `POST /disconnect` | Yes | Ends the connection. `keep` leaves the partner's name and last totals as history, out of public view |

The site answers one route of its own, `GET /wp-json/packout/v1/hub-challenge?site={id}`: the challenge and a signature of it. The hub asks it when the site verifies, when the site says it moved, and when the site finishes recovering a lost key, and tries the `?rest_route=` form when the first does not answer. The first challenge is the hub's. The other two are numbers the site made for that one request: only the site at the address can answer with a number it was just given and a signature of its own key.

## A signed request

Four headers: `X-PackOut-Key` (the site id), `X-PackOut-Timestamp` (seconds), `X-PackOut-Nonce`, and `X-PackOut-Signature`. The signature is Ed25519 over five lines: the method, the path and query as sent, the SHA-256 of the body in hex, the timestamp, and the nonce. `PackOut\Core\Signing` builds and checks it. A request with a body says its `contract`, `core_version`, and `plugin_version` in the body. A `GET` says its `contract` and repeats its `nonce` in the query, in that order, so no two share an address.

The hub checks in this order, and the first that fails is the answer:

| Status | Code | When |
| --- | --- | --- |
| 400 | `https_required` | The request did not come over HTTPS. A site whose environment is `local` is let through |
| 401 | `unknown_site` | No open connection has that id, or the request is not signed |
| 401 | `stale` | The timestamp is more than 300 seconds from the hub's clock. The answer carries `now`, and the site corrects for the difference and tries once more |
| 401 | `replayed` | The nonce was used in the last 600 seconds |
| 401 | `bad_signature` | The signature is not the key's, or the nonce in the query is not the one in the header |
| 429 | `too_many` | More than 120 requests in a minute |
| 403 | `partner_pending`, `partner_suspended`, `partner_disconnected` | The partner's state does not allow what was asked |
| 403 | `terms_required` | The partner terms changed more than 30 days ago and the site has not accepted them. `GET /me`, `GET /notices`, `POST /terms`, `GET /export`, `POST /key`, and `POST /disconnect` are still answered |
| 413 | `too_large` | The body is more than a megabyte |
| 426 | `update_required` | The contract is not one the hub speaks, or the plugin is older than the hub accepts. The answer carries `contract` and `minimum_version`. A request with no body is not held to a plugin version, so an old plugin can still ask `GET /me` which version it needs |
| 422 | `invalid` | The payload does not fit its schema. The answer carries the `path` of the first fault |

Every answer says `Cache-Control: no-store`.

## Listings

Each item has a `kind`, a `remote_id`, a `rev` that rises with every change, and either its fields or `deleted: true`. The answer gives each item one of `created`, `updated`, `unchanged`, `deleted`, or `refused` with a `reason`: `foreign_url` (the address is not on the site's host), `missing_reference` (it names an organization, campaign, or program the hub does not have), `remote_id`, `title`, or `not_saved`. A refused item does not stop the rest of the batch.

A mission names its campaign by the site's own identifier, or by `hub:` and the hub's number when the campaign is a joint one another host keeps. The hub files the mission under that campaign while the mission's organization takes part in it, and keeps it there afterward. A mission whose organization does not take part is listed in no campaign and is not refused. A campaign the site hosts says `joint: open` or `joint: closed` once it is a joint campaign. Who takes part is not in a listing: a field that names participants is a fault.

A full send says `full: true` and carries one `sweep` id across its batches. The batch that says `last: true` ends it, and the hub then removes every listing of that partner the sweep did not name. A full send that is cut short removes nothing.

## Statistics

Each item is the totals of one `scope` (`partner`, `organization`, `team`, `program`, `campaign`, `mission`) for one `day`, with measured amounts apart from self-reported ones, verified hours apart from unverified, and how many records they come from. The answer gives each item `kept`, `flagged` (kept, and held back from public view until a coordinator looks), or `refused` with the reason `day` for a day that has not come.

Work on a Spot the site took on from the hub is in no total the site sends. The hub counts it once, from the record it writes itself.

What the site's organizations did in a joint campaign of another host goes as a `campaign` snapshot whose `remote_id` is `hub:` and the hub's number. The hub refuses it with the reason `campaign` unless the site has, or had, a part in that campaign. `hub:` names a campaign and nothing else.

## Spots

The site decides what to send before it sends. Each item has a `remote_id`, a `rev`, and a `state`:

| State | When | Carries |
| --- | --- | --- |
| `awaiting` | A report no coordinator of the site has reviewed | The category, the day, the hazard, and a point rounded to two decimal places. No title, description, landmark, photograph, or credit |
| `published` | The site's own public sees the Spot | What that public sees: title, address, description, category, stage, hazard, the point and landmark (a rounded point and no landmark when the site shows the Spot approximately), the credit the reporter chose, and a list of the photographs the public sees |
| `removed` | Anything else: kept private, sent back, a duplicate, in the trash, deleted | Nothing |

The answer gives each item `created`, `updated`, `unchanged`, `deleted`, or `refused` with a `reason`. For a Spot the hub holds it also gives `standing` (`shown`, `waiting`, `held`, `hidden`, `returned`, or `linked`), the `reason` a coordinator of the hub gave, `hints` (up to five Spots within reach that may be the same thing, each with a title and an address), and `photos`: the identifiers of the listed photographs the hub does not hold yet.

A revision older than the one the hub holds is not applied. The site sends a Spot when it is saved, and compares what the hub holds with what it should hold once a night, through `GET /spots/index`.

## Images

`POST /images?of=spot&id={remote_id}&photo={id}&about=report|cleanup|visit` sends one photograph of a Spot, and `of=adoption&id={adoption}&photo={id}&about=cleanup` one photograph of the work on a Spot of the hub's. The body is the image, up to 25 megabytes, and the signature covers it. The hub takes only a photograph the Spot or the report listed, and makes its own copy with nothing in it but the picture. It answers `created` or `unchanged`, `404 unknown_spot` or `unknown_adoption`, `409 not_expected`, or `415 not_image`.

The partner's logo and cover go the same way. `PUT /profile` carries `images`, with `logo` and `cover` each a number that changes when the image does, or empty for none. The hub removes the copy of one that is now empty, and answers with `images` naming each one it does not hold, by that number. The site then sends `POST /images?of=listing&id=logo|cover&photo={number}` with the image as the body. The hub takes only the image it asked for, and keeps its own copy with nothing in it but the picture.

## Changes

`GET /changes?since={time}` answers `now`, to ask from next time, and two lists. `spots`: each Spot of the site's whose standing, reason, or hints changed. `adoptions`: each adoption the hub ended (`released`, with the `reason`) or recorded (`done`).

It also answers `campaigns`: a stamp of what `GET /campaigns` would say to the site now. The site asks `GET /campaigns` only when the stamp is not the one it last learned, so a site in no joint campaign asks nothing more.

## Adoptions

A mission of the site takes on a Spot that was reported on the hub. Every request says its `act`:

| Act | Says | The hub |
| --- | --- | --- |
| `adopt` | The hub's `spot`, the `mission` (its `remote_id`, `title`, `url` on the site's host, and days), and the `organization` it is for, when the hub lists it | Answers the adoption's `id`, its `state`, and `spot`: what a visitor to the hub sees of the Spot, and no more. The Spot reads planned and names the crew. Refuses with `not_published`, `adopted`, `on_mission`, `do_not_touch`, `needs_authorization`, `not_open`, `foreign_url`, or `missing_reference` |
| `start` | The adoption's `id` | The Spot reads in progress |
| `complete` | The `id`, the `date`, hours, people, bags and pounds with where each figure came from, what the crew wrote, and the identifiers of up to 12 photographs | Answers the `photos` it wants. The first photograph that arrives marks the Spot cleaned and writes one unverified record, credited to the organization. With no photograph, a coordinator of the hub confirms the report |
| `release` | The `id` and a `reason`: `removed`, `called_off`, or `still_open` | The Spot waits for a crew again |

A request about an adoption that is over changes nothing and answers how it stands. No request names a person.

## Joint campaigns

A joint campaign is one campaign that several organizations take part in. Its host keeps it. The hub keeps who takes part, whoever hosts it.

`GET /campaigns` answers `campaigns`, a `stamp`, and `organizations`. Each campaign has the hub's `id`, its `title`, `description`, `goal`, `host` (a name), `url` (its page on the hub), `joint` (`open` or `closed`), its days and times, and `hosted`:

| `hosted` | Also carries | The site |
| --- | --- | --- |
| `false` | `states`: the site's identifier of each of its organizations that has a part, and how it stands: `invited`, `accepted`, `declined`, or `left` | Shows an invitation to the organization's leads. Once one is accepted it keeps a copy of the campaign that nobody on the site can change, and files that organization's missions under it |
| `true` | `local`: the site's own identifier of the campaign, and `participants`: each organization invited or taking part, with its hub `id`, `name`, `partner`, and `state` | Shows them to the campaign's host |

`organizations` lists the organizations the site could invite, each with its hub `id`, `name`, and `partner`. It is empty unless the site hosts an open joint campaign, and never holds the site's own organizations: their missions are filed on the site.

`accept` and `leave` say the site's `organization`. `leave` on an invitation declines it. `invite` and `remove` say the `participant`, by the hub's `id`, and are the host's alone: the hub answers `403 not_host` to any other site. The hub answers `404 not_found` for a campaign that is not joint or that the site has no part in, `409 missing_reference` for an organization it does not list for the site, and `refused` with a status of 400 or 409 when the campaign's own rules say no: it is closed, the organization is already invited, or there is no invitation to accept.

An organization that leaves, or is taken out, keeps its past missions in the campaign and its figures on the campaign's page, with the day it left. No new mission of it is filed there.

No request and no answer names a person.

## The terms

`terms_required` in `GET /me` is the version in force when the site accepted an older one, and `terms_by` is the time, in seconds, until which the hub goes on taking what the site sends. A site that is told a day goes on sending until that day. A site that is told none stops at once. After the day the hub answers `403 terms_required`, and removes nothing it holds.

## One organization on both sites

`claims` in `GET /me` lists what stands between the site's organizations and the hub's: for each, the site's `organization`, the `state` (`asked`, `accepted`, `declined`, or `ended`), the `day` the organization's work moved, `until` when that was undone, and the `name` and `url` of the hub's organization. The lead of the hub's organization answers, on the hub.

From `day`, the hub leaves its own records of that organization out of its totals, and the site leaves what the organization did before `day` out of every total it sends. The days are the hub's: a record dated on `day` is the site's. Either side may end it. The hub's organization is worked in again from `until`, and the days between stay the site's.

The hub refuses with `unknown_listing` (the site's organization is not listed on the hub yet), `unknown_organization` (no listed, approved organization of the hub's own is at the address), or `already_claimed`.

## A new address

`POST /move` carries the new address and a challenge. The hub refuses an address that is not HTTPS, its own, or another connected site's (`address_taken`), and one it cannot reach (`not_proved`). When the site answers the challenge there, the hub changes the address it holds for the connection, the partner, every listing, and every Spot, and the site sends everything again.

## A lost key

`POST /recover` with the `site` alone always answers `200` with `asked: true`. When the hub knows the site, it emails the address the site registered with: a link that works for a day, to a page with one button. Pressing it opens the recovery for an hour. In that hour, `POST /recover` with the `site`, a new `public_key`, and a `challenge` makes the hub ask the site, at the address on file, for the challenge signed with the new key. When the answer fits, the new key replaces the old one and the answer is `recovered: true`. Anything else is `403 recovery_closed`, in the same words whether the site is unknown, the recovery is not open, or the site did not answer. An address may ask five times in an hour, and a site three times in a day.

## The export

`GET /export` answers one object: `exported` (the time), `site` (the connection: its address, the email address it registered with, the terms it accepted and when, its versions, and its dates), `partner` (the profile, the state, and the addresses of the hub's copies of its logo and cover), `listings` and `spots` (each record as the hub holds it, with its fields and the addresses of the hub's copies of its photographs), `stats` (every snapshot), `claims`, `adoptions`, `notices`, and `log` (the record of requests, which holds no payload). The key is not in it.

## Place requests

`place` is the hub's number for a listed Place, `organization` the site's identifier for an organization the hub lists, and `note` up to 600 characters. The answer gives the `request`. The same request twice is one request. The hub refuses with `unknown_place`, `missing_reference`, or `already_adopted`. A coordinator of the hub answers the request, and the site reads the answer among its notices.

## What the hub shows everyone

A partner site may draw the hub's map on its own. It asks three public routes of the hub, unsigned, as anyone can: `GET /wp-json/packout/v1/spots`, `/places`, and `/features`, each with `bbox=west,south,east,north`. The site's server asks, every fifteen minutes; a visitor's browser never does.
