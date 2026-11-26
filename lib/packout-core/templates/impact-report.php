<?php
/**
 * Impact report HTML, also rendered to PDF.
 *
 * One file draws both. It is self-contained: inline CSS, the logo as a data
 * URI, bars as sized blocks, no script, and no remote resource.
 *
 * The PDF renderer is Dompdf, which has no flexbox and no grid. Columns here
 * are tables for that reason. It has no color-mix() and does not resolve a
 * custom property on a table cell, so a color is written in the rules below as
 * {{name}} and replaced with the plain hex value Overview_Report::colors()
 * worked out. Rules under .is-pdf apply to the PDF only. Rules under the
 * screen media query apply to the HTML file opened in a browser.
 *
 * @package PackOut_Core
 *
 * @var array<string, mixed> $packout_report Report data, as Overview_Report::data() returns it.
 * @var array<string, mixed> $packout_view   Presentation values, as Overview_Report::view() returns them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$report   = $packout_report;
$view     = $packout_view;
$outcomes = $report['outcomes'] ?? array();
$outputs  = $report['outputs'] ?? array();
$effort   = $report['effort'] ?? array();
$cycle    = $report['from_report_to_cleanup'] ?? array();
$who      = $report['who'] ?? array();
$where    = (array) ( $report['where'] ?? array() );
$how      = $report['how_to_read'] ?? array();
$listed   = (array) ( $who['listed'] ?? array() );
$unlisted = (int) ( $who['unlisted'] ?? 0 );
$days     = (int) ( $report['period']['days'] ?? 0 );
$owner    = (string) ( $report['subject']['owner'] ?? '' );
$sites    = is_array( $report['partner_sites'] ?? null ) ? $report['partner_sites'] : array();
$heading  = (string) ( $report['title'] ?? '' );
// The report of one organization names its teams. Every other report names organizations.
$of_team = 'team' === ( $report['subject']['kind'] ?? '' );
/* translators: 1: first day of the period, 2: last day of the period. */
$period = sprintf( __( '%1$s – %2$s', 'packout-core' ), (string) $view['from'], (string) $view['to'] );

/**
 * A figure for print: grouped thousands, one decimal only when there is one, a dash when there is none.
 */
$num = static function ( $value ): string {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	$n = (float) $value;
	return number_format_i18n( $n, floor( $n ) === $n ? 0 : 1 );
};

/**
 * Width of a bar, as a whole percentage. A part that exists is never drawn at nothing.
 */
$share = static function ( $part, $whole ): int {
	$part  = (float) $part;
	$whole = (float) $whole;
	if ( $whole <= 0 || $part <= 0 ) {
		return 0;
	}
	return (int) max( 2, min( 100, round( 100 * $part / $whole ) ) );
};

/**
 * Class for a figure. A long one is set smaller so it stays inside its tile.
 */
$fit = static function ( string $figure, int $room ): string {
	return 'tile__num' . ( strlen( $figure ) > $room ? ' tile__num--long' : '' );
};

/**
 * One figure with its caption under it.
 */
$tile = static function ( string $figure, string $caption, int $room = 8 ) use ( $fit ): void {
	echo '<td class="tile"><span class="' . esc_attr( $fit( $figure, $room ) ) . '">' . esc_html( $figure ) . '</span><span class="tile__cap">' . esc_html( $caption ) . '</span></td>';
};

/**
 * One quantity counted two ways. The two figures sit side by side and are never added.
 */
$split = static function ( string $name, string $first, string $first_basis, string $second, string $second_basis, int $columns = 2 ) use ( $fit ): void {
	// Both figures take the smaller size together, so the pair still reads as a pair.
	$class = $fit( strlen( $first ) > strlen( $second ) ? $first : $second, 7 );
	echo '<td class="tile tile--split"><span class="tile__name">' . esc_html( $name ) . '</span>';
	echo '<table class="pair"><tr>';
	echo '<td><span class="' . esc_attr( $class ) . '">' . esc_html( $first ) . '</span><span class="tile__cap"><span class="dot dot--good"></span>' . esc_html( $first_basis ) . '</span></td>';
	echo '<td><span class="' . esc_attr( $class ) . '">' . esc_html( $second ) . '</span><span class="tile__cap"><span class="dot dot--plain"></span>' . esc_html( $second_basis ) . '</span></td>';
	// Empty cells keep the two figures in line with the tiles of the row above.
	echo str_repeat( '<td></td>', max( 0, $columns - 2 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup.
	echo '</tr></table></td>';
};

$visits   = (int) ( $outcomes['still_clear'] ?? 0 ) + (int) ( $outcomes['returned'] ?? 0 );
$reported = (int) ( $cycle['reported'] ?? 0 );
$cleaned  = (int) ( $cycle['cleaned'] ?? 0 );
$most     = max( $reported, $cleaned );
$stacked  = count( $listed ) > 40 || count( $where ) > 40;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( (string) $view['lang'] ); ?>">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $heading . ', ' . $period ); ?></title>
	<style>
		<?php ob_start(); ?>
		@page { margin: 54px 54px 76px; }

		/* The PDF renderer puts the page margin on the root element, so only the body is reset. */
		body { margin: 0; padding: 0; }
		body {
			color: {{ink}};
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			font-size: 12px;
			line-height: 1.5;
		}
		/* Helvetica is built into every PDF reader. A character it lacks is drawn in DejaVu Sans. */
		body.is-pdf { font-family: Helvetica, "DejaVu Sans", sans-serif; }

		h1, h2, p, ul { margin: 0; padding: 0; }
		table { border-collapse: collapse; }
		td { padding: 0; vertical-align: top; }

		/* Cover */
		.cover { padding: 26px 30px 28px; border-radius: 12px; background: {{ink}}; color: {{surface}}; }
		.brand { width: 100%; margin-bottom: 34px; }
		.brand td { vertical-align: middle; }
		.brand__logo { width: 36px; }
		.brand__logo img { display: block; width: 36px; height: 36px; border-radius: 8px; }
		.brand__name { padding-left: 12px; font-size: 13px; font-weight: bold; }
		.brand__site { color: {{cover-quiet}}; font-size: 11px; text-align: right; }
		.cover__rule { width: 40px; height: 3px; margin-bottom: 14px; border-radius: 2px; background: {{accent}}; }
		.cover h1 { font-size: 27px; font-weight: bold; line-height: 1.2; letter-spacing: -0.4px; }
		.cover__period { margin-top: 6px; font-size: 15px; }
		.cover__meta { margin-top: 16px; color: {{cover-quiet}}; font-size: 11px; }

		/* Summary */
		.eyebrow { margin-bottom: 6px; color: {{accent-ink}}; font-size: 11px; font-weight: bold; }
		.summary { margin-top: 26px; }
		.summary__lead { font-size: 15px; line-height: 1.55; }

		/* Sections: what it is on the left, the figures on the right. */
		.block { margin-top: 22px; padding-top: 22px; border-top: 1px solid {{line}}; page-break-inside: avoid; }
		.section { width: 100%; table-layout: fixed; }
		.section__head { width: 30%; padding-right: 24px; }
		.section h2 { margin-bottom: 5px; font-size: 14px; font-weight: bold; line-height: 1.3; }
		.section__note { color: {{quiet}}; font-size: 11px; line-height: 1.5; }
		.block--stack .section, .block--stack .section > tbody, .block--stack .section__row, .block--stack .section__head, .block--stack .section__body { display: block; width: auto; }
		.block--stack .section__head { padding: 0 0 14px; }
		.block--stack { page-break-inside: auto; }

		/* Tiles. The wrapper takes back the spacing the table puts outside its first and last cell. */
		.tiles-wrap { margin: 0 -8px; }
		.tiles-wrap + .tiles-wrap { margin-top: 8px; }
		.tiles { width: 100%; border-collapse: separate; border-spacing: 8px 0; table-layout: fixed; }
		.tile { padding: 12px 14px 13px; border-radius: 8px; background: {{tint}}; }
		.tile__num { display: block; font-size: 24px; font-weight: bold; line-height: 28px; letter-spacing: -0.4px; }
		.tile__num--long { font-size: 17px; letter-spacing: -0.2px; }
		.tile__cap { display: block; margin-top: 3px; color: {{quiet}}; font-size: 11px; line-height: 1.35; }
		.tile__name { display: block; margin-bottom: 8px; font-size: 11px; font-weight: bold; }
		.pair { width: 100%; table-layout: fixed; }
		.pair td { padding-right: 8px; }
		.dot { display: inline-block; width: 7px; height: 7px; margin-right: 5px; border-radius: 4px; }
		.dot--good { background: {{good}}; }
		.dot--plain { background: {{line-strong}}; }
		.dot--accent { background: {{accent}}; }

		/* Bars */
		.meter { margin-top: 12px; }
		.meter__track { height: 8px; border-radius: 4px; background: {{accent}}; }
		.meter__fill { height: 8px; border-radius: 4px; background: {{good}}; }
		.meter__key { margin-top: 7px; color: {{quiet}}; font-size: 11px; }
		.meter__key span.key { margin-right: 16px; }
		.rows { width: 100%; margin-top: 12px; }
		.rows td { padding: 3px 0; vertical-align: middle; font-size: 11px; }
		.rows__name { width: 72px; color: {{quiet}}; }
		.rows__value { width: 44px; font-weight: bold; text-align: right; }
		.rows__bar div { height: 8px; border-radius: 4px; }
		.bar--accent { background: {{accent}}; }
		.bar--good { background: {{good}}; }
		.quiet-line { margin-top: 10px; color: {{quiet}}; font-size: 11px; }

		/* Names */
		.chip { display: inline-block; vertical-align: top; margin: 0 6px 6px 0; padding: 4px 11px; border: 1px solid {{line-strong}}; border-radius: 12px; font-size: 11px; line-height: 15px; }
		.names__more { margin-top: 2px; color: {{quiet}}; font-size: 11px; }
		.names__none { color: {{quiet}}; font-size: 12px; }

		/* How to read this report */
		.howto { margin-top: 26px; padding: 16px 18px 14px; border-radius: 8px; background: {{tint}}; page-break-inside: avoid; }
		.howto h2 { margin-bottom: 6px; font-size: 12px; font-weight: bold; }
		.howto p { margin-bottom: 4px; font-size: 11px; line-height: 1.5; }
		.howto p.howto__when { margin: 8px 0 0; color: {{quiet}}; }

		/* Running footer, PDF only. It sits in the bottom page margin. */
		.foot { display: none; }
		.is-pdf .foot { display: block; position: fixed; right: 0; bottom: -46px; left: 0; padding-top: 9px; border-top: 1px solid {{line}}; color: {{quiet}}; font-size: 10px; }
		.foot table { width: 100%; }
		.foot__page { text-align: right; }
		.foot__page:after { content: counter(page); }

		/* The HTML file opened in a browser: one sheet, the width of the printed page. */
		@media screen {
			body { padding: 32px 16px; background: {{tint}}; }
			.sheet { max-width: 708px; margin: 0 auto; padding: 54px; border: 1px solid {{line}}; border-radius: 12px; background: {{surface}}; }
			.tile__num, .cover h1, .section h2, .brand__name, .eyebrow, .tile__name, .howto h2, .rows__value { font-weight: 600; }
		}
		@media screen and (max-width: 720px) {
			body { padding: 0; background: {{surface}}; }
			.sheet { padding: 20px; border: 0; border-radius: 0; }
			.cover { padding: 22px; }
			.section, .section > tbody, .section__row, .section__head, .section__body { display: block; width: auto; }
			.section__head { padding: 0 0 12px; }
			.tiles-wrap { margin: 0; }
			.tiles, .tiles > tbody { display: block; }
			.tiles > tbody > tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
			.tiles td.tile { display: block; }
			.tiles td.tile--split { grid-column: 1 / -1; }
		}
		@media print {
			/* Printed from a browser, the cover and the tiles keep their backgrounds. */
			body { padding: 0; background: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
			.sheet { max-width: none; margin: 0; padding: 0; border: 0; }
		}
		<?php
		// Colors are written into the rules as plain hex. The PDF renderer does not resolve a custom property on a table cell.
		$rules = (string) ob_get_clean();
		foreach ( (array) $view['colors'] as $name => $hex ) {
			$rules = str_replace( '{{' . $name . '}}', (string) $hex, $rules );
		}
		echo $rules; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS from this file, with hex colors built by Overview_Report::colors().
		?>
	</style>
</head>
<body class="<?php echo ! empty( $view['pdf'] ) ? 'is-pdf' : 'is-html'; ?>">
	<div class="foot">
		<table><tr>
			<td><?php echo esc_html( $heading . ' · ' . $period ); ?></td>
			<td class="foot__page"><?php echo esc_html__( 'Page', 'packout-core' ); ?> </td>
		</tr></table>
	</div>

	<div class="sheet">
		<div class="cover">
			<table class="brand"><tr>
				<?php if ( '' !== (string) $view['logo'] ) : ?>
					<td class="brand__logo"><img src="<?php echo esc_attr( (string) $view['logo'] ); ?>" alt="" /></td>
				<?php endif; ?>
				<td class="brand__name"<?php echo '' === (string) $view['logo'] ? ' style="padding-left:0"' : ''; ?>><?php echo esc_html( (string) $view['name'] ); ?></td>
				<td class="brand__site"><?php echo esc_html( (string) $view['site'] ); ?></td>
			</tr></table>
			<div class="cover__rule"></div>
			<h1><?php echo esc_html( $heading ); ?></h1>
			<p class="cover__period"><?php echo esc_html( $period ); ?></p>
			<p class="cover__meta">
				<?php
				if ( '' !== $owner ) {
					echo esc_html(
						sprintf(
							'program' === ( $report['subject']['kind'] ?? '' )
								/* translators: %s: the name of the organization or partner a program belongs to. */
								? __( 'A program of %s', 'packout-core' )
								/* translators: %s: the name of the organization a campaign belongs to. */
								: __( 'A campaign of %s', 'packout-core' ),
							$owner
						) . ' · '
					);
				}
				/* translators: %s: number of days the report covers. */
				echo esc_html( sprintf( _n( '%s day', '%s days', $days, 'packout-core' ), number_format_i18n( $days ) ) . ' · ' . (string) ( $how['generated'] ?? '' ) );
				?>
			</p>
		</div>

		<div class="summary">
			<p class="eyebrow"><?php echo esc_html__( 'Summary', 'packout-core' ); ?></p>
			<p class="summary__lead"><?php echo esc_html( (string) ( $report['summary'] ?? '' ) ); ?></p>
		</div>

		<div class="block">
			<table class="section"><tr class="section__row">
				<td class="section__head">
					<h2><?php echo esc_html__( 'Outcomes', 'packout-core' ); ?></h2>
					<p class="section__note"><?php echo esc_html__( 'Spots cleaned in this period, and what later visits to cleaned Spots found.', 'packout-core' ); ?></p>
				</td>
				<td class="section__body">
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$tile( $num( $outcomes['spots_cleaned'] ?? 0 ), __( 'Spots cleaned', 'packout-core' ) );
						$tile( $num( $outcomes['still_clear'] ?? 0 ), __( 'Later visits that found them still clear', 'packout-core' ) );
						$tile( $num( $outcomes['returned'] ?? 0 ), __( 'Later visits that found the problem back', 'packout-core' ) );
						?>
					</tr></table></div>
					<?php if ( $visits > 0 ) : ?>
						<div class="meter">
							<div class="meter__track"><div class="meter__fill" style="width:<?php echo esc_attr( (string) $share( $outcomes['still_clear'] ?? 0, $visits ) ); ?>%"></div></div>
							<p class="meter__key">
								<span class="key"><span class="dot dot--good"></span><?php echo esc_html__( 'Still clear', 'packout-core' ); ?></span>
								<span class="key"><span class="dot dot--accent"></span><?php echo esc_html__( 'Problem back', 'packout-core' ); ?></span>
							</p>
						</div>
					<?php else : ?>
						<p class="quiet-line"><?php echo esc_html__( 'No later visit to a cleaned Spot was recorded in this period.', 'packout-core' ); ?></p>
					<?php endif; ?>
				</td>
			</tr></table>
		</div>

		<div class="block">
			<table class="section"><tr class="section__row">
				<td class="section__head">
					<h2><?php echo esc_html__( 'Outputs', 'packout-core' ); ?></h2>
					<p class="section__note"><?php echo esc_html__( 'What was carried out. Measured and self-reported amounts are shown apart and are never added together.', 'packout-core' ); ?></p>
				</td>
				<td class="section__body">
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$split( __( 'Bags', 'packout-core' ), $num( $outputs['bags_measured'] ?? 0 ), __( 'measured', 'packout-core' ), $num( $outputs['bags_self_reported'] ?? 0 ), __( 'self-reported', 'packout-core' ) );
						$split( __( 'Pounds', 'packout-core' ), $num( $outputs['pounds_measured'] ?? 0 ), __( 'measured', 'packout-core' ), $num( $outputs['pounds_self_reported'] ?? 0 ), __( 'self-reported', 'packout-core' ) );
						?>
					</tr></table></div>
				</td>
			</tr></table>
		</div>

		<div class="block">
			<table class="section"><tr class="section__row">
				<td class="section__head">
					<h2><?php echo esc_html__( 'Effort', 'packout-core' ); ?></h2>
					<p class="section__note"><?php echo esc_html__( 'The people and the time behind the cleanups. Verified hours are confirmed by a coordinator or lead.', 'packout-core' ); ?></p>
				</td>
				<td class="section__body">
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$tile( $num( $effort['people'] ?? 0 ), __( 'People', 'packout-core' ) );
						$tile( $num( $effort['missions'] ?? 0 ), __( 'Missions completed', 'packout-core' ) );
						$tile( $num( $effort['organizations'] ?? 0 ), __( 'Organizations active', 'packout-core' ) );
						?>
					</tr></table></div>
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$split( __( 'Hours', 'packout-core' ), $num( $effort['hours_verified'] ?? 0 ), __( 'verified', 'packout-core' ), $num( $effort['hours_unverified'] ?? 0 ), __( 'unverified', 'packout-core' ), 3 );
						?>
					</tr></table></div>
				</td>
			</tr></table>
		</div>

		<div class="block">
			<table class="section"><tr class="section__row">
				<td class="section__head">
					<h2><?php echo esc_html__( 'From report to cleanup', 'packout-core' ); ?></h2>
					<p class="section__note"><?php echo esc_html__( 'Spots reported and cleaned in this period, and how long a cleanup took to arrive.', 'packout-core' ); ?></p>
				</td>
				<td class="section__body">
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$tile( $num( $reported ), __( 'Reported', 'packout-core' ), 5 );
						$tile( $num( $cleaned ), __( 'Cleaned', 'packout-core' ), 5 );
						$tile( $num( $cycle['median_days'] ?? null ), __( 'Median days from report to cleaned', 'packout-core' ), 5 );
						$tile( $num( $cycle['open_at_end'] ?? 0 ), __( 'Spots open at the end of the period', 'packout-core' ), 5 );
						?>
					</tr></table></div>
					<?php if ( $most > 0 ) : ?>
						<table class="rows">
							<tr>
								<td class="rows__name"><?php echo esc_html__( 'Reported', 'packout-core' ); ?></td>
								<td class="rows__bar"><div class="bar--accent" style="width:<?php echo esc_attr( (string) $share( $reported, $most ) ); ?>%"></div></td>
								<td class="rows__value"><?php echo esc_html( $num( $reported ) ); ?></td>
							</tr>
							<tr>
								<td class="rows__name"><?php echo esc_html__( 'Cleaned', 'packout-core' ); ?></td>
								<td class="rows__bar"><div class="bar--good" style="width:<?php echo esc_attr( (string) $share( $cleaned, $most ) ); ?>%"></div></td>
								<td class="rows__value"><?php echo esc_html( $num( $cleaned ) ); ?></td>
							</tr>
						</table>
					<?php endif; ?>
				</td>
			</tr></table>
		</div>

		<?php if ( $sites ) : ?>
		<div class="block">
			<table class="section"><tr class="section__row">
				<td class="section__head">
					<h2><?php echo esc_html__( 'Reported by partner sites', 'packout-core' ); ?></h2>
					<p class="section__note"><?php echo esc_html( (string) ( $sites['note'] ?? '' ) ); ?></p>
				</td>
				<td class="section__body">
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$tile( $num( $sites['sites'] ?? 0 ), __( 'Partner sites', 'packout-core' ) );
						$tile( $num( $sites['spots_cleaned'] ?? 0 ), __( 'Spots cleaned', 'packout-core' ) );
						$tile( $num( $sites['missions'] ?? 0 ), __( 'Missions completed', 'packout-core' ) );
						?>
					</tr></table></div>
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$split( __( 'Bags', 'packout-core' ), $num( $sites['bags_measured'] ?? 0 ), __( 'measured', 'packout-core' ), $num( $sites['bags_self_reported'] ?? 0 ), __( 'self-reported', 'packout-core' ) );
						$split( __( 'Pounds', 'packout-core' ), $num( $sites['pounds_measured'] ?? 0 ), __( 'measured', 'packout-core' ), $num( $sites['pounds_self_reported'] ?? 0 ), __( 'self-reported', 'packout-core' ) );
						?>
					</tr></table></div>
					<div class="tiles-wrap"><table class="tiles"><tr>
						<?php
						$split( __( 'Hours', 'packout-core' ), $num( $sites['hours_verified'] ?? 0 ), __( 'verified by the site', 'packout-core' ), $num( $sites['hours_unverified'] ?? 0 ), __( 'unverified', 'packout-core' ), 3 );
						?>
					</tr></table></div>
				</td>
			</tr></table>
		</div>
		<?php endif; ?>

		<div class="block<?php echo $stacked ? ' block--stack' : ''; ?>">
			<table class="section"><tr class="section__row">
				<td class="section__head">
					<h2><?php echo esc_html__( 'Who did the work', 'packout-core' ); ?></h2>
					<p class="section__note"><?php echo esc_html( $of_team ? __( 'Its teams that were on a mission with a cleanup in this period, in alphabetical order.', 'packout-core' ) : __( 'Listed organizations that documented a cleanup in this period, in alphabetical order.', 'packout-core' ) ); ?></p>
				</td>
				<td class="section__body">
					<?php if ( $listed ) : ?>
						<div class="names">
							<?php foreach ( $listed as $name ) : ?>
								<span class="chip"><?php echo esc_html( (string) $name ); ?></span>
							<?php endforeach; ?>
						</div>
						<?php if ( $unlisted > 0 ) : ?>
							<p class="names__more">
								<?php
								/* translators: %s: number of organizations that are not listed publicly. */
								echo esc_html( sprintf( _n( 'and %s organization that is not listed.', 'and %s organizations that are not listed.', $unlisted, 'packout-core' ), number_format_i18n( $unlisted ) ) );
								?>
							</p>
						<?php endif; ?>
					<?php elseif ( $unlisted > 0 ) : ?>
						<p class="names__none">
							<?php
							/* translators: %s: number of organizations that are not listed publicly. */
							echo esc_html( sprintf( _n( '%s organization that is not listed documented a cleanup in this period.', '%s organizations that are not listed documented a cleanup in this period.', $unlisted, 'packout-core' ), number_format_i18n( $unlisted ) ) );
							?>
						</p>
					<?php else : ?>
						<p class="names__none"><?php echo esc_html( $of_team ? __( 'No team was on a mission with a cleanup in this period.', 'packout-core' ) : __( 'No listed organization documented a cleanup in this period.', 'packout-core' ) ); ?></p>
					<?php endif; ?>
				</td>
			</tr></table>
		</div>

		<div class="block<?php echo $stacked ? ' block--stack' : ''; ?>">
			<table class="section"><tr class="section__row">
				<td class="section__head">
					<h2><?php echo esc_html__( 'Where', 'packout-core' ); ?></h2>
					<p class="section__note"><?php echo esc_html__( 'Listed Places with a cleanup in this period.', 'packout-core' ); ?></p>
				</td>
				<td class="section__body">
					<?php if ( $where ) : ?>
						<div class="names">
							<?php foreach ( $where as $name ) : ?>
								<span class="chip"><?php echo esc_html( (string) $name ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="names__none"><?php echo esc_html__( 'No listed Place had a cleanup in this period.', 'packout-core' ); ?></p>
					<?php endif; ?>
				</td>
			</tr></table>
		</div>

		<div class="howto">
			<h2><?php echo esc_html__( 'How to read this report', 'packout-core' ); ?></h2>
			<p><?php echo esc_html( (string) ( $how['verified'] ?? '' ) ); ?></p>
			<p><?php echo esc_html( (string) ( $how['sources'] ?? '' ) ); ?></p>
			<p><?php echo esc_html( (string) ( $how['counted'] ?? '' ) ); ?></p>
			<p class="howto__when"><?php echo esc_html( (string) ( $how['generated'] ?? '' ) ); ?></p>
		</div>
	</div>
</body>
</html>
