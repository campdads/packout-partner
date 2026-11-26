=== Pack-Out Spot Partner ===
Contributors: campdads
Tags: stewardship, cleanup, volunteers, map, community
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Community stewardship for places that need attention, on your own site.

== Description ==

Pack-Out Spot is how a community looks after places: someone reports a Spot that needs attention, an organization plans a mission to clean it, and the result is recorded. [packout.spot](https://packout.spot) is the hub where that work is shown together.

Pack-Out Spot Partner runs the same work on your own WordPress site. Your records stay in your database. If you choose to connect the site to packout.spot, your organization appears there as a Partner, and the Spots, missions, and results you publish are shown there too.

With it, people sign up, report Spots, start organizations and teams, plan campaigns and missions, record what a cleanup removed, and see the totals; an administrator sets the site up in a few steps, which make its pages, and can connect it to packout.spot; a connected site's Spots are on the hub's map, with the hub's Spots available on yours; a person can report a Spot and work a mission on a phone with no signal; and an organization on a connected site can take part in a campaign with other partners, or host one. The plugin works in full without connecting, and its Connection screen says whether packout.spot is taking new partner sites. The [partner guide](https://packout.spot/partner-guide/) walks through installing it, setting it up, and connecting.

The plugin needs no other plugin and works with any theme.

= What is in this version =

* A report form, a map of Spots, and a public page for each Spot, with its photos and its updates.
* Organizations, their teams, their members, invitations, and messages.
* Campaigns and missions, with dates and times, sign-up, a checklist, and a calendar in six forms with a calendar file to subscribe to.
* Programs that several organizations take part in, with their progress and totals.
* Impact records: hours, people, bags, and weight, with measured and self-reported figures kept apart, and a coordinator's check before a figure is counted publicly.
* Accounts: sign-up by email link, sign-in, a profile, and a workspace for each organization.
* Screens under Pack-Out Spot in the dashboard: an overview, Spots, Missions, Organizations, Programs, People, Invitations, Impact, Export, and Settings. The overview downloads as a PDF report.
* An impact report of a program or of a campaign as a whole, as a PDF, for whoever may change it: a program's from its pane in the workspace and from Programs in the dashboard, a campaign's from its pane in the workspace. It has the figures the program's or campaign's page shows, and names no person.
* Blocks for a page: Map, Report a Spot, Organizations, Impact, Calendar, Sign up, Sign in, Account, Workspace, and a partner badge that shows while the site is connected.
* A setup, under Pack-Out Spot, Setup, that names your partner, makes a first organization, makes the pages, and places the map. "Setting up" below says what it does and what it leaves alone.
* Pack-Out Spot, Pages: every page the plugin offers, how each stands, and what can be done with it.
* How the site stands, in Tools, Site Health, and from the command line with `wp packout`.
* Suggested text for your privacy policy, and what the plugin keeps about a person in WordPress's export and erasure of personal data.
* The connection to packout.spot, under Pack-Out Spot, Connection. It is off until an administrator turns it on. "Connecting to packout.spot" below says what it sends.
* A field app at your site's own address, under your partner's name, for a phone with no signal. "The field app" below says what it does and what it needs.
* On a connected site: its partner's logo and cover on its page at packout.spot; its Spots on packout.spot's map; packout.spot's Spots, Places, and map features on its own map, if an administrator turns that on; a mission that takes on a Spot reported on packout.spot; and a request to adopt a Place there.
* On a connected site: joint campaigns. "Joint campaigns" below says how they work.
* Youth teams. "Youth teams" below says what marking a team changes.
* On a connected site: saying that one of your organizations is the same as one packout.spot keeps, so its work is counted once; a copy of everything packout.spot holds from your site, as a file; telling packout.spot that the site moved to a new address; and getting a new key when the site's key is lost. "An organization that is also on packout.spot" and "Leaving" below say how.

= What is not in it yet =

* Translations. The plugin can be translated and carries the templates a translator starts from, in `languages/`. None is shipped yet.

= Your site is one partner =

The plugin makes one record for your site when it is activated: your partner, named after the site and led by the administrator who activated the plugin. Every organization on the site belongs to it. It is not shown publicly until you list it.

= Addresses =

Each kind of record has a public address that starts with one word: `spot`, `team`, `mission`, `campaign`, `program`, `partner`. If your site already uses one of those words for a page or for another plugin's content, change it under Settings, Pack-Out Spot. The plugin refuses a word that is already in use and suggests one that is free. The old address forwards to the new one.

= One site, not a network =

Activate the plugin on the one site that will use it. It cannot be activated for a whole network, because each site has its own records and its own connection. It cannot run on a site that runs Pack-Out Spot itself.

== Installation ==

1. Download the plugin's zip from [packout.spot/plugins/packout-partner](https://packout.spot/plugins/packout-partner).
2. In WordPress, open Plugins, Add New Plugin, Upload Plugin, and choose the zip.
3. Activate **Pack-Out Spot Partner**.
4. Open Pack-Out Spot, Setup. The dashboard reminds an administrator that it is there until it is finished or put off.
5. Open Settings, Pack-Out Spot to choose beta versions and the addresses of your records.
6. Open Pack-Out Spot in the dashboard menu for the overview and the rest of the screens.

The [partner guide](https://packout.spot/partner-guide/) on packout.spot goes through the same steps at more length, and through connecting.

= The report form and page caches =

A page with the Report a Spot block carries a value that is good for a day at most. If your host or a plugin keeps copies of pages, leave that page out of the copies, or reports sent from an old copy are refused and the person is asked to send theirs again.

== Setting up ==

Pack-Out Spot, Setup takes an administrator through eight steps. Each says what it will do before it does it, each can be left for later, and any of them can be opened again.

1. **Check.** What this server can do: permalinks, scheduled tasks, signatures, photos, and email, with a test email to your own address. The address is not kept.
2. **Your partner.** Its name, description, region, website, and logo, and whether it has a public page.
3. **Your first organization.** Under the partner's own name, or another.
4. **Connect.** What connecting to packout.spot means, with a link to the Connection screen. The setup does not connect the site.
5. **Pages.** For each page, whether the plugin makes it, uses a page you already have, or leaves it out.
6. **Map.** Where the map opens, and how reports are checked for duplicates.
7. **People.** Invite co-leads by email.
8. **Done.** How the site stands, and what to do next.

The plugin never sets, changes, or replaces your front page, your menus, or your theme. Add the pages you want to your menu yourself.

= The pages =

The setup offers fifteen pages: Map, Report a Spot, Organizations, Calendar, Impact, How it works, Sign up, Sign in, My organizations, My account, and five pages under My account. Each is an ordinary page with one of the plugin's blocks on it, and you can edit it like any other. If an address is already taken, the new page gets `pack-out-` in front of its address.

Pack-Out Spot, Pages lists them and says how each stands. A plugin update never changes a page. When a newer version of the plugin carries a newer template, a page nobody has edited is offered an Update. A page you have edited is never written to unless you choose Replace, which asks first and leaves what you wrote in the page's revisions. You can also keep yours, or make a draft copy of the new template to compare. Each template is also in the block patterns, under Pack-Out Spot.

= How the site stands =

Tools, Site Health reports the same checks the setup's first and last steps show, with what is waiting to be sent to packout.spot and for how long. Its Info tab has a Pack-Out Spot section that says how the connection stands. It says whether the site has a key and never shows it.

With WP-CLI: `wp packout doctor` lists every check, `wp packout status` says how the connection stands, `wp packout sync` sends what is waiting, `wp packout reconcile` sends everything again, `wp packout notices` lists what packout.spot has told the site, `wp packout pages` lists the pages and `wp packout pages update` updates the ones nobody has edited, and `wp packout connect` and `wp packout disconnect` do what the Connection screen does. `doctor` and `status` exit with 1 when something needs fixing.

== The field app ==

The field app is a page of your site, at `/field/` unless you change the word under Settings, Pack-Out Spot. A person who has signed in opens it on a phone, and the phone offers to add it to the home screen. It carries your partner's name, the main color of your theme, and your site's icon, from Settings, General. It needs your site to be on HTTPS, as every app of this kind does.

Before leaving coverage, a person opens the app once and downloads the map of a region. Out of coverage they can:

* report a Spot, with photographs and a pin, which waits on the phone;
* open a mission they are on, tick its checklist, and mark its Spots cleaned;
* record what a cleanup removed, and a return visit to a cleaned Spot.

Back in coverage the app sends what waited. Each thing is sent once, however many times the phone tries, and a report arrives as any report does: it waits for a coordinator's review, and on a connected site it goes on to packout.spot as "What is sent" below describes.

A region is a name, a box on the map, and the address of a map package in the PMTiles format. A coordinator lists regions under Pack-Out Spot, Settings. The plugin does not make the packages, and without one the app still works wherever there is a signal.

On a connected site that shows packout.spot's Spots on its map, the app also keeps the Spots of packout.spot that lie inside a downloaded region, to be read with no signal. They cannot be changed from the app. The phone gets them from your site and never calls packout.spot.

The app keeps on the phone only what the signed-in person sent or was given, and the page it keeps to open with no signal names nobody.

= The field app and caches =

Three addresses must reach WordPress every time, and must not be kept by a page cache plugin, by your host, or by a CDN:

* `/field/`, the app's page;
* `/field/sw.js`, with anything after a `?`, the part of the app that keeps it on the phone;
* `/field/app.webmanifest`, the name, color, and icon a phone reads when the app is added to a home screen.

If WordPress is in a subdirectory, each is under it, and if you changed the word, each is under yours. Each of the three says so itself: it asks not to be kept, carries a date in the past, and sets `DONOTCACHEPAGE`, which the common page cache plugins obey. Add a rule only if your host or CDN keeps pages regardless. WP Engine is one that does: measured on packout.spot in November 2026, it kept the app's page for ten minutes whatever the page asked. There, ask support to leave `^/field/` out of the page cache. A kept copy of the second holds phones on an old version of the app after you update the plugin.

The app's scripts, styles, and icons may be kept for as long as you like: their addresses change with every version. What the app sends and reads, under `/wp-json/packout/v1/`, must not be kept.

After an update of the plugin, a phone gets the new version of the app the next time it is opened with a signal.

== Connecting to packout.spot ==

The plugin works in full without ever connecting. Connecting is a choice an administrator makes under Pack-Out Spot, Connection, and can undo there.

A connected site gets a public page on packout.spot for its partner. The organizations, teams, programs, campaigns, and missions that are public on your site are listed there, each with a link back to its page on your site. Its Spots are on packout.spot's map, marked as reported through your partner, each with a link to its page on your site. People join and sign up on your site, not on packout.spot. Your records stay in your database and remain the record of truth: packout.spot holds a copy of what is public and cannot change yours.

= What is sent =

* Your partner's name, description, region, and website, the address of its page on your site, and its logo and cover image. packout.spot keeps a copy of each image with no location or camera details.
* Each organization and team with a public page: its name, description, and the address of that page.
* Each public program, campaign, and mission: its title, description, goal, dates, times, time zone, status, and the address of its page. A mission's landmark is sent only when its lead ticked "share" on it.
* Each Spot, in two steps. **When it is reported and before a coordinator has reviewed it:** its category, the day, whether it was flagged as a hazard, and its location rounded to about a kilometre. Nothing the reporter wrote, no photo, and no credit. packout.spot shows it as "Reported, awaiting review". **When a coordinator publishes it:** what your own public page shows: title, category, stage, a short description, the location as your coordinator set it (a Spot you show at an approximate point is sent at that approximate point, with no landmark), the day, the credit line the reporter chose to show, and the address of its page. Then the photos your public sees, one at a time, as copies that carry no location data.
* A Spot you keep private, return to its reporter, or delete is taken off packout.spot. A photo you hide is removed there.
* Totals for the partner and for each listed organization, team, program, campaign, and mission: hours, people, Spots cleaned, bags, and pounds, with measured and self-reported figures apart, how many records they come from, and how many of those are verified. Only records your site counts publicly are in them.
* The figures of the overview for the partner and for each listed organization, as numbers.
* When a mission of yours takes on a Spot reported on packout.spot: that Spot's number, the mission, and its organization. When the crew records the work: the outcome, hours, people, bags, pounds, what was written about the cleanup, and the photos of the work. packout.spot keeps that as its own record of the cleanup, credited to your organization, and your site leaves it out of the totals it sends so that it is counted once.
* When a lead asks to adopt a Place on packout.spot: the Place, the organization, and the note the lead typed.
* For a joint campaign: which of your organizations accepted, declined, or left; that a campaign of yours is joint or closed; and which organization its lead invited or took out. A mission your organization files under another host's joint campaign is listed under that campaign, and the totals of your part of it are sent as any totals are.
* When an administrator says an organization of yours is the same as one on packout.spot: your organization's number and the address of the organization's page there. No person is named.
* When the site moves: its new address. When its key is lost: the public half of a new key.
* Your site's address, the version of the plugin, and, once, the email address of the administrator who connects the site. packout.spot sends that address a confirmation link and tells it when the partner is approved, suspended, or declined.

= What is never sent =

* Anything about a person: no member, lead, volunteer, or reporter, and no email address or phone number. The one name that is sent is the credit a reporter chose to have shown on a published Spot, exactly as your own page shows it.
* Who signed up for a mission, or how many.
* Anything that is not public on your site, apart from the bare facts of a Spot awaiting review listed above.
* The stored pin of a Spot you show at an approximate point, or its landmark.
* The exact place of a mission's meeting point, or a note written for the crew.
* A photo your public does not see, the original of any photo, or a photo's caption.
* Messages, invitations, and your settings.

= How it is sent =

Connecting makes a key pair on your site. The secret key stays in your database and is never sent. Every request after the first is signed with it, over HTTPS, and packout.spot refuses a request that is not.

Nothing is sent while a visitor waits for a page. A change to something public is queued and sent a few minutes later by WP-Cron. A Spot is sent as soon as the request that saved it has finished, so that it is on packout.spot's map within about a minute. Once a night everything public is sent again in full, and the site compares its Spots with the ones packout.spot holds. If packout.spot cannot be reached, what is waiting is kept and tried again. The Connection screen shows what is waiting and a log of what was sent. The log keeps ninety days and never keeps the contents of a request.

packout.spot calls your site once, when you connect, to be sure the request came from your address: it asks `/wp-json/packout/v1/hub-challenge` for a signed answer. If your host or a plugin keeps copies of pages or blocks requests to the REST API, let that address through and leave it out of the copies.

Before the partner is listed, an administrator confirms the request by an emailed link, and a coordinator at packout.spot approves it. A coordinator can hide one listing, hold back totals that look wrong, or suspend the partner; the Connection screen says so and why. A coordinator there can also hide one of your Spots from packout.spot's map, send it back with a request, show it at an approximate point, or note that it is the same as a Spot already there. The Spot stays as it is on your site, and its row on your Spots screen says what packout.spot did and why.

= Joint campaigns =

A joint campaign is one campaign that several organizations work in, each with its own missions on its own site. packout.spot keeps who takes part, so it needs a connected site.

* **Taking part.** When the host of a joint campaign invites one of your organizations, the invitation waits above that organization's campaigns in its workspace, and a lead accepts or declines. Once accepted, the campaign is kept on your site as a campaign nobody here can change, with its host's name and a link to the whole of it on packout.spot. The mission form offers it, your calendar shows it, and its page here shows what your organization planned and recorded. A lead can leave: the missions already in it keep their place and stay counted, and no new one can be filed.
* **Hosting.** A lead opens a public campaign's pane in the workspace and chooses "Make this a joint campaign". packout.spot then lists it as one, and the lead invites organizations packout.spot lists, takes one out, or closes the campaign. You go on changing its title, dates, and description here.
* **What packout.spot shows.** The campaign's page there names who takes part, in alphabetical order, with what each recorded. Your line is what your site reported, labeled as a partner site's own report with its date. Nobody is ranked.
* **What your site learns.** The title, description, dates, and host of a campaign one of your organizations is invited to or takes part in; and, for a campaign you host, the names of the organizations packout.spot lists and how each you invited stands. Your site asks for these only when packout.spot says something changed.

Nothing about a person is sent or learned: an invitation names an organization.

= An organization that is also on packout.spot =

An organization that worked on packout.spot before your site existed can be on both. Left alone, its earlier work would be counted twice once your site lists it. Under Pack-Out Spot, Connection, an administrator names the organization here and gives the address of its page on packout.spot. A lead of the organization there is asked, and answers in its workspace.

When the lead accepts, packout.spot counts what the organization did there before that day, and your site counts what it does from that day on. The totals your site sends for the organization, and for your partner as a whole, leave out the earlier work. Your own pages and screens are not changed: they still show all of it. The organization on packout.spot is kept as it is and takes no new plans or records, its page there says its work is kept on your site, and its members are told once. Nobody's account is moved or copied.

Either side can end it. The organization on packout.spot is then worked in again, the days between stay counted on your site, and your site goes on leaving out the earlier work.

= Youth teams =

A lead of an organization can mark one of its teams as a youth team, in the workspace: open the team, then Name and lead. A youth team's page names no lead and nobody can ask to join it. A mission or campaign that names a youth team takes a sign-up only from people who already belong to the organization. On a connected site, no photograph and no credit line of a Spot in such a mission is sent to packout.spot, and packout.spot shows no sign-up link for it. Taking the mark off does not put lead names back or open the team: someone sets those.

= packout.spot on your map =

Off until an administrator turns it on under Pack-Out Spot, Connection, and names a region of at most 500 kilometres a side. Your site then asks packout.spot, about every fifteen minutes, for the Spots, Places, and map features it shows the public in that region, and keeps the answer. The request carries the region and your site's address and nothing else. Your visitors' maps read that kept answer from your site: a visitor's browser never calls packout.spot. The three layers are listed under "On packout.spot" in the map's layers, each off until the visitor ticks it, and nothing on your site can change what they show.

A lead can add a Spot from packout.spot to a mission, from the mission on the Missions screen. Your site then holds a copy of what packout.spot shows publicly of that Spot. If packout.spot shows the Spot at an approximate point, so does the copy.

= Leaving =

Disconnect under Pack-Out Spot, Connection. Your listings are removed from packout.spot, the key is deleted, and nothing more is sent. You choose whether packout.spot keeps the partner's name and its last totals as history, out of public view. Nothing on your site changes, and you can connect again later.

Deactivating the plugin stops the sending and keeps the connection. Deleting the plugin does not tell packout.spot: disconnect first if you want your listings taken down.

If you ask for everything to be removed, packout.spot also removes what your crews wrote and photographed on Spots of packout.spot's own that they took on. That such a Spot was cleaned, when, and what was carried out stays, because it is a fact about a place on packout.spot's map.

Before you leave, or at any time, the Connection screen downloads a copy of everything packout.spot holds from your site, as one file: the connection without its key, your partner, each listing and Spot, each day's totals, and the record of your site's requests.

A copy of your site at another address, such as a staging copy, sends nothing. The Connection screen there asks whether it is a copy, which forgets the connection on the copy alone, or whether the site moved. If it moved, "Tell packout.spot the site moved" sends the new address, signed with the site's key. packout.spot asks the site one question at the new address, changes the address it holds on your partner and on every listing and Spot, and your site sends everything again. The new address has to begin with https.

If the site's key is lost, for instance after a restore from a backup made before it connected, the Connection screen says so and sends nothing. "Send the email" asks packout.spot to email a link to the administrator who connected the site. Open the link and press its button, then come back within the hour and choose "Finish with a new key". The site makes a new key, and packout.spot asks the site one question at the address it has on file, signed with that key, before it takes it. packout.spot answers the first step in the same words whether or not it knows the site.

A site packout.spot does not hear from for a day is shown there with the date it was last updated. After 14 days its coming missions and campaigns leave public view there, and after 90 days it is off the directory and its page says since when it has not been heard from. The administrator who connected it is emailed four days before the first and fifteen days before the second. The next request the site sends undoes all of it. WP-Cron sends the requests, so a site with visitors, or a real cron job, is never silent.

The partner terms are at [packout.spot/partner-terms](https://packout.spot/partner-terms/). When they change, packout.spot names a day, thirty days on, to accept the new version by. Until then the site goes on sending and the Connection screen asks for the acceptance. After it, the site sends nothing until an administrator accepts, and nothing is removed from packout.spot meanwhile.

== Updates and what they send ==

The plugin is published from the catalog on packout.spot, not from WordPress.org. WordPress checks for a new version about twice a day, the same way it checks every plugin.

Each check sends packout.spot three things: the plugin's file name, the version you have, and whether you chose beta versions. As with every WordPress update check, the request also carries your site's address. packout.spot uses that to count installations and to know which versions are in use. Nothing else is sent, and nothing is sent about your visitors or your records.

Every package is signed with Pack-Out Spot's release key. Before WordPress installs an update, the plugin checks the package's SHA-256 against the one the catalog gave and checks the signature against the public key the plugin carries. A package that fails either check is not installed, and the update screen says why.

To stop update checks, deactivate the plugin. A site that sets `DISALLOW_FILE_MODS` is not offered updates.

== Other services the plugin uses ==

The map and the search for a place use three outside services. None of them is sent a person's name, email address, or account.

* **OpenFreeMap** draws the map. A visitor's browser asks `tiles.openfreemap.org` for the part of the map they are looking at, so that service sees the visitor's IP address. To use another source of tiles, change the map style under Pack-Out Spot, Settings. [openfreemap.org](https://openfreemap.org/)
* **Nominatim**, the search of OpenStreetMap, finds a place by name and names the place at a pin. Your site, not the visitor's browser, asks `nominatim.openstreetmap.org` with the words that were typed or the coordinates of the pin. The request names your site's address, as Nominatim's usage policy asks. Answers are kept for a while so the same question is not asked twice. [Usage policy](https://operations.osmfoundation.org/policies/nominatim/)
* **The U.S. Forest Service** supplies the forest overlays of the map, such as the Motor Vehicle Use Map. A visitor's browser asks `apps.fs.usda.gov` only after they turn one of those overlays on. [Privacy policy](https://www.usda.gov/privacy-policy)

== Frequently Asked Questions ==

= Does it need Vectra Toolkit or any other plugin? =

No.

= Where are my records kept? =

In your site's own database, as WordPress posts, their fields, two tables for invitations, and two for the connection to packout.spot: what is waiting to be sent, and the log of what was. Photos are in your media library. What your site last read of packout.spot for its map is kept in one option, and is replaced each time it is read. Deactivating the plugin removes nothing. Deleting it removes nothing either, unless you tick "Remove all Pack-Out Spot data when the plugin is deleted" in Settings first. With the box ticked, deleting the plugin removes everything it made: its records with their photos, logos, and files, its categories, its tables, its settings, what it kept on each account, its roles, its scheduled tasks, and the connection with its key. The pages the setup made stay, as ordinary pages, and so do the user accounts. An account that held only one of the plugin's roles gets the site's default role. It cannot be undone, and packout.spot is not told: disconnect first if you want your listings taken down.

= What does the plugin do for my privacy policy and for a request about someone's data? =

The policy guide under Settings, Privacy has suggested text for your privacy policy from this plugin. It is a suggestion: read it and make it yours.

Tools, Export Personal Data includes what the plugin keeps about a person: their profile and contact details, the organizations they belong to, the missions they signed up for, the Spots they reported, their notes and updates, the work they recorded, the messages they sent as a lead, and invitations sent to their address. Tools, Erase Personal Data removes the profile, the contact details, private notes, and invitations to the address. The Spots a person reported, the public updates on them, and the records of work stay, with no author and no name, because they are the history of a place. Memberships stay with the account: delete the account to remove them. Deleting an account in Users never deletes a Spot or a record of work, whichever of WordPress's two choices you make there: the plugin first takes the person's name off them, as an erasure does, and they then belong to no account. On a network of sites, deleting a user from the network's own Users screen is different: WordPress deletes everything they wrote on every site without asking any plugin, so remove the person from the site instead. A name typed on a report made without an account cannot be matched to an email address, so an erasure does not find it: a coordinator removes it on the Spot.

= Can it be translated? =

Yes. `languages/packout-partner.pot` and `languages/packout-core.pot` are the templates. Put a translation of each in `wp-content/languages/plugins/`, named `packout-partner-<locale>.mo` and `packout-core-<locale>.mo`. The editor and the dashboard scripts read JSON files made from the second with `wp i18n make-json`, from the same directory or the plugin's `languages/`.

= What does the report form keep about a visitor? =

The report, the photo if one was sent, and the visitor's name only when they asked for it to be shown. It asks for no email address. To slow down scripts it counts reports by the sender's address for ten minutes; the address itself is not stored.

= What other software does the package contain? =

MapLibre GL JS and PMTiles draw the map (BSD 3-Clause). Dompdf, with php-font-lib, php-svg-lib, HTML5-PHP, and PHP-CSS-Parser, makes the PDF report (LGPL 2.1, LGPL 3.0, and MIT). The icons are from the WordPress icon library (GPL 2.0 or later). Chart.js draws the overview's charts (MIT). Each is in the package with its license. No script or style sheet is loaded from another site. "Other services the plugin uses" says what the map and the search ask of other sites.

= What are beta versions? =

Versions released for testing before they become the stable version. Tick "Get beta versions" in Settings, Pack-Out Spot to be offered them. Untick it and you are offered stable versions only; a beta you already have stays until a newer stable version is released.

= Where do I report a problem? =

On [GitHub](https://github.com/campdads/packout-partner/issues). For a security problem, report it privately as [SECURITY.md](https://github.com/campdads/packout-partner/blob/main/SECURITY.md) describes.

== Changelog ==

= 1.0.0 =

The first stable version. It is 1.0.0-beta.1 with its version changed: nothing the plugin does is different.

* Sites that did not ask for beta versions are offered the plugin from this version on.
* The plugin's source is public at [github.com/campdads/packout-partner](https://github.com/campdads/packout-partner), where a problem can be reported.

= 1.0.0-beta.1 =

The beta of version 1.0. The plugin does what 0.8.0-beta.1 does; what is new is around it.

* A partner guide at [packout.spot/partner-guide](https://packout.spot/partner-guide/): installing, setting up, connecting, what is sent, and leaving. This readme links to it.
* A site that updates while it is connected stays connected. Before a version is released, a site on an earlier released version connects to a test hub, lists its work, puts a Spot on the hub's map, and is then updated to the new version; the release stops unless the connection, the key, the pages, and the records are as they were.
* Carries the shared Pack-Out Spot library, version 0.13.0. It adds a check that a site and packout.spot understand each other when one of them is on an earlier version: everything an earlier version sent is still accepted, and nothing it sends was taken away.

= 0.8.0-beta.1 =

Youth teams, and the life of a connection. A site that is not connected gains youth teams and the rule about deleted accounts, and is otherwise as it was.

* A lead can mark a team as a youth team. Its page names no lead, nobody can ask to join it, its missions take sign-ups from the organization's own people only, and no photograph or credit of its Spots goes to packout.spot.
* Deleting an account never deletes a Spot or a record of work. The person's name comes off them and they stay.
* On a connected site, an administrator can say that an organization here is the same as one on packout.spot, so its work is counted once. See "An organization that is also on packout.spot" above.
* The Connection screen downloads a copy of everything packout.spot holds from the site.
* A site that moved to a new address tells packout.spot and goes on, in place of disconnecting and connecting again.
* A site whose key is lost gets a new one by an emailed link and a question packout.spot asks the site.
* When the partner terms change, the site goes on sending until the day packout.spot names, and stops then if nobody accepted. Before this it stopped at once.
* Carries the shared Pack-Out Spot library, version 0.12.0. A claim, a move, a new key, and the copy need packout.spot at 0.28.0 or later, which it is.

= 0.7.0-beta.1 =

Joint campaigns, and the report of a program or a campaign. See "Joint campaigns" above.

* On a connected site an organization can take part in a joint campaign another organization or partner hosts: a lead accepts the invitation in the workspace, the campaign is then kept here unchangeable, and missions are filed under it.
* A lead can make a public campaign of your own joint, invite organizations packout.spot lists, take one out, and close it.
* An impact report of a campaign as a whole downloads as a PDF from its pane in the workspace, and a program's report now covers the program from its first day. Each has the figures its page shows.
* A campaign made joint or closed reaches packout.spot with the change, even when the campaign was changed a moment before.
* "The field app and caches" now says that WP Engine keeps the app's page unless asked not to.
* Carries the shared Pack-Out Spot library, version 0.11.0. Joint campaigns need packout.spot at 0.27.0 or later, which it is.

= 0.6.0-beta.1 =

The field app. See "The field app" above.

* A field app at your site's own address, for a phone with no signal: report a Spot with photographs, work a mission's checklist, mark Spots cleaned, and record a cleanup or a return visit. What waited is sent once when the signal is back.
* It carries your partner's name, your theme's main color, and your site's icon, and can be added to a home screen.
* It works when WordPress is in a subdirectory, and with a page cache plugin or a CDN in front of the site. "The field app and caches" says which three addresses must not be kept.
* On a connected site that shows packout.spot's Spots, the app keeps the ones inside a downloaded region, to be read with no signal.
* When two phones answered the same thing on a mission differently, its lead chooses which stands. A coordinator lists map regions under Pack-Out Spot, Settings.
* After an update of the plugin a phone gets the new app on its next visit. In earlier versions the app's page and the part that keeps it on the phone could disagree about the version.
* Carries the shared Pack-Out Spot library, version 0.10.0. A connected site needs packout.spot at 0.25.0 or later, which it is.

= 0.5.0-beta.1 =

Setting the site up. See "Setting up" above.

* Pack-Out Spot, Setup: eight steps that name the partner, make a first organization, make the pages, and place the map. It never touches the front page, the menus, or the theme.
* Pack-Out Spot, Pages: every page, how it stands, and Update, Keep mine, a draft copy to compare, or Replace. An edited page is never written to without Replace.
* The partner's logo and cover go to packout.spot with its profile.
* Tools, Site Health and `wp packout` report how the site and its connection stand.
* Suggested privacy policy text, and the plugin's part in exporting and erasing a person's data.
* A partner badge block, shown while the site is connected, with the site's public totals under it if you choose.
* On a connected site the overview says when sending to packout.spot has stopped, and why.
* Disconnect asks for a tick before it does anything.
* Deleting the plugin with "Remove all Pack-Out Spot data" ticked now removes every record the plugin made. The pages and the user accounts stay.
* The plugin can be translated.
* The report form no longer runs off the side of a phone in themes that do not limit the width of a form's fields.
* Carries the shared Pack-Out Spot library, version 0.9.1. A connected site needs packout.spot at 0.25.0 or later, which it is.

= 0.4.0-beta.1 =

The Spot exchange, for a connected site. See "Connecting to packout.spot" above. A site that is not connected works as before.

* A Spot is on packout.spot's map within about a minute of being reported: rounded and bare until a coordinator publishes it, then as your own page shows it, with its public photos.
* The Spots screen says how each Spot stands on packout.spot, and why when a coordinator there hid or returned it.
* Pack-Out Spot, Connection: show packout.spot's Spots, Places, and map features on your map, for a region you name.
* A mission can take on a Spot reported on packout.spot. The work is recorded here and counted once, on packout.spot.
* A lead can ask packout.spot to let an organization adopt a Place there.
* Carries the shared Pack-Out Spot library, version 0.8.0.

= 0.3.0-beta.1 =

The connection to packout.spot.

* Pack-Out Spot, Connection: connect the site, see how the connection stands, what is waiting, and what was sent, send now, replace the key, and disconnect.
* A connected site lists its public organizations, teams, programs, campaigns, and missions on packout.spot and sends its totals. Nothing about a person is sent.
* Deactivating stops the sending. Deleting the plugin with "Remove all Pack-Out Spot data" ticked also removes the connection and its key.
* Carries the shared Pack-Out Spot library, version 0.7.0.

= 0.2.0-beta.1 =

The application arrives. See "What is in this version" above.

* Spots, the report form, the map, organizations and teams, campaigns, missions, programs, the calendar, impact records, accounts, the workspace, and the dashboard screens.
* The site's one partner is made when the plugin is activated.
* Settings, Pack-Out Spot: the first word of each kind of record's address.
* The map shows the thousand most recently changed Spots. On a site with more, it adds the Spots of the area on view each time it is moved.
* Carries the shared Pack-Out Spot library, version 0.6.2, and Dompdf 3.1.6.

= 0.1.0-beta.3 =

* Carries the shared Pack-Out Spot library, version 0.3.0. Nothing a site's owner sees changes.

= 0.1.0-beta.2 =

* Carries the shared Pack-Out Spot library, version 0.2.0. Nothing a site's owner sees changes.

= 0.1.0-beta.1 =

The first beta. The plugin installs on WordPress 6.8 and PHP 8.2 or later, refuses to run beside Pack-Out Spot or for a whole network, takes signed updates from packout.spot, and has a settings screen with the beta choice and the choice to remove data when the plugin is deleted.
