<?php
/**
 * The partner record's rules.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a partner is, the states it moves through, and what each state allows.
 *
 * A partner answers for one or more organizations. This class holds the
 * rules that are the same on the hub and on a partner's own site, and reads
 * nothing from WordPress. The plugin stores the record and asks here before
 * it acts.
 */
final class Partner {

	/**
	 * The post type a partner is stored as.
	 */
	public const POST_TYPE = 'pos_partner';

	/**
	 * The meta key on an organization that names its partner.
	 */
	public const ORGANIZATION_META = '_pos_partner';

	/**
	 * Every state, in the order a partner usually meets them.
	 */
	public const STATES = array( 'pending', 'active', 'suspended', 'dormant', 'disconnected' );

	/**
	 * How many co-leads a partner may have beside its lead.
	 */
	public const MAX_COLEADS = 10;

	/**
	 * The longest description, in characters.
	 */
	public const MAX_DESCRIPTION = 2000;

	/**
	 * What each state allows.
	 *
	 * - `profile`      Change the name, description, region, and website, and invite co-leads.
	 * - `images`       Set a logo and a cover.
	 * - `organization` File an organization under it, or create one.
	 * - `program`      Open a program.
	 * - `message`      Write to the people of its organizations.
	 * - `page`         Have a public page, for a listed partner.
	 * - `directory`    Appear in the directory and the map's partner filter, for a listed partner.
	 * - `totals`       Have its figures shown in public.
	 */
	private const ALLOWS = array(
		'pending'      => array( 'profile' ),
		'active'       => array( 'profile', 'images', 'organization', 'program', 'message', 'page', 'directory', 'totals' ),
		'suspended'    => array( 'profile' ),
		'dormant'      => array( 'profile', 'images', 'page', 'totals' ),
		'disconnected' => array(),
	);

	/**
	 * The steps that change a state: step to the states it may be taken
	 * from, and the state it leads to.
	 *
	 * `decline` leads to no state. The plugin removes the record.
	 */
	private const STEPS = array(
		'approve'    => array(
			'from' => array( 'pending' ),
			'to'   => 'active',
		),
		'decline'    => array(
			'from' => array( 'pending' ),
			'to'   => '',
		),
		'suspend'    => array(
			'from' => array( 'active', 'dormant' ),
			'to'   => 'suspended',
		),
		'lift'       => array(
			'from' => array( 'suspended' ),
			'to'   => 'active',
		),
		'quiet'      => array(
			'from' => array( 'active' ),
			'to'   => 'dormant',
		),
		'heard'      => array(
			'from' => array( 'dormant' ),
			'to'   => 'active',
		),
		'disconnect' => array(
			'from' => array( 'pending', 'active', 'suspended', 'dormant' ),
			'to'   => 'disconnected',
		),
	);

	/**
	 * The steps only a partner that is its own site can take.
	 */
	private const REMOTE_STEPS = array( 'quiet', 'heard', 'disconnect' );

	/**
	 * A stored state, made safe. Anything unknown reads as `pending`, the
	 * state that allows least in public.
	 *
	 * @param mixed $value What was stored.
	 */
	public static function state( $value ): string {
		return is_string( $value ) && in_array( $value, self::STATES, true ) ? $value : 'pending';
	}

	/**
	 * A stored origin, made safe: empty for a partner kept on this site,
	 * `remote` for a partner that is its own site.
	 *
	 * @param mixed $value What was stored.
	 */
	public static function origin( $value ): string {
		return 'remote' === $value ? 'remote' : '';
	}

	/**
	 * Whether a state allows an act.
	 *
	 * @param string $state A state.
	 * @param string $act   One of the acts listed on ALLOWS.
	 */
	public static function allows( string $state, string $act ): bool {
		return in_array( $act, self::ALLOWS[ self::state( $state ) ], true );
	}

	/**
	 * Whether the public may see a partner: its page when `$act` is `page`,
	 * its card and filter when `directory`, its figures when `totals`.
	 *
	 * @param string $state  A state.
	 * @param bool   $listed Whether the partner chose to be listed.
	 * @param string $act    `page`, `directory`, or `totals`.
	 */
	public static function shown( string $state, bool $listed, string $act = 'page' ): bool {
		return $listed && in_array( $act, array( 'page', 'directory', 'totals' ), true ) && self::allows( $state, $act );
	}

	/**
	 * The state a step leads to.
	 *
	 * @param string $state  The state now.
	 * @param string $step   `approve`, `decline`, `suspend`, `lift`, `quiet`, `heard`, or `disconnect`.
	 * @param string $origin The partner's origin. Three steps are for a partner site alone.
	 * @return string|null The next state, an empty string when the step removes the record, or null when the step cannot be taken.
	 */
	public static function step( string $state, string $step, string $origin = '' ): ?string {
		if ( ! isset( self::STEPS[ $step ] ) ) {
			return null;
		}
		if ( in_array( $step, self::REMOTE_STEPS, true ) && 'remote' !== self::origin( $origin ) ) {
			return null;
		}
		if ( ! in_array( self::state( $state ), self::STEPS[ $step ]['from'], true ) ) {
			return null;
		}
		return self::STEPS[ $step ]['to'];
	}

	/**
	 * The steps that can be taken from a state.
	 *
	 * @param string $state  The state now.
	 * @param string $origin The partner's origin.
	 * @return array<int, string>
	 */
	public static function steps( string $state, string $origin = '' ): array {
		$open = array();
		foreach ( array_keys( self::STEPS ) as $step ) {
			if ( null !== self::step( $state, $step, $origin ) ) {
				$open[] = $step;
			}
		}
		return $open;
	}

	/**
	 * The co-leads a partner keeps from a list: whole user ids, each once,
	 * never the lead, and no more than the limit. The first ten stay.
	 *
	 * @param array<int, mixed> $ids  User ids as stored or submitted.
	 * @param int               $lead The lead's user id.
	 * @return array<int, int>
	 */
	public static function coleads( array $ids, int $lead = 0 ): array {
		$kept = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) {
				continue;
			}
			$id = (int) $id;
			if ( $id > 0 && $id !== $lead && ! in_array( $id, $kept, true ) ) {
				$kept[] = $id;
			}
		}
		return array_slice( $kept, 0, self::MAX_COLEADS );
	}

	/**
	 * Whether a partner has room for one more co-lead.
	 *
	 * @param array<int, mixed> $ids  Its co-leads now.
	 * @param int               $lead The lead's user id.
	 */
	public static function has_room( array $ids, int $lead = 0 ): bool {
		return count( self::coleads( $ids, $lead ) ) < self::MAX_COLEADS;
	}

	/**
	 * Whether a person leads a partner, as its lead or a co-lead.
	 *
	 * @param int               $user    The person.
	 * @param int               $lead    The lead's user id.
	 * @param array<int, mixed> $coleads The co-leads' user ids.
	 */
	public static function is_led_by( int $user, int $lead, array $coleads ): bool {
		if ( $user <= 0 ) {
			return false;
		}
		return $user === $lead || in_array( $user, self::coleads( $coleads, $lead ), true );
	}

	/**
	 * What a partner's leads may do for an organization filed under it.
	 *
	 * They may do what that organization's co-lead may. Three acts stay with
	 * the organization's own lead and with coordinators.
	 *
	 * @param string $act What is being done to the organization.
	 */
	public static function reaches( string $act ): bool {
		return ! in_array( $act, array( 'remove_lead', 'hand_over', 'remove' ), true );
	}
}
