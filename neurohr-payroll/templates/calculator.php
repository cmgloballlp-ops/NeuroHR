<?php
/**
 * Calculator markup. Variables in scope: $s (settings), $current (default period), $show_title, $is_pro, $upgrade_url.
 */

use NeuroHR\Payroll\Engine\Rates;
use NeuroHR\Payroll\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="nhp" id="nhp">
	<?php if ( $show_title ) : ?>
	<header class="nhp-head">
		<h2 class="nhp-title">Simple Payroll</h2>
		<p class="nhp-sub">EPF, SOCSO, LINDUNG 24 Jam, EIS and PCB for one employee. Nothing you enter is saved.</p>
	</header>
	<?php endif; ?>

	<div class="nhp-grid">
		<form class="nhp-form" id="nhp-form" autocomplete="off" novalidate>

			<fieldset class="nhp-card">
				<legend>Employee</legend>
				<div class="nhp-row nhp-row-2">
					<div class="nhp-field">
						<span id="nhp-period-label">Pay month</span>
						<div class="nhp-period" role="group" aria-labelledby="nhp-period-label">
							<select data-period="month" aria-label="Month">
								<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
									<option value="<?php echo (int) $m; ?>" <?php selected( $m, (int) substr( $current, 5, 2 ) ); ?>><?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 1, 2026 ) ) ); ?></option>
								<?php endfor; ?>
							</select>
							<input type="number" data-period="year" aria-label="Year" inputmode="numeric" value="<?php echo esc_attr( substr( $current, 0, 4 ) ); ?>" min="<?php echo esc_attr( substr( Rates::FIRST_PERIOD, 0, 4 ) ); ?>" max="2099">
						</div>
					</div>
					<label class="nhp-field">
						<span>Employment</span>
						<select name="type" data-toggle="type">
							<option value="monthly">Monthly salary</option>
							<option value="parttime">Part-time</option>
						</select>
					</label>
				</div>
				<div class="nhp-row nhp-row-2">
					<label class="nhp-field">
						<span>Citizenship</span>
						<select name="citizenship">
							<option value="citizen">Malaysian</option>
							<option value="pr">Permanent resident</option>
							<option value="foreign">Foreign worker</option>
						</select>
					</label>
					<label class="nhp-field">
						<span>Age</span>
						<input type="number" name="age" value="30" min="15" max="99" inputmode="numeric">
					</label>
				</div>
			</fieldset>

			<fieldset class="nhp-card">
				<legend>Earnings this month</legend>
				<div class="nhp-only-monthly">
					<label class="nhp-field">
						<span>Basic salary (RM)</span>
						<input type="text" name="salary" inputmode="decimal" placeholder="0.00">
					</label>
				</div>
				<div class="nhp-row nhp-row-2 nhp-only-parttime" hidden>
					<label class="nhp-field">
						<span>Rate per hour or day (RM)</span>
						<input type="text" name="rate" inputmode="decimal" placeholder="0.00">
					</label>
					<label class="nhp-field">
						<span>Hours or days worked</span>
						<input type="text" name="units" inputmode="decimal" placeholder="0">
					</label>
				</div>
				<label class="nhp-field">
					<span>Allowances &amp; commission (RM)</span>
					<input type="text" name="allowance" inputmode="decimal" placeholder="0.00">
					<small>Subject to EPF, SOCSO, EIS and PCB.</small>
				</label>
				<label class="nhp-field">
					<span>Taxable allowance without EPF/SOCSO (RM)</span>
					<input type="text" name="allowance_tax_only" inputmode="decimal" placeholder="0.00">
					<small>For example travel allowance above the tax exemption: only PCB applies.</small>
				</label>
				<label class="nhp-field">
					<span>Bonus (RM)</span>
					<input type="text" name="bonus" inputmode="decimal" placeholder="0.00">
					<small>EPF and PCB apply; SOCSO and EIS do not.</small>
				</label>
			</fieldset>

			<fieldset class="nhp-card">
				<legend>Income tax (PCB)</legend>
				<div class="nhp-row nhp-row-2">
					<label class="nhp-field">
						<span>Tax resident</span>
						<select name="resident">
							<option value="1">Yes</option>
							<option value="0">No (flat 30%)</option>
						</select>
					</label>
					<label class="nhp-field">
						<span>Children</span>
						<select name="children">
							<?php for ( $i = 0; $i <= 15; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>"><?php echo (int) $i; ?></option>
							<?php endfor; ?>
						</select>
					</label>
				</div>
				<label class="nhp-field">
					<span>Tax category</span>
					<select name="tax_category">
						<option value="1">Single</option>
						<option value="2">Married, spouse not working</option>
						<option value="3">Married &amp; spouse working, or divorced / widowed</option>
					</select>
				</label>
				<div class="nhp-row nhp-row-2">
					<label class="nhp-field">
						<span>Zakat this month (RM)</span>
						<input type="text" name="zakat" inputmode="decimal" placeholder="0.00">
					</label>
					<label class="nhp-field">
						<span data-tp1-label>Other tax reliefs, yearly (RM)</span>
						<input type="text" name="tp1" inputmode="decimal" placeholder="0.00">
					</label>
				</div>
				<small class="nhp-hint">Reliefs from Form TP1, such as life insurance, SSPN, lifestyle or medical. EPF, SOCSO and EIS reliefs are already included.</small>

				<label class="nhp-check">
					<input type="checkbox" name="ytd" value="1">
					<span><strong>Year to date</strong> — use the amounts already paid this year (joined mid-year, pay changed, or processing month by month)</span>
				</label>
				<div class="nhp-ytd" hidden>
					<p class="nhp-hint">Totals from January to last month, including pay from a previous employer this year (Form TP3). <em>Next month</em> fills these in for you.</p>
					<div class="nhp-row nhp-row-2">
						<label class="nhp-field"><span>Total pay received this year (RM)</span><input type="text" name="ytd_Y" inputmode="decimal" placeholder="0.00"></label>
						<label class="nhp-field"><span>Employee EPF paid this year (RM)</span><input type="text" name="ytd_K" inputmode="decimal" placeholder="0.00"></label>
						<label class="nhp-field"><span>PCB paid this year (RM)</span><input type="text" name="ytd_X" inputmode="decimal" placeholder="0.00"></label>
						<label class="nhp-field"><span>Zakat paid this year (RM)</span><input type="text" name="ytd_Z" inputmode="decimal" placeholder="0.00"></label>
						<label class="nhp-field"><span>TP1 reliefs claimed this year (RM)</span><input type="text" name="ytd_LP" inputmode="decimal" placeholder="0.00"></label>
						<label class="nhp-field"><span>SOCSO + EIS paid this year (RM)</span><input type="text" name="ytd_socso" inputmode="decimal" placeholder="0.00"><small>Up to RM350 a year counts as tax relief.</small></label>
					</div>
					<input type="hidden" name="ytd_l24" value="0">
				</div>
			</fieldset>

			<fieldset class="nhp-card">
				<legend>Contribution rates</legend>
				<p class="nhp-hint">Auto follows the employee's age and citizenship. Change any of them if needed.</p>
				<div class="nhp-row nhp-row-2">
					<label class="nhp-field">
						<span>EPF employee</span>
						<select name="epf_ee" data-auto="epf_employee_rate"><?php echo Plugin::rate_options( Plugin::employee_rates(), 'Auto' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					</label>
					<label class="nhp-field">
						<span>EPF employer</span>
						<select name="epf_er" data-auto="epf_employer_rate"><?php echo Plugin::rate_options( Plugin::employer_rates(), 'Auto' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					</label>
				</div>
				<label class="nhp-field">
					<span>SOCSO category</span>
					<select name="socso" data-auto="socso_label">
						<option value="auto">Auto</option>
						<?php foreach ( Plugin::socso_categories() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<small>Choose a category without LINDUNG 24 if the employee has opted out.</small>
				</label>
				<label class="nhp-field">
					<span>EIS</span>
					<select name="eis" data-auto="eis">
						<option value="auto">Auto</option>
						<option value="yes">Yes</option>
						<option value="no">No</option>
					</select>
					<small>Not for foreign workers, employees 60 and above, or those first employed at 57 or older with no earlier contribution.</small>
				</label>
				<label class="nhp-field">
					<span>HRD Corp levy (employer)</span>
					<select name="hrd">
						<option value="0">0% — not registered</option>
						<option value="0.5">0.5%</option>
						<option value="1">1%</option>
					</select>
					<small>On basic salary and fixed allowances, Malaysian full-time employees only.</small>
				</label>
			</fieldset>

			<fieldset class="nhp-card">
				<legend>Payslip details <em>(optional, only used for printing)</em></legend>
				<label class="nhp-field">
					<span>Company name</span>
					<input type="text" data-local="company" maxlength="120">
				</label>
				<div class="nhp-row nhp-row-2">
					<label class="nhp-field">
						<span>Employee name</span>
						<input type="text" data-local="employee" maxlength="120">
					</label>
					<label class="nhp-field">
						<span>Employee no. / IC</span>
						<input type="text" data-local="staffno" maxlength="40">
					</label>
				</div>
				<?php if ( $is_pro ) : ?>
					<div class="nhp-row nhp-row-2">
						<div class="nhp-field">
							<span>Company logo</span>
							<div class="nhp-logo">
								<img data-logo-preview alt="" hidden>
								<label class="nhp-btn nhp-btn-ghost nhp-btn-sm">Choose image<input type="file" accept="image/png,image/jpeg,image/webp" data-logo-input hidden></label>
								<button type="button" class="nhp-link" data-action="logo-remove" hidden>Remove</button>
							</div>
						</div>
						<label class="nhp-field">
							<span>Pay date</span>
							<input type="date" data-local="paydate">
						</label>
					</div>
					<label class="nhp-field">
						<span>Company address</span>
						<textarea data-local="address" rows="2" maxlength="300"></textarea>
					</label>
				<?php else : ?>
					<div class="nhp-upsell">
						<strong>Logo, company address and pay date on the payslip</strong>
						<span>Available to Pro members.</span>
						<?php if ( $upgrade_url ) : ?>
							<a class="nhp-btn nhp-btn-sm" href="<?php echo esc_url( $upgrade_url ); ?>">Upgrade</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<label class="nhp-field">
					<span>Other deductions (RM)</span>
					<input type="text" name="other_deductions" inputmode="decimal" placeholder="0.00">
					<small>Salary advance, loan repayment and the like. Reduces net pay only.</small>
				</label>
				<label class="nhp-check">
					<input type="checkbox" data-remember checked>
					<span>Remember my entries on this browser (nothing is sent to NeuroHR)</span>
				</label>
			</fieldset>
			<a class="nhp-bar" id="nhp-bar" href="#nhp-result"><span>Net pay</span><strong>RM 0.00</strong></a>
		</form>

		<aside class="nhp-result" id="nhp-result" aria-live="polite">
			<div class="nhp-card nhp-summary">
				<div class="nhp-net">
					<span>Net pay</span>
					<strong data-out="net">RM 0.00</strong>
				</div>

				<table class="nhp-table">
					<tbody data-list="earnings"></tbody>
					<tbody class="nhp-subtotal"><tr><th>Gross pay</th><td data-out="earnings.gross">0.00</td></tr></tbody>
				</table>

				<h3>Employee deductions</h3>
				<table class="nhp-table">
					<tbody data-list="employee"></tbody>
					<tbody class="nhp-subtotal"><tr><th>Total deductions</th><td data-out="employee.total">0.00</td></tr></tbody>
				</table>

				<h3>Employer contributions</h3>
				<table class="nhp-table">
					<tbody data-list="employer"></tbody>
					<tbody class="nhp-subtotal"><tr><th>Total cost to employer</th><td data-out="cost">0.00</td></tr></tbody>
				</table>

				<ul class="nhp-notes" data-list="notes" hidden></ul>

				<div class="nhp-actions">
					<button type="button" class="nhp-btn" data-action="print">Print payslip</button>
					<button type="button" class="nhp-btn nhp-btn-ghost" data-action="next" hidden>Next month →</button>
					<button type="button" class="nhp-btn nhp-btn-ghost" data-action="reset">Clear</button>
				</div>

				<details class="nhp-details">
					<summary>How this was calculated</summary>
					<ul data-list="details"></ul>
				</details>

				<p class="nhp-disclaimer"><?php echo esc_html( $s['disclaimer'] ); ?></p>
				<p class="nhp-status" data-out="status" role="status"></p>
			</div>
		</aside>
	</div>

	<section class="nhp-payslip" id="nhp-payslip" aria-hidden="true"></section>
</div>
