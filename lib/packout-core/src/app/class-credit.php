<?php
/**
 * Who a report or an update is credited to in public.
 *
 * @package PackOut_Core
 */

namespace PackOut\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A public page says who reported a Spot and who wrote an update on it.
 *
 * It names an organization when the person chose one of theirs. Otherwise it
 * says "an individual", and gives the person's name only when they ticked the
 * box that asks. An organization that is not listed is "an organization".
 * Nobody is named without having asked to be.
 *
 * In code an organization is a team: see Teams.
 */
class Credit {

	/**
	 * The organizations a person can report or write for: approved ones they lead or belong to.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, string> Name by team ID.
	 */
	public static function teams( int $user_id ): array {
		$teams = array();
		foreach ( Teams::of_user( $user_id ) as $id ) {
			$team = Teams::get( $id );
			if ( $team && Teams::active( $id ) ) {
				$teams[ $id ] = $team->post_title;
			}
		}
		asort( $teams, SORT_NATURAL | SORT_FLAG_CASE );
		return $teams;
	}

	/**
	 * Settle what a person asked for.
	 *
	 * @param int      $user_id The person, or 0 for a visitor.
	 * @param int|null $team    The organization they chose, 0 for none, or null when they were not asked:
	 *                          a person in an organization is then credited with it.
	 * @param bool     $named   Whether they asked to be named.
	 * @return array{team: int, named: bool}
	 */
	public static function choose( int $user_id, ?int $team, bool $named ): array {
		$teams = self::teams( $user_id );
		if ( null === $team ) {
			$team = $teams ? (int) array_key_first( $teams ) : 0;
		}
		return array(
			'team'  => isset( $teams[ $team ] ) ? $team : 0,
			'named' => $named,
		);
	}

	/**
	 * The credit as the public reads it.
	 *
	 * @param int    $team    Team ID, or 0.
	 * @param bool   $named   Whether the person asked to be named.
	 * @param int    $user_id The person, or 0 for a visitor.
	 * @param string $name    The name a visitor typed, kept only when they asked to be named.
	 * @return array{kind: string, label: string, url: string} kind is team, person, or individual.
	 */
	public static function line( int $team, bool $named, int $user_id, string $name = '' ): array {
		$record = $team > 0 ? Teams::get( $team ) : null;
		if ( $record ) {
			$listed = 'publish' === $record->post_status && Teams::active( $team ) && Post_Access::can_read( $record, 0 );
			return array(
				'kind'  => 'team',
				'label' => $listed ? $record->post_title : __( 'an organization', 'packout-core' ),
				'url'   => $listed ? (string) get_permalink( $record ) : '',
			);
		}
		if ( $named ) {
			$person = $user_id > 0 ? get_userdata( $user_id ) : false;
			$shown  = trim( $person ? (string) $person->display_name : $name );
			if ( '' !== $shown ) {
				return array(
					'kind'  => 'person',
					'label' => $shown,
					'url'   => '',
				);
			}
		}
		return array(
			'kind'  => 'individual',
			'label' => __( 'an individual', 'packout-core' ),
			'url'   => '',
		);
	}

	/**
	 * A credit as escaped HTML: a link to a listed organization, or plain words.
	 *
	 * @param array{kind: string, label: string, url: string} $line From line().
	 */
	public static function html( array $line ): string {
		return '' !== $line['url'] ? '<a href="' . esc_url( $line['url'] ) . '">' . esc_html( $line['label'] ) . '</a>' : esc_html( $line['label'] );
	}

	/**
	 * The two questions of a form: which organization, and whether to give the name.
	 *
	 * @param string                             $prefix  Start of the field names: <prefix>_team and <prefix>_named.
	 * @param int                                $user_id The person filling the form, or 0 for a visitor.
	 * @param string                             $what    What is being credited, for the help text: report or update.
	 * @param array{team: int, named: bool}|null $current What is chosen now, when the form changes a choice made before.
	 */
	public static function fields( string $prefix, int $user_id, string $what = 'report', ?array $current = null ): string {
		$teams = self::teams( $user_id );
		$id    = 'pos-' . sanitize_html_class( str_replace( '_', '-', $prefix ) );
		$html  = '<fieldset class="pos-credit"><legend>' . esc_html( 'update' === $what ? __( 'Who it is from', 'packout-core' ) : __( 'Who reported it', 'packout-core' ) ) . '</legend>';
		if ( $teams ) {
			$html .= '<p class="pos-credit__field"><label for="' . esc_attr( $id . '-team' ) . '">' . esc_html( 'update' === $what ? __( 'Post it for', 'packout-core' ) : __( 'Report it for', 'packout-core' ) ) . '</label> ';
			$html .= '<select id="' . esc_attr( $id . '-team' ) . '" name="' . esc_attr( $prefix . '_team' ) . '">';
			foreach ( $teams as $team => $name ) {
				$html .= '<option value="' . esc_attr( (string) $team ) . '"' . ( $current && $current['team'] === (int) $team ? ' selected' : '' ) . '>' . esc_html( $name ) . '</option>';
			}
			$html .= '<option value="0"' . ( $current && ! isset( $teams[ $current['team'] ] ) ? ' selected' : '' ) . '>' . esc_html__( 'Myself, not an organization', 'packout-core' ) . '</option></select></p>';
		}
		$html .= '<p class="pos-credit__field"><label><input type="checkbox" name="' . esc_attr( $prefix . '_named' ) . '" value="1"' . ( $current && $current['named'] ? ' checked' : '' ) . ' /> ' . esc_html__( 'Show my name', 'packout-core' ) . '</label></p>';
		if ( $teams ) {
			$help = 'update' === $what
				? __( 'The Spot\'s page shows the organization you choose. Posting as yourself, it says “an individual” unless you tick the box.', 'packout-core' )
				: __( 'The Spot\'s page says who reported it: the organization you choose. Reporting as yourself, it says “an individual” unless you tick the box.', 'packout-core' );
		} elseif ( $user_id > 0 ) {
			$help = 'update' === $what
				? __( 'The Spot\'s page says it is from “an individual” unless you tick the box.', 'packout-core' )
				: __( 'The Spot\'s page says it was reported by “an individual” unless you tick the box.', 'packout-core' );
		} else {
			$help = __( 'The Spot\'s page says it was reported by “an individual”. Tick the box and it gives the name you typed instead.', 'packout-core' );
		}
		return $html . '<p class="pos-credit__help">' . esc_html( $help ) . '</p></fieldset>';
	}
}
