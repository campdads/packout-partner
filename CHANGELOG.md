# Changelog

Every version of Pack-Out Spot Partner, newest first.

## 1.0.0

The first stable version. It is 1.0.0-beta.1 with its version changed: nothing the plugin does is different.

- Sites that did not ask for beta versions are offered the plugin from this version on.
- This repository is public. It began again with one commit at 1.0.0-beta.1; the versions before that were built in private, and this file lists them.
- Before a version reaches packout.spot's catalog, a connected site on the release before it updates to it on a test hub. For this version that release is 1.0.0-beta.1.

Not in this version: shipped translations.

## 1.0.0-beta.1

The beta of version 1.0. The plugin does what 0.8.0-beta.1 does. What is new is the guide, and two checks a release now waits on.

- **A partner guide** at [packout.spot/partner-guide](https://packout.spot/partner-guide/): installing, setting up, connecting, what is sent and what is not, keeping it running, and leaving. The readme links to it from its description and its installation steps.
- **An update of a connected site is rehearsed before a release.** A site installs an earlier released version from a test catalog, is set up, connects to a test hub as it is now, lists its work, and has a Spot on the hub's map. Only then is the new version published, and the site updates to it. The release stops unless the site is still connected with the key it had, its pages are unchanged, its records are as they were, the hub knows the new version, and the rest of the connection (adoptions, a joint campaign's invitations, a claim, a move, a lost key, leaving) works on the new version.
- **Sites and hubs of different versions.** The shared library, now 0.13.0, keeps the schemas and the examples of every released version that changed what is sent, and checks them both ways: everything an earlier version sent is still accepted, and the only requests an earlier hub would not know are the ones added since. `contract/v1/README.md` in the library says what that allows.
- The plugin's phase is 12, the last. Every act of the capability list that belongs on a partner site has its handler; `tests/skeleton.php` checks that none waits.

Not in this version: shipped translations.

## 0.8.0-beta.1

Youth teams, and the life of a connection: an organization kept on both sites, a copy of what packout.spot holds, a new address, a lost key, and a later version of the terms. A site that is not connected gains youth teams and the rule about deleted accounts, and is otherwise as it was.

- **Youth teams.** A lead of an organization marks a team in the workspace. Its page names no lead and has no way to join. A mission or campaign that names it takes a sign-up only from people already in the organization. On a connected site, no photograph and no credit line of a Spot in such a mission is sent to packout.spot. When the partner's name or description mentions young people, the setup says where the mark is.
- **Deleting an account never deletes a record.** Whichever choice an administrator makes in Users, the Spots a person reported and the work they recorded stay, with no name and no author. On a network, deleting a user from the network's own Users screen still deletes them: WordPress does that without asking.
- **An organization that is also on packout.spot.** An administrator names an organization here and the address of its page there. A lead there answers. Once accepted, the totals the site sends for the organization and for the partner count its work from that day only, and packout.spot counts what came before from its own records. The site's own pages and screens still show all of it. Either side can end it.
- **A copy of what packout.spot holds.** The Connection screen downloads it as one file.
- **A new address.** A site that moved says so on the Connection screen. packout.spot asks it one question at the new address, changes the addresses it holds, and the site sends everything again.
- **A lost key.** The Connection screen says the key is lost and sends nothing. An emailed link, a button, and one question packout.spot asks the site, signed with the new key, replace it.
- **A later version of the partner terms** stops the sending on the day packout.spot names, thirty days on, and not at once.
- **One more act answers here**, as it does on packout.spot: marking a team as a youth team.
- The readme says what packout.spot shows of a site it has not heard from, and when.
- Carries the shared Pack-Out Spot library, version 0.12.0.

Not in this version: shipped translations.

## 0.7.0-beta.1

Joint campaigns, and the report of a program or a campaign as a whole. A site that is not connected gains the reports and is otherwise as it was.

- **Taking part in a joint campaign.** On a connected site, an invitation from the host of a joint campaign waits above an organization's campaigns in its workspace. A lead accepts or declines. An accepted campaign is kept on the site as a campaign nobody there can change, with its host's name and a link to the whole of it on packout.spot. The mission form offers it, the calendar and the organization's page show it, and its page shows what was planned and recorded on this site. Leaving keeps the missions already in it and what they recorded; no new one can be filed.
- **Hosting one.** A lead makes a public campaign joint from its pane in the workspace, invites organizations packout.spot lists, takes one out, and closes it. packout.spot keeps who takes part. The campaign itself is still changed here.
- **What is sent and learned** is in the readme under "Joint campaigns": organizations and campaigns by their numbers, and no person. Every text packout.spot sends about a joint campaign is cleaned before it is kept, and an address that is not packout.spot's is dropped.
- **Reports.** A campaign's impact report downloads as a PDF from its pane in the workspace, for whoever may change the campaign; on a joint campaign kept here from packout.spot, for a coordinator of the site. A program's report, from its pane and from Programs in the dashboard, now covers the program from its first day. Each has the figures its page shows, says whose it is, and names no person.
- **Four more acts answer here**, as they do on packout.spot: a program's report, a campaign's report, hosting a joint campaign, and answering an invitation to one.
- The readme names WP Engine as a host that keeps the field app's page unless asked not to.
- Carries the shared Pack-Out Spot library, version 0.11.0.

Not in this version: shipped translations.

## 0.6.0-beta.1

The field app, on the partner's own site. A site that never opens it is as it was.

- **The field app.** At `/field/`, or under the word the site chose, and under the site's own path when WordPress is in a subdirectory. A signed-in person downloads the map of a region, and with no signal reports a Spot with photographs and a pin, works a mission's checklist, marks its Spots cleaned, and records a cleanup or a return visit. What waited is sent when the signal is back, each thing once. A report arrives as any report does, and on a connected site goes on to packout.spot.
- **It is the partner's.** The app's title, the name on a home screen, its color, and its icon are the partner's name, the main color of the site's theme, and the site's icon. The site's icon is listed by the size each copy really is; without one of 144 pixels or more the app uses its own.
- **packout.spot's Spots with a region.** On a connected site that shows the hub's layers, the hub's Spots inside a downloaded region are kept on the phone and can be read with no signal. They cannot be reported on or changed, and the phone gets them from the partner's site, never from packout.spot.
- **Caches.** The app's page, its worker, and its manifest ask not to be kept, carry a date in the past, and define `DONOTCACHEPAGE`. The readme says which three addresses a host or CDN that keeps pages regardless must leave alone.
- **A new version reaches the phone.** The page names the worker by the version the worker gives itself, so a plugin update, or a change to any file the app keeps, brings a new worker on the next visit, which removes what the old one kept and leaves the downloaded regions. Before this the two could disagree. A browser lets the new worker take over when the old one has gone quiet: Chrome at once on most updates, and on about one in twenty when the app is next opened or has been left alone for half a minute. In that time the app still opens with no signal.
- The app's address without its last slash goes to the address with it.
- **Seven more acts answer here**, as they do on packout.spot: a report from the app, a capture sent later, the list of regions, a mission's progress, a Spot marked cleaned, a lead choosing between a phone's copy of a mission and the site's, and a coordinator listing regions.
- `tests/field.php` checks the page, the manifest, and the worker over HTTP, and the app at another address.
- Carries the shared Pack-Out Spot library, version 0.10.0.

Not in this version: joint campaigns, a program's report, and shipped translations.

## 0.5.0-beta.1

Setting a site up, and what a site needs around the application. A site that was set up by hand keeps working as it was: the Pages screen finds the pages its settings name.

- **Pack-Out Spot, Setup.** Eight steps for an administrator: what the server can do, with a test email; the partner, with its logo; a first organization; what connecting means; the pages; the map; inviting co-leads; and how the site stands. Each step says what it will do, can be left for later, and can be opened again. The dashboard says the setup is there until it is finished or put off. The setup does not connect the site, and it never sets, changes, or replaces the front page, the menus, or the theme.
- **Pack-Out Spot, Pages.** Fifteen pages from templates: made as ordinary pages, or taken from pages the site already has. A plugin update never changes a page. A page nobody edited is offered the newer template. An edited page is never written to unless the site chooses Replace, which asks first and leaves what was written in the page's revisions; the site can also keep its own or make a draft copy to compare. A taken address gets `pack-out-` in front. Each template is also a block pattern.
- **The logo and the cover reach packout.spot.** They go with the partner's profile, each in a request of its own, and packout.spot keeps a copy with no location or camera details. Removing one here removes it there.
- **How the site stands.** Tools, Site Health carries the plugin's checks, with what is waiting to be sent and for how long, whether the organizations' address is taken by a page, and whether updates can be checked. The Info tab says how the connection stands and never shows a key. `wp packout doctor`, `status`, `sync`, `reconcile`, `notices`, `connect`, `disconnect`, and `pages` do the same from the command line; `doctor` and `status` exit with 1 when something needs fixing.
- **Privacy.** Suggested text for the site's privacy policy. What the plugin keeps about a person is in WordPress's export of personal data. An erasure removes the profile, contact details, private notes, and invitations to the address; Spots, public updates, and records of work stay with no author and no name.
- **A partner badge** block, shown only while the site is connected and listed. An option adds the site's own public totals under it: Spots cleaned and verified hours.
- **The overview says when sending has stopped.** On a connected site, a line above the queue says when a send to packout.spot keeps failing and why, when the connection needs someone, and when the copy of packout.spot's map is out of date. An administrator gets a link to the Connection screen.
- **Disconnect asks for a tick** on the form, with or without scripts.
- **Deleting the plugin with "Remove all Pack-Out Spot data" ticked removes everything the plugin made**: records with their photos, logos, and files, categories, tables, settings, what it kept on each account, roles and capabilities, scheduled tasks, and the connection with its key. It removes by exact name, never by the `pos_` prefix, which point-of-sale plugins also use. The pages the setup made and the user accounts stay; an account left with no role gets the site's default role.
- **Translation.** `languages/` carries the templates for the plugin's own text and for the library's. `bin/make-pot.sh` writes them.
- The map's Contributions menu lists Spots alone, since a partner site keeps no Places or map features.
- The Connection screen's list of what is sent names Spots, which have been sent since 0.4.0-beta.1.
- The report form no longer runs off the side of a phone in a theme that does not limit the width of a form's fields.
- Carries the shared Pack-Out Spot library, version 0.9.1. The profile now names the logo and the cover, which a hub older than 0.25.0 refuses.

Not in this version: the field app, joint campaigns, a program's report, and shipped translations.

## 0.4.0-beta.1

The Spot exchange, for a site connected to packout.spot. A site that never connects works as before.

- **Your Spots on packout.spot's map.** A Spot is sent as soon as the request that saved it has finished. Until a coordinator reviews it, only its category, the day, the hazard flag, and a location rounded to about a kilometre are sent, and packout.spot shows it as awaiting review. When it is published, what the site's own page shows is sent, then its public photos, one at a time, as copies with no location data. A Spot shown at an approximate point is sent at that point; its stored pin and its landmark never leave the site. A Spot kept private, returned, or deleted is taken off packout.spot.
- **What packout.spot did with it.** The Spots screen says how each Spot stands on the hub: shown, waiting, hidden or returned with the coordinator's reason, or linked to a Spot already there, and names a Spot on packout.spot that looks like the same thing.
- **packout.spot on the site's map.** Pack-Out Spot, Connection: tick the box and name a region of at most 500 kilometres a side. The site reads the hub's public Spots, Places, and map features there about every fifteen minutes and serves them to its own visitors, under "On packout.spot" in the map's layers. A visitor's browser never calls packout.spot.
- **Taking on a Spot from packout.spot.** A lead adds one to a mission from the Missions screen, chosen from the region or by its number. packout.spot shows that the crew plans to work on it, then that it is working. When the work is recorded here, with its photos, packout.spot marks the Spot cleaned and keeps its own record of it. The site leaves that work out of the totals it sends, so it is counted once. Taking the Spot off the mission, calling the mission off, or leaving it open gives it back.
- **Adopting a Place.** A lead of an organization asks packout.spot, from the Organizations screen, to name the organization as an adopter of a Place there. A coordinator at packout.spot answers, and the answer is on the Connection screen.
- Once a night the site compares its Spots with the ones packout.spot holds and sends what differs.
- A change made while its earlier version was being sent is no longer lost until the nightly send.
- Deleting the plugin with "Remove all Pack-Out Spot data" ticked also removes what was read of packout.spot and what the hub said of each Spot.
- Carries the shared Pack-Out Spot library, version 0.8.0.

Not in this version: logos and covers on packout.spot, the setup wizard that makes the pages, the field app, and removing every record when the plugin is deleted.

## 0.3.0-beta.1

The connection to packout.spot. A site that never connects works as before.

- **Pack-Out Spot, Connection.** An administrator reads what is sent and what never is, accepts the partner terms, and connects. The screen then shows the steps that are left (an emailed confirmation, packout.spot reaching the site, a coordinator's approval), how the connection stands, what packout.spot has told the site, what is waiting to be sent and why, and a log of what was sent.
- **What is listed.** The partner's profile, and every organization, team, program, campaign, and mission that is public on the site, each with the address of its page here. People join and sign up here. packout.spot shows them read-only.
- **Totals.** Hours, people, Spots cleaned, bags, and pounds for the partner and for each listing, measured apart from self-reported, with how many records they come from and how many are verified, and the figures of the overview as numbers.
- **Nothing about a person is sent**: no member, lead, volunteer, or reporter, no sign-up, no message, no photo, and nothing that is not public here.
- **How.** A key pair made on the site; the secret key never leaves it. Every request is signed and sent over HTTPS. Nothing is sent while a visitor waits: a change is queued and sent by WP-Cron a few minutes later, and everything public is sent again in full once a night. A send that fails is kept and tried again after a minute, five, fifteen, an hour, and then every six hours.
- **When packout.spot says stop.** A suspended partner, changed terms, or a plugin too old for the hub stops the sending, and the screen says which and what to do. What changed meanwhile goes when sending starts again.
- **A copy of the site** at another address sends nothing until an administrator says whether it is a copy or the site moved.
- **Leaving.** Disconnect removes the listings from packout.spot and deletes the key, whether or not packout.spot can be reached. Send now, Send everything again, and Replace the key are on the same screen.
- Deactivating stops the timers and keeps the connection. Deleting the plugin with "Remove all Pack-Out Spot data" ticked removes the connection, its key, and its two tables as well as the settings. It does not tell packout.spot.
- Carries the shared Pack-Out Spot library, version 0.7.0.

Not in this version: Spots on the hub's map, the setup wizard that makes the pages, the field app, and removing every record when the plugin is deleted.

## 0.2.0-beta.1

The application arrives. A site with this plugin and nothing else does what a partner does on packout.spot, apart from what the list below leaves for later.

- **Spots.** A report form of the plugin's own, a map, and a public page for each Spot with its photos, updates, and credit. A coordinator reviews a report before it is on the map, flags a hazard, and records that work is authorized.
- **Organizations and teams.** Proposing and approving an organization, its profile, members, co-leads, invitations, messages, resources, sponsors, and its teams.
- **Campaigns, missions, and programs.** Dates, times, and a time zone; sign-up; a checklist; starting, completing, calling off, and repeating a mission; a program several organizations take part in, with its progress.
- **The calendar.** One block in six forms, a calendar file to subscribe to, and event data on a mission's page.
- **Impact.** A record of each cleanup, measured and self-reported figures kept apart, verification, public totals, and each person's own record.
- **Accounts.** Sign-up by email link, sign-in, a profile, and a workspace.
- **The dashboard.** An overview of the site and of each organization, with a PDF report, and screens for Spots, Missions, Organizations, Programs, People, Invitations, Impact, Export, and Settings.
- **One partner.** The site's partner record is made on activation, named after the site, led by the administrator who activated the plugin, and not listed until the site lists it. Every organization on the site is its.
- **Addresses.** Settings, Pack-Out Spot sets the first word of each kind of record's address. A word a page, another content type, or WordPress already uses is refused, with one that is free suggested. The old address forwards to the new one.
- What packout.spot alone does is refused here with a plain answer: proposing or approving a partner, joining or leaving one, places and their adoption, and map features.
- The map shows the thousand most recently changed Spots. On a site with more, it adds the Spots of the area on view each time it is moved.
- Carries the shared Pack-Out Spot library, version 0.6.2, and Dompdf 3.1.6 for the PDF report.

Not in this version: the connection to packout.spot, the setup wizard that makes the pages, the field app, and removing every record when the plugin is deleted. Ticking "Remove all Pack-Out Spot data" still removes the plugin's settings only.

## 0.1.0-beta.3

Nothing a site's owner sees changes.

- Carries the shared Pack-Out Spot library, version 0.3.0, which adds the rules of the calendar: when a mission starts and ends in its own time zone, the six forms of the calendar, the calendar file, and repeating a mission. packout.spot uses them now. This plugin uses them when it gains missions and the calendar.

## 0.1.0-beta.2

Nothing a site's owner sees changes.

- Carries the shared Pack-Out Spot library, version 0.2.0, which adds the rules of a partner and of a program. packout.spot uses them now. This plugin uses them when it gains programs.

## 0.1.0-beta.1

The first beta. It is the plugin's frame, with none of the application yet.

- Installs on WordPress 6.8 or later with PHP 8.2 or later, and needs no other plugin.
- Refuses to be activated for a whole network, and refuses to run on a site that runs Pack-Out Spot itself. Each says why.
- Takes updates from the catalog on packout.spot. A package is installed only when its SHA-256 matches the catalog's and its signature was made by Pack-Out Spot's release key.
- Settings, Pack-Out Spot: choose beta versions, and choose whether deleting the plugin removes its data. By default it removes nothing.
- Carries the shared Pack-Out Spot library, version 0.1.0.
