<?php
/**
 * The capability list.
 *
 * Every act a person can take in Pack-Out Spot, who may take it, and which
 * plugin has it. This file is the source. `docs/capabilities.md` is written
 * from it by `bin/capabilities-doc.php`, and the parity test in each plugin
 * reads it.
 *
 * A row:
 *
 * - `group`   The heading it is listed under.
 * - `act`     What the person does.
 * - `who`     Who may do it.
 * - `surface` `both`, `hub`, or `partner`.
 * - `reason`  Why only one plugin has it. Required unless the surface is `both`.
 *             For a `both` row it may note how the act differs on a partner site.
 * - `since`   The phase of the partner build that gives each plugin the act.
 *             Zero means the hub had it before the build began. The parity
 *             test skips a row whose phase the plugin has not reached.
 *
 * A new act is added here first. A handler for an id that is not here fails
 * the parity test.
 *
 * @package PackOut_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	// Spots.
	'spot.report.form'      => array(
		'group'   => 'Spots',
		'act'     => 'Report a Spot from the website form',
		'who'     => 'Visitor',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.report.app'       => array(
		'group'   => 'Spots',
		'act'     => 'Report a Spot from the field app, with photographs',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 9,
		),
	),
	'spot.edit.own'         => array(
		'group'   => 'Spots',
		'act'     => 'Change one’s own report while it waits for review',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.credit'           => array(
		'group'   => 'Spots',
		'act'     => 'Choose who is credited on one’s own Spot',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.note'             => array(
		'group'   => 'Spots',
		'act'     => 'Keep private notes on a Spot, and show one',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.update'           => array(
		'group'   => 'Spots',
		'act'     => 'Share an update on a Spot’s page',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.review'           => array(
		'group'   => 'Spots',
		'act'     => 'Publish, keep private, or return a report',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.coarsen'          => array(
		'group'   => 'Spots',
		'act'     => 'Set public precision',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.hazard'           => array(
		'group'   => 'Spots',
		'act'     => 'Mark a hazard value',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.clear'            => array(
		'group'   => 'Spots',
		'act'     => 'Record a clearance',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.duplicate'        => array(
		'group'   => 'Spots',
		'act'     => 'Link a Spot as a duplicate of another',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.photos.hide'      => array(
		'group'   => 'Spots',
		'act'     => 'Hide or show a Spot’s photographs',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.claim'            => array(
		'group'   => 'Spots',
		'act'     => '“I cleaned this Spot” from its page',
		'who'     => 'Visitor',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.claim.confirm'    => array(
		'group'   => 'Spots',
		'act'     => 'Confirm or dismiss a cleanup report',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.return'           => array(
		'group'   => 'Spots',
		'act'     => 'File a return visit: still clear, or it’s back',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.exact'            => array(
		'group'   => 'Spots',
		'act'     => 'See the exact pin of a reviewed Spot',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.remote.hide'      => array(
		'group'   => 'Spots',
		'act'     => 'Hide, return, or coarsen a partner’s Spot',
		'who'     => 'Coordinator',
		'surface' => 'hub',
		'reason'  => 'Only the hub holds other sites’ Spots',
		'since'   => array( 'hub' => 7 ),
	),
	'spot.remote.hold'      => array(
		'group'   => 'Spots',
		'act'     => 'Hold a partner’s Spots for review',
		'who'     => 'Coordinator',
		'surface' => 'hub',
		'reason'  => 'Only the hub holds other sites’ Spots',
		'since'   => array( 'hub' => 7 ),
	),
	'spot.hub.adopt'        => array(
		'group'   => 'Spots',
		'act'     => 'Take a hub Spot into a mission',
		'who'     => 'Lead, planner',
		'surface' => 'partner',
		'reason'  => 'A hub mission includes a hub Spot directly',
		'since'   => array( 'partner' => 7 ),
	),
	'spot.update.remove'    => array(
		'group'   => 'Spots',
		'act'     => 'Remove one’s own update or note; a coordinator removes any',
		'who'     => 'Reporter, coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.claim.followup'   => array(
		'group'   => 'Spots',
		'act'     => 'Add a photograph to one’s cleanup report and choose its credit',
		'who'     => 'Whoever filed it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'spot.stage'            => array(
		'group'   => 'Spots',
		'act'     => 'Set a Spot’s stage by hand, override its credit, or remove it',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),

	// Map and search.
	'map.view'              => array(
		'group'   => 'Map and search',
		'act'     => 'See the public map, filter, and open a card',
		'who'     => 'Visitor',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'map.search'            => array(
		'group'   => 'Map and search',
		'act'     => 'Search the map',
		'who'     => 'Visitor',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'map.filter.partner'    => array(
		'group'   => 'Map and search',
		'act'     => 'Filter the map to one partner',
		'who'     => 'Visitor',
		'surface' => 'hub',
		'reason'  => 'A partner site is one partner',
		'since'   => array( 'hub' => 3 ),
	),
	'map.layers.hub'        => array(
		'group'   => 'Map and search',
		'act'     => 'Show hub Spots, Places, and features as layers',
		'who'     => 'Administrator',
		'surface' => 'partner',
		'reason'  => 'The hub has them as its own records',
		'since'   => array( 'partner' => 7 ),
	),
	'feature.author'        => array(
		'group'   => 'Map and search',
		'act'     => 'Add, change, or import a map feature',
		'who'     => 'Reporter',
		'surface' => 'hub',
		'reason'  => 'Map features are the hub’s shared layer',
		'since'   => array( 'hub' => 0 ),
	),
	'feature.suggest'       => array(
		'group'   => 'Map and search',
		'act'     => 'Suggest a change to a feature',
		'who'     => 'Reporter',
		'surface' => 'hub',
		'reason'  => 'As above',
		'since'   => array( 'hub' => 0 ),
	),
	'place.outline'         => array(
		'group'   => 'Map and search',
		'act'     => 'Outline and list a Place',
		'who'     => 'Coordinator',
		'surface' => 'hub',
		'reason'  => 'Places are the hub’s shared geography',
		'since'   => array( 'hub' => 0 ),
	),
	'place.adopt.request'   => array(
		'group'   => 'Map and search',
		'act'     => 'Ask to adopt a Place',
		'who'     => 'Member',
		'surface' => 'both',
		'reason'  => 'On a partner site a lead of the organization asks the hub',
		'since'   => array(
			'hub'     => 0,
			'partner' => 7,
		),
	),
	'place.adopt.answer'    => array(
		'group'   => 'Map and search',
		'act'     => 'Accept or decline an adoption request',
		'who'     => 'Lead; coordinator for a partner’s request',
		'surface' => 'hub',
		'reason'  => 'Places are the hub’s',
		'since'   => array( 'hub' => 0 ),
	),
	'place.adopt.record'    => array(
		'group'   => 'Map and search',
		'act'     => 'Record or end an adoption directly, attach Spots to a Place, and set its sponsors',
		'who'     => 'Coordinator',
		'surface' => 'hub',
		'reason'  => 'Places are the hub’s shared geography',
		'since'   => array( 'hub' => 0 ),
	),
	'feature.update'        => array(
		'group'   => 'Map and search',
		'act'     => 'Add a photograph or an update to a map feature’s page',
		'who'     => 'Reporter',
		'surface' => 'hub',
		'reason'  => 'Map features are the hub’s shared layer',
		'since'   => array( 'hub' => 0 ),
	),

	// Organizations and teams.
	'org.propose'           => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Propose an organization',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.approve'           => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Approve or decline an organization',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.profile'           => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Change profile, address, logo, cover, listing, join policy',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.planning'          => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Let members plan',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.join'              => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Join, ask to join, withdraw, leave',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.join.answer'       => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Accept or decline a request to join',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.invite'            => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Invite by email',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.member.add'        => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Add a person directly',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.member.remove'     => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Remove a member',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.colead'            => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Name or remove a co-lead',
		'who'     => 'Lead, not a co-lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.handover'          => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Hand the organization to another member',
		'who'     => 'Lead, not a co-lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.message'           => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Write to the organization, a team, or a mission’s people',
		'who'     => 'Lead, team lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.resource'          => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Add or remove a resource at a level one leads',
		'who'     => 'Lead, team lead, planner',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.sponsor'           => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Add, change, or remove a sponsor',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.contact.share'     => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Share a phone or address with one’s leads',
		'who'     => 'Member',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'team.manage'           => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Create, rename, remove teams and name their leads',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'team.run'              => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Add and remove a team’s members, set its page, logo, cover, join rule',
		'who'     => 'Team lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'team.join'             => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Join, ask to join, or leave a team',
		'who'     => 'Member',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'team.youth'            => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Mark a team as a youth team',
		'who'     => 'Lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 11,
			'partner' => 11,
		),
	),
	'org.overview'          => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Open the organization’s overview',
		'who'     => 'Lead, coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'org.manage'            => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Create, change, or remove an organization directly',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.invite.accept'     => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Accept an invitation',
		'who'     => 'Whoever was invited',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.invite.revoke'     => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Take an invitation back',
		'who'     => 'Lead, coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.answer.link'       => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Answer a request from the one-time link in its email, without signing in',
		'who'     => 'Whoever the email was sent to',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.mail.quiet'        => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Stop an organization’s email from the link in a message',
		'who'     => 'Member',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'org.message.read'      => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Read the organization’s messages',
		'who'     => 'Member',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'team.join.answer'      => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Accept or decline a request to join a team',
		'who'     => 'Team lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'team.colead'           => array(
		'group'   => 'Organizations and teams',
		'act'     => 'Name or remove a team’s co-lead',
		'who'     => 'Lead, team lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),

	// Partners and programs.
	'partner.propose'       => array(
		'group'   => 'Partners and programs',
		'act'     => 'Propose a partner',
		'who'     => 'A lead of an active organization',
		'surface' => 'hub',
		'reason'  => 'A partner site is one partner, made by its wizard',
		'since'   => array( 'hub' => 3 ),
	),
	'partner.approve'       => array(
		'group'   => 'Partners and programs',
		'act'     => 'Approve, decline, suspend, or lift a partner',
		'who'     => 'Coordinator with `packout_partner`',
		'surface' => 'hub',
		'reason'  => 'As above',
		'since'   => array( 'hub' => 3 ),
	),
	'partner.profile'       => array(
		'group'   => 'Partners and programs',
		'act'     => 'Change the partner’s profile, address, logo, cover, listing',
		'who'     => 'Partner lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 8,
		),
	),
	'partner.colead'        => array(
		'group'   => 'Partners and programs',
		'act'     => 'Name or remove a partner co-lead; hand over',
		'who'     => 'Partner lead, not a co-lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 8,
		),
	),
	'partner.org.create'    => array(
		'group'   => 'Partners and programs',
		'act'     => 'Create an organization under the partner',
		'who'     => 'Partner lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'partner.org.claim'     => array(
		'group'   => 'Partners and programs',
		'act'     => 'Ask an organization to join, answer, or leave',
		'who'     => 'Lead, partner lead',
		'surface' => 'hub',
		'reason'  => 'On a partner site every organization is the partner’s',
		'since'   => array( 'hub' => 3 ),
	),
	'partner.message'       => array(
		'group'   => 'Partners and programs',
		'act'     => 'Write to everyone in the partner’s organizations, or their leads',
		'who'     => 'Partner lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'partner.workspace'     => array(
		'group'   => 'Partners and programs',
		'act'     => 'Use the Partner workspace',
		'who'     => 'Partner lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'partner.overview'      => array(
		'group'   => 'Partners and programs',
		'act'     => 'Open the partner’s overview',
		'who'     => 'Partner lead, coordinator',
		'surface' => 'both',
		'reason'  => 'On a partner site it is the site overview',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'partner.directory'     => array(
		'group'   => 'Partners and programs',
		'act'     => 'Browse the directory of partners',
		'who'     => 'Visitor',
		'surface' => 'hub',
		'reason'  => 'A partner site is one partner',
		'since'   => array( 'hub' => 3 ),
	),
	'program.manage'        => array(
		'group'   => 'Partners and programs',
		'act'     => 'Open, change, close, or remove a program',
		'who'     => 'The owner’s lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'program.file'          => array(
		'group'   => 'Partners and programs',
		'act'     => 'File a campaign or mission under a program',
		'who'     => 'Lead, planner',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'program.report'        => array(
		'group'   => 'Partners and programs',
		'act'     => 'Download a program’s impact report',
		'who'     => 'Whoever may change it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 10,
			'partner' => 10,
		),
	),

	// Campaigns and missions.
	'campaign.plan'         => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Plan, change, or remove a campaign',
		'who'     => 'Lead, planner',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'campaign.report'       => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Download a campaign’s impact report',
		'who'     => 'Whoever may change it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 10,
			'partner' => 10,
		),
	),
	'campaign.joint.host'   => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Open a joint campaign and invite participants',
		'who'     => 'Lead, partner lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 10,
			'partner' => 10,
		),
	),
	'campaign.joint.answer' => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Accept, decline, or leave a joint campaign',
		'who'     => 'Lead, partner lead',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 10,
			'partner' => 10,
		),
	),
	'mission.plan'          => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Plan, change, or remove a mission',
		'who'     => 'Lead, team lead for an assigned mission, planner',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'mission.assign'        => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Change a mission’s campaign, program, teams, sponsors',
		'who'     => 'Lead, planner',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'mission.start'         => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Start a mission',
		'who'     => 'Whoever may change it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'mission.complete'      => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Complete a mission and settle each Spot’s outcome',
		'who'     => 'Whoever may change it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'mission.calloff'       => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Call a mission off',
		'who'     => 'Whoever may change it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'mission.repeat'        => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Repeat a mission',
		'who'     => 'Whoever may change it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 4,
			'partner' => 5,
		),
	),
	'mission.work'          => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'See a mission’s exact pins and work its checklist',
		'who'     => 'Its crew',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'event.signup'          => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Sign up for a public campaign or mission, and take it back',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'calendar.view'         => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'See a calendar, switch its form, subscribe to its feed',
		'who'     => 'Visitor',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 4,
			'partner' => 5,
		),
	),
	'mission.decide'        => array(
		'group'   => 'Campaigns and missions',
		'act'     => 'Choose between a crew phone’s copy of a mission and the site’s',
		'who'     => 'Whoever may change it',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 9,
		),
	),

	// Impact.
	'impact.record'         => array(
		'group'   => 'Impact',
		'act'     => 'Record the result of work on a Spot',
		'who'     => 'Whoever did the work',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'impact.verify'         => array(
		'group'   => 'Impact',
		'act'     => 'Verify an impact record',
		'who'     => '`packout_impact`',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'impact.count'          => array(
		'group'   => 'Impact',
		'act'     => 'Count a record in public totals',
		'who'     => '`packout_impact` and `packout_team` together',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'impact.totals'         => array(
		'group'   => 'Impact',
		'act'     => 'See an organization’s, partner’s, program’s, or campaign’s totals',
		'who'     => 'Its leads; anyone, for what is public',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'impact.own'            => array(
		'group'   => 'Impact',
		'act'     => 'See one’s own impact and reported Spots',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'impact.partner.flag'   => array(
		'group'   => 'Impact',
		'act'     => 'Act on a flag raised on a partner’s figures',
		'who'     => 'Coordinator',
		'surface' => 'hub',
		'reason'  => 'Only the hub receives snapshots',
		'since'   => array( 'hub' => 11 ),
	),
	'impact.change'         => array(
		'group'   => 'Impact',
		'act'     => 'Change or remove an impact record, and add photographs to it',
		'who'     => 'Whoever recorded it, `packout_impact`',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),

	// Accounts.
	'account.signup'        => array(
		'group'   => 'Accounts',
		'act'     => 'Sign up and confirm by email',
		'who'     => 'Visitor',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'account.signin'        => array(
		'group'   => 'Accounts',
		'act'     => 'Sign in, sign out, reset a password',
		'who'     => 'Visitor',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'account.profile'       => array(
		'group'   => 'Accounts',
		'act'     => 'Change personal info, email preferences, sign-in details',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'account.join.network'  => array(
		'group'   => 'Accounts',
		'act'     => 'Join Pack-Out Spot with an existing network account',
		'who'     => 'A CampDads account holder',
		'surface' => 'hub',
		'reason'  => 'The CampDads network is the hub’s',
		'since'   => array( 'hub' => 0 ),
	),
	'account.data'          => array(
		'group'   => 'Accounts',
		'act'     => 'Export or erase a person’s data through WordPress’s privacy tools',
		'who'     => 'Administrator, at the person’s request',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 11,
			'partner' => 8,
		),
	),

	// Admin.
	'admin.overview'        => array(
		'group'   => 'Admin',
		'act'     => 'Open the site’s overview',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.spots'           => array(
		'group'   => 'Admin',
		'act'     => 'The Spots screen',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.missions'        => array(
		'group'   => 'Admin',
		'act'     => 'The Missions screen',
		'who'     => '`packout_lead`',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.organizations'   => array(
		'group'   => 'Admin',
		'act'     => 'The Organizations screen',
		'who'     => '`packout_screens`',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.programs'        => array(
		'group'   => 'Admin',
		'act'     => 'The Programs screen',
		'who'     => '`packout_lead`',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 3,
			'partner' => 5,
		),
	),
	'admin.people'          => array(
		'group'   => 'Admin',
		'act'     => 'The People screen',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.invitations'     => array(
		'group'   => 'Admin',
		'act'     => 'The Invitations screen, with resend and timeline',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.impact'          => array(
		'group'   => 'Admin',
		'act'     => 'The Impact screen',
		'who'     => '`packout_impact`',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.export'          => array(
		'group'   => 'Admin',
		'act'     => 'Download Spots as GeoJSON, impact and people as CSV',
		'who'     => '`packout_export`',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.report'          => array(
		'group'   => 'Admin',
		'act'     => 'Export the impact report from an overview',
		'who'     => 'Whoever may open that overview',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.settings'        => array(
		'group'   => 'Admin',
		'act'     => 'The Settings screen',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),
	'admin.places'          => array(
		'group'   => 'Admin',
		'act'     => 'The Places screen',
		'who'     => 'Coordinator',
		'surface' => 'hub',
		'reason'  => 'Places are the hub’s',
		'since'   => array( 'hub' => 0 ),
	),
	'admin.partners'        => array(
		'group'   => 'Admin',
		'act'     => 'The Partners screen',
		'who'     => '`packout_partner`',
		'surface' => 'hub',
		'reason'  => 'A partner site is one partner',
		'since'   => array( 'hub' => 3 ),
	),
	'admin.connection'      => array(
		'group'   => 'Admin',
		'act'     => 'Connect, see the connection, send now, replace the key, disconnect',
		'who'     => 'Administrator',
		'surface' => 'partner',
		'reason'  => 'The hub is what a site connects to',
		'since'   => array( 'partner' => 6 ),
	),
	'admin.pages'           => array(
		'group'   => 'Admin',
		'act'     => 'Create pages from templates and keep them current',
		'who'     => 'Administrator',
		'surface' => 'partner',
		'reason'  => 'The hub’s pages are built and deployed from `content/`',
		'since'   => array( 'partner' => 8 ),
	),
	'admin.wizard'          => array(
		'group'   => 'Admin',
		'act'     => 'The setup wizard',
		'who'     => 'Administrator',
		'surface' => 'partner',
		'reason'  => 'The hub is set up by `deploy/setup.php`',
		'since'   => array( 'partner' => 8 ),
	),
	'admin.health'          => array(
		'group'   => 'Admin',
		'act'     => 'See how the site and what it sends stand, in Site Health and from the command line',
		'who'     => 'Administrator',
		'surface' => 'partner',
		'reason'  => 'The hub is watched by `deploy/live-check.cjs` after each release',
		'since'   => array( 'partner' => 8 ),
	),
	'admin.addresses'       => array(
		'group'   => 'Admin',
		'act'     => 'Choose the base of each public path',
		'who'     => 'Administrator',
		'surface' => 'partner',
		'reason'  => 'The hub’s addresses are fixed',
		'since'   => array( 'partner' => 5 ),
	),
	'admin.regions'         => array(
		'group'   => 'Admin',
		'act'     => 'Publish offline map regions',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 9,
		),
	),
	'admin.people.edit'     => array(
		'group'   => 'Admin',
		'act'     => 'Change a person’s profile, contact details, mail choices, and memberships',
		'who'     => 'Coordinator',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 5,
		),
	),

	// Field app.
	'field.capture'         => array(
		'group'   => 'Field app',
		'act'     => 'Save a Spot offline and sync it',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 9,
		),
	),
	'field.region'          => array(
		'group'   => 'Field app',
		'act'     => 'Download a map region',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 9,
		),
	),
	'field.mission'         => array(
		'group'   => 'Field app',
		'act'     => 'Work a mission offline: checklist and outcomes',
		'who'     => 'Its crew',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 9,
		),
	),
	'field.cleaned'         => array(
		'group'   => 'Field app',
		'act'     => 'Mark one’s own Spot cleaned',
		'who'     => 'Reporter',
		'surface' => 'both',
		'reason'  => '',
		'since'   => array(
			'hub'     => 0,
			'partner' => 9,
		),
	),
);
