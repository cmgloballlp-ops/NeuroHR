# NeuroHR Simple Payroll — v0.5.0

A payroll.my-style calculator for members: one employee, one month.
Shows EPF, SOCSO, LINDUNG 24 Jam, EIS and PCB, net pay and employer cost, and prints a payslip.
Nothing is saved: no database tables, and payslip names never leave the member's browser.

## Install

1. Plugins → Add New → Upload → `neurohr-payroll.zip` → Activate.
2. Create a page (e.g. `/payroll/`) with the shortcode `[nh_payroll_calculator]`.
   Use `[nh_payroll_calculator title="no"]` if the page already has its own heading.
3. Link to it from your member dashboard (HR tools).
4. Settings → NeuroHR Payroll: brand colour, payslip footer, disclaimer.

Access: any logged-in user (Free and above). Guests see a **Log in** button (NeuroHR Members login page — or MemberPress without it — then back).
For a page builder, the Blocksy "full width" page layout gives the two-column view; in narrow content areas the results move below the form with a net-pay bar at the bottom.

## What members enter

| Section | Fields |
|---|---|
| Employee | Pay month (month + year) · Monthly salary / Part-time · Malaysian / PR / Foreign · Age |
| Earnings | Basic salary (or rate × hours/days) · Allowances & commission · Taxable allowance without EPF/SOCSO · Bonus |
| Income tax | Resident yes/no · Tax category · Children · Zakat this month · Other TP1 reliefs · **Year to date** (total pay received, employee EPF, PCB, zakat, TP1 reliefs, SOCSO + EIS paid this year) |
| Contribution rates | EPF employee (Auto, none, 2%, 4%, 5.5%, 7–30%) · EPF employer (Auto, none, 2%, 4%, 6%, 6.5%, 12–30%) · SOCSO category (5 choices as payroll.my) · EIS Auto/Yes/No · HRD Corp levy 0% / 0.5% / 1% |
| Payslip (print only) | Company · Employee name · No./IC · Other deductions · Pro & Corporate: logo, company address, pay date |

All amounts show as `1,234.50` (inputs are formatted when you leave the field).

**Auto** picks the rate from age and citizenship; the drop-down shows what Auto chose (e.g. "Auto — 12%").

## Rules used

**EPF** — KWSP Third Schedule: RM20 bands to RM5,000, RM100 bands to RM20,000, rate × band top, rounded up to the ringgit; exact % above RM20,000.
Auto: below 60 → 11% / 13% (12% if salary above RM5,000; stays 13% when only the bonus pushes wages above RM5,000) · Malaysian 60+ → 0% / 4% · PR 60+ → 5.5% / 6.5% (6%) · foreign → 2% / 2% from Oct 2025 · 75+ → none.

**SOCSO** — PERKESO table, ceiling RM6,000, bonus excluded.
Auto: below 60 → Injury & Invalidity & LINDUNG 24 · 60+ or foreign → Injury & LINDUNG 24.
**LINDUNG 24 Jam** — employee 0.75% (table), from June 2026 wages. Workers could opt out in Jul–Aug 2026: pick a category without LINDUNG 24 for them.

**EIS** — 0.2% + 0.2%, ceiling RM6,000. Auto: Malaysian/PR aged 18–59.

**PCB** — LHDN computerised calculation, 2026 specification: reliefs RM9,000 self, RM4,000 spouse, RM2,000 per child, EPF relief up to RM4,000; bonus by the 5-step additional remuneration method; zakat reduces PCB; non-resident 30%; under RM10 not deducted; rounded up to 5 sen.
The calculator assumes the same pay every month (no year-to-date history), the same as payroll.my. The engine already supports year-to-date figures for the future HRMS.

## Tests

`php tests/run.php` (kept out of the plugin zip) — 111 checks: the four LHDN 2026 worked examples (exact to the sen), EPF bands, 19 SOCSO/LINDUNG rows and 11 EIS rows from the PERKESO tables, year-to-date rolling, HRD levy and full calculations. PCB was also cross-checked against an independent implementation on 5,768 cases (no differences).
Spot-check a few results against LHDN's own calculator (calcpcbplus.hasil.gov.my) before going live.

## Updating rates

PCB tables (yearly LHDN specification) and LINDUNG 24 Jam phases are edited in **NeuroHR Payroll → PCB tables** and **SOCSO & EPF rates**, no code needed. Built-in figures live in `src/Engine/Rates.php`.
Supported pay months: from Jan 2025. A year without its own PCB table uses the latest one and shows a notice — add the new year in NeuroHR Payroll → PCB tables.

## Files

- `src/Engine/` — calculation only, no WordPress code (reusable for the HRMS): `Money`, `Rates`, `Epf`, `Socso` (SOCSO, LINDUNG 24, EIS), `Pcb`, `Calculator`, `SelfTest` (runs each year's worked examples).
- `src/Plugin.php` — shortcode, REST endpoints (logged-in only), membership level, usage counts.
- `src/Admin.php` — NeuroHR Payroll menu: Overview, PCB tables, SOCSO & EPF rates, Settings.
- `templates/calculator.php`, `assets/` — the page.

## Not in this version (planned for the HRMS)

Saving employees on the server, monthly runs for many employees, EA form, KWSP/PERKESO/LHDN upload files, overtime, disabled-person and other detailed reliefs in the form.

## Changelog

- **0.5.0** — Membership levels come from NeuroHR Members when it is active: Settings → "Payslip extras for" picks the level (Pro and above by default); the usage table shows a column per level; the login button opens the NeuroHR Members login page; an empty Upgrade link uses that level's upgrade link. Deleting the level in Members moves the extras to the next level up. Without NeuroHR Members it still reads MemberPress.
- **0.4.0** — Year-to-date mode for PCB (amounts already paid this year, incl. previous employer) and a **Next month →** button that rolls this month into the totals (December starts a new year). Entries remembered in the member's own browser (can be switched off; Clear wipes it). Pro & Corporate: logo, company address and pay date on the payslip; others see an Upgrade box. HRD Corp levy 0% / 0.5% / 1% (basic + fixed allowances, Malaysian full-time only). Usage table on the Overview (members per level per month, payslips printed, Next month used). Settings: upgrade link (empty = no Upgrade button) and membership levels picked from the MemberPress list (Lite 6373, Pro 6376, Corporate 6379 + 6382 by default). Year-to-date labels read "… paid this year"; every amount is shown as 1,234.50.
- **0.3.0** — Own "NeuroHR Payroll" admin menu (Overview, PCB tables, SOCSO & EPF rates, Settings). PCB figures stored per year and editable, with the specification's worked examples checked on save; next year falls back to the latest table with a notice. LINDUNG 24 rate schedule editable for phase 2/3. PCB now claims SOCSO + EIS as relief (up to RM350 a year), as LHDN's calculator does.
- **0.2.0** — Pay month is now a month list plus a year box. Fixed the part-time fields jumping to the side of the card. New payslip design in the hub style (brand-colour header line and net pay bar, info panel, rounded cards), unaffected by theme table styles.
- **0.1.0** — First version.
