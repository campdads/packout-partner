<?php
/**
 * The code that performs each act of the capability list on a partner site.
 *
 * Keyed by the act's id in `lib/packout-core/capabilities.php`. The parity
 * check fails when an act the plugin should have by now is missing here, when
 * one that belongs to the hub alone is listed, or when a handler cannot be
 * called.
 *
 * Nearly every act is performed by the library, as on the hub. Three are this
 * plugin's own or differ from the hub's: the report form, the choice of
 * addresses, and the partner's overview, which on a partner site is the
 * site's. Some the hub does not have at all: the site's connection to it,
 * the hub's layers on the site's map, and taking a Spot of the hub's on.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

use PackOut\Core\App\Accounts;
use PackOut\Core\App\Admin;
use PackOut\Core\App\Adoptions;
use PackOut\Core\App\Approvals;
use PackOut\Core\App\Blocks;
use PackOut\Core\App\Calendar;
use PackOut\Core\App\Campaigns;
use PackOut\Core\App\Claims;
use PackOut\Core\App\Connection_Screen;
use PackOut\Core\App\Export_Screen;
use PackOut\Core\App\Health;
use PackOut\Core\App\Hub_Layers;
use PackOut\Core\App\Impact;
use PackOut\Core\App\Impact_Screen;
use PackOut\Core\App\Invitations;
use PackOut\Core\App\Invitations_Screen;
use PackOut\Core\App\Joint;
use PackOut\Core\App\Messages;
use PackOut\Core\App\Missions;
use PackOut\Core\App\Missions_Screen;
use PackOut\Core\App\Overview_Page;
use PackOut\Core\App\Overview_Report;
use PackOut\Core\App\Pages;
use PackOut\Core\App\Partner_Workspace;
use PackOut\Core\App\Partners;
use PackOut\Core\App\People_Screen;
use PackOut\Core\App\Privacy;
use PackOut\Core\App\Profile;
use PackOut\Core\App\Programs;
use PackOut\Core\App\Programs_Screen;
use PackOut\Core\App\Resources;
use PackOut\Core\App\Rest;
use PackOut\Core\App\Search;
use PackOut\Core\App\Settings_Screen as App_Settings_Screen;
use PackOut\Core\App\Sign_In;
use PackOut\Core\App\Signups;
use PackOut\Core\App\Sponsors;
use PackOut\Core\App\Spots;
use PackOut\Core\App\Spots_Screen;
use PackOut\Core\App\Subteams;
use PackOut\Core\App\Teams;
use PackOut\Core\App\Teams_Screen;
use PackOut\Core\App\Updates;
use PackOut\Core\App\Wizard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Spots.
	'spot.report.form'      => array( Report_Form::class, 'handle' ),
	'spot.edit.own'         => array( Profile::class, 'save_spot' ),
	'spot.credit'           => array( Spots::class, 'set_credit' ),
	'spot.note'             => array( Updates::class, 'note' ),
	'spot.update'           => array( Updates::class, 'add' ),
	'spot.review'           => array( Spots::class, 'publish' ),
	'spot.coarsen'          => array( Spots::class, 'set_precision' ),
	'spot.hazard'           => array( Spots::class, 'set_hazard' ),
	'spot.clear'            => array( Spots::class, 'clear' ),
	'spot.duplicate'        => array( Spots::class, 'mark_duplicate' ),
	'spot.photos.hide'      => array( Spots::class, 'set_photos_public' ),
	'spot.claim'            => array( Claims::class, 'submit' ),
	'spot.claim.confirm'    => array( Claims::class, 'confirm' ),
	'spot.return'           => array( Impact::class, 'save_visit' ),
	'spot.exact'            => array( Spots::class, 'view_for' ),
	'spot.update.remove'    => array( Updates::class, 'remove' ),
	'spot.claim.followup'   => array( Claims::class, 'add_photo' ),
	'spot.stage'            => array( Spots::class, 'set_status' ),
	'spot.hub.adopt'        => array( Adoptions::class, 'adopt' ),

	// Map and search.
	'map.view'              => array( Blocks::class, 'render_map' ),
	'map.search'            => array( Search::class, 'rest' ),
	'map.layers.hub'        => array( Hub_Layers::class, 'save' ),
	'place.adopt.request'   => array( Adoptions::class, 'request_place' ),

	// Organizations and teams.
	'org.propose'           => array( Teams::class, 'propose' ),
	'org.approve'           => array( Teams::class, 'approve' ),
	'org.profile'           => array( Teams::class, 'update_profile' ),
	'org.planning'          => array( Teams::class, 'update_profile' ),
	'org.join'              => array( Teams::class, 'ask' ),
	'org.join.answer'       => array( Teams::class, 'answer' ),
	'org.invite'            => array( Teams::class, 'invite' ),
	'org.member.add'        => array( Teams::class, 'add_member' ),
	'org.member.remove'     => array( Teams::class, 'remove_member' ),
	'org.colead'            => array( Teams::class, 'set_colead' ),
	'org.handover'          => array( Teams::class, 'hand_over' ),
	'org.message'           => array( Messages::class, 'send' ),
	'org.resource'          => array( Resources::class, 'add' ),
	'org.sponsor'           => array( Sponsors::class, 'save' ),
	'org.contact.share'     => array( Profile::class, 'save_contact' ),
	'team.manage'           => array( Subteams::class, 'save' ),
	'team.run'              => array( Subteams::class, 'set_member' ),
	'team.join'             => array( Subteams::class, 'ask' ),
	'org.overview'          => array( Overview_Page::class, 'render' ),
	'org.manage'            => array( Teams::class, 'save' ),
	'org.invite.accept'     => array( Accounts::class, 'accept' ),
	'org.invite.revoke'     => array( Invitations::class, 'revoke' ),
	'org.answer.link'       => array( Approvals::class, 'handle' ),
	'org.mail.quiet'        => array( Profile::class, 'handle' ),
	'org.message.read'      => array( Messages::class, 'view' ),
	'team.join.answer'      => array( Subteams::class, 'answer' ),
	'team.colead'           => array( Subteams::class, 'set_colead' ),
	'team.youth'            => array( Subteams::class, 'set_youth' ),

	// Partners and programs.
	'partner.profile'       => array( Partners::class, 'update_profile' ),
	'partner.colead'        => array( Partners::class, 'set_colead' ),
	'partner.org.create'    => array( Partners::class, 'create_organization' ),
	'partner.message'       => array( Messages::class, 'send' ),
	'partner.workspace'     => array( Partner_Workspace::class, 'render' ),
	'partner.overview'      => array( Admin::class, 'overview' ),
	'program.manage'        => array( Programs::class, 'save' ),
	'program.file'          => array( Programs::class, 'file' ),
	'program.report'        => array( Overview_Report::class, 'download' ),

	// Campaigns and missions.
	'campaign.plan'         => array( Campaigns::class, 'save' ),
	'campaign.report'       => array( Overview_Report::class, 'download' ),
	'campaign.joint.host'   => array( Joint::class, 'invite' ),
	'campaign.joint.answer' => array( Joint::class, 'answer' ),
	'mission.plan'          => array( Missions::class, 'save' ),
	'mission.assign'        => array( Missions::class, 'save' ),
	'mission.start'         => array( Missions::class, 'set_status' ),
	'mission.complete'      => array( Missions::class, 'set_status' ),
	'mission.calloff'       => array( Missions::class, 'set_status' ),
	'mission.repeat'        => array( Missions::class, 'repeat' ),
	'mission.work'          => array( Missions::class, 'progress' ),
	'event.signup'          => array( Signups::class, 'sign_up' ),
	'calendar.view'         => array( Calendar::class, 'events' ),

	// Impact.
	'impact.record'         => array( Impact::class, 'save_cleanup' ),
	'impact.verify'         => array( Impact::class, 'verify' ),
	'impact.count'          => array( Impact::class, 'set_visible' ),
	'impact.totals'         => array( Impact::class, 'totals' ),
	'impact.own'            => array( Profile::class, 'contributions' ),
	'impact.change'         => array( Impact::class, 'update' ),

	// Accounts.
	'account.signup'        => array( Accounts::class, 'finish' ),
	'account.signin'        => array( Sign_In::class, 'enter' ),
	'account.profile'       => array( Profile::class, 'save_about' ),
	'account.data'          => array( Privacy::class, 'erase' ),

	// Field app.
	'spot.report.app'       => array( Rest::class, 'put_spot' ),
	'mission.decide'        => array( Missions::class, 'decide' ),
	'admin.regions'         => array( App_Settings_Screen::class, 'save' ),
	'field.capture'         => array( Rest::class, 'sync' ),
	'field.region'          => array( Rest::class, 'regions' ),
	'field.mission'         => array( Rest::class, 'progress' ),
	'field.cleaned'         => array( Rest::class, 'cleaned' ),

	// Admin.
	'admin.overview'        => array( Admin::class, 'overview' ),
	'admin.spots'           => array( Spots_Screen::class, 'render' ),
	'admin.missions'        => array( Missions_Screen::class, 'render' ),
	'admin.organizations'   => array( Teams_Screen::class, 'render' ),
	'admin.programs'        => array( Programs_Screen::class, 'render' ),
	'admin.people'          => array( People_Screen::class, 'render' ),
	'admin.invitations'     => array( Invitations_Screen::class, 'render' ),
	'admin.impact'          => array( Impact_Screen::class, 'render' ),
	'admin.export'          => array( Export_Screen::class, 'download' ),
	'admin.report'          => array( Overview_Report::class, 'download' ),
	'admin.settings'        => array( App_Settings_Screen::class, 'render' ),
	'admin.addresses'       => array( Addresses::class, 'save' ),
	'admin.people.edit'     => array( People_Screen::class, 'action' ),
	'admin.connection'      => array( Connection_Screen::class, 'perform' ),
	'admin.pages'           => array( Pages::class, 'act' ),
	'admin.wizard'          => array( Wizard::class, 'perform' ),
	'admin.health'          => array( Health::class, 'checks' ),
);
