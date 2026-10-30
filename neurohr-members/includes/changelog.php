<?php
/**
 * Version history shown in Settings → Changelog.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'0.4.4' => array(
		'date'  => '31 Oct 2026',
		'items' => array(
			'Invitation page for company staff: Settings → Pages & login → "Invitation page" ([nhm_invite], or click Create page). It shows only the invitation: company name, who invited them, the invited email (fixed), name and password, and the Terms tick box. No plans or prices. People with an account log in to accept. Without this page, invitations still open the registration page.',
			'My account tables (Invoices, Company seats) redone: proper spacing, aligned columns, numbers on the right, and cards on phones. The theme\'s table borders no longer show through.',
			'Invoices: "PDF" is now "View invoice"; "Request a refund" lines up next to it. The refund window is fixed (it was inheriting the table\'s right alignment and running off the side).',
			'Company seats: Remove for each colleague (not the owner), and Send again / Cancel for invitations, all with a clear confirmation window in the site style instead of the browser\'s grey box.',
			'Security → Change password: the two boxes line up again. Form labels stay left-aligned whatever the theme does.',
		),
	),
	'0.4.3' => array(
		'date'  => '30 Oct 2026',
		'items' => array(
			'Card auto-renew for personal plans (Lite, Pro). "Renew automatically" is ticked on the payment page; Stripe keeps the card (FPX and GrabPay still show, but only cards renew). The price is locked while auto-renew stays on: a higher plan price does not apply to them, a lower one does. Corporate and Consulting are off (Levels → "Offer card auto-renew").',
			'Reminder emails 7 and 3 days before, and 30 days before when the card expires first. On the last day the card is charged; the new term follows the old last day and the member gets "Plan renewed" with the paid invoice. The usual "Plan ending soon" emails are not sent to them.',
			'Failed charge: the member gets an email, keeps access, and we try again 3 days later (at once when they add a new card). If that fails too, they get the renewal invoice with the payment link (same locked price), auto-renew turns off and you get an email.',
			'My account → Plan & billing: an Auto-renew card with the next payment, the card and the locked price; Update card, Turn off (with an optional reason), or Add a card and turn on for members who paid by transfer.',
			'Member profile → Card auto-renew: status, next charge, locked price (editable), Turn off, and "Run renewal now" (test mode) / "Charge renewal now". The members list shows an Auto-renew label.',
			'Pricing table: "Renews automatically every year. Cancel any time." under plans with auto-renew.',
			'Policy pages (Settings → Pages & login): Terms of Use and Privacy Policy give a required tick box on the registration form (date and time kept on the profile); Refund Policy gives a required tick box on the payment page.',
			'New date and time pickers in your style: dd/mm/yyyy with weeks starting on Sunday, Today and Yesterday buttons, 24-hour time (type 1435 or pick), a sheet from the bottom on phones.',
			'Refunds. Members can ask within 72 hours of paying (My account → Invoices → Request a refund, with a reason). Their access is paused at once and every step is emailed to them.',
			'Members → Payments → Refund requests: what the member opened and downloaded since paying (Knowledge Hub 0.8.2), the date to complete by (14 working days), the amount (paid minus the Stripe fee, editable). Approve refunds card/FPX/GrabPay through Stripe at once; for bank transfer or QR you pay back and click "Mark as refunded". Do not approve: with a reason, access comes back.',
			'Any refund ends the plan at once and turns off auto-renew. Each refund gets a Refund Note PDF with its own number (Business info → Refunds: format, next running number, hours, working days); members download it in My account.',
			'Refunds made in the Stripe dashboard are followed automatically. A chargeback pauses the member\'s access and emails you; "Restore access" on their profile gives it back.',
			'Dashboard: received, refunded and net for the year, and auto-renewals due in the next 30 days. Invoices show Refunded / Partly refunded. Business info: you can now also set the next invoice number.',
			'Stripe webhook: add the events payment_intent.succeeded, payment_intent.payment_failed, charge.refunded and charge.dispute.created.',
		),
	),
	'0.4.2' => array(
		'date'  => '30 Oct 2026',
		'items' => array(
			'Invoice lines now read "NeuroHR - Lite Membership (1 year)" without the company name. The brand comes from Settings → Business info → "Product name on invoices" (empty = site name). The Stripe payment page uses the same text. Invoices made before keep their old text.',
			'Invoice PDF: the DuitNow QR picture is printed next to the payment details on unpaid invoices (when QR payments are on). Qty, Unit price and Amount headings are aligned right like the numbers.',
			'Payment page: tap the DuitNow QR to open it big in a pop-up (with the amount and reference), easier to scan. A "Save QR image" link helps people paying on the same phone.',
			'Block a member: Members (bulk action) or their profile → "Block or delete". They are logged out at once, cannot log in, their own plan goes back to Free, unpaid invoices are voided and their email cannot register again. They see a polite message with your contact email. New Blocked tab; Unblock any time.',
			'Block list: Settings → Registration → Blocked emails. Add emails or whole domains (@spam-domain.com).',
			'Delete an account: paid invoices are kept for your records (unpaid ones voided). For test accounts, "Delete with invoices and payments" removes everything, including slips and history.',
		),
	),
	'0.4.1' => array(
		'date'  => '30 Oct 2026',
		'items' => array(
			'Send email through your own mailbox (SMTP): Settings → Emails → "Send through your mailbox". Every email of the website uses it, with your From name and address instead of "WordPress". "Send a test email to me" shows the error if it fails.',
			'Paid plans: after the registration form the person goes straight to the payment page (steps "Create account" and "Payment"). No email confirmation or login first. Paying online opens the account and logs them in; with bank transfer or DuitNow QR the account opens when you approve the payment. Until then the account cannot log in: the login form and a second sign-up with the same email send the payment link again.',
			'Sign-up and upgrade invoices are due in 3 days: a friendly reminder the day before, cancelled the day after (with an email). A new account that never paid is then removed, so the person can sign up again. Invoices with a payment being checked are never cancelled; renewal and hand-made invoices keep the normal due date.',
			'The plan card ([nhm_plan_card] and My account) turns into a payment card while a plan waits for payment: "Payment pending" with Complete payment and Download invoice, or "Checking payment". The yellow notice and the Upgrade button are gone in that state.',
			'Fixed: "Download invoice" sent members to the dashboard instead of the PDF. The link with its secret key now opens the PDF, also for people who are not logged in (like the payment link).',
			'Texts: we confirm transfers "within 3 working days"; friendlier wording without dashes in member emails and messages. The Payment Received email now ends with the login link.',
		),
	),
	'0.4.0' => array(
		'date'  => '30 Oct 2026',
		'items' => array(
			'Payments. Every unpaid invoice has a payment page (link in the invoice emails and the PDF, "Pay now" in My account, and straight after choosing a paid plan). Anyone with the link can pay, also without logging in.',
			'Three ways to pay (Settings → Payments): online with Stripe (card, FPX, GrabPay — the invoice becomes Paid and the plan starts by itself), bank transfer (your bank details) and DuitNow QR (your QR picture).',
			'Bank transfer and QR: the member sends who paid, date and time, amount, bank reference and the slip (JPG, PNG or PDF up to 5 MB). Members → Payments → Waiting for check: open the slip, Approve (invoice Paid, plan starts, paid invoice emailed) or Reject with a reason (the member gets an email and can send it again). Slips are kept in a closed folder; only staff can open them.',
			'Stripe: test and live keys, webhook at /wp-json/nhm/v1/stripe (signature checked), payment also confirmed when the member comes back from Stripe, repeated events ignored, a short payment does not start the plan.',
			'Members → Payments: all payments with method, amount, status, slip and a link to Stripe. The invoice screen shows its payment link and payments.',
			'New emails: Payment Details Received, Payment Not Confirmed (member) and Payment to Check (admin, slip attached). Plan Application and Invoice emails now include the payment link ({pay_url}) — if you edited those texts before, add {pay_url} yourself.',
		),
	),
	'0.3.6' => array(
		'date'  => '30 Oct 2026',
		'items' => array(
			'Fixed: on some pages the pricing table was pushed out of shape — a tall label strip, big gaps above the plan names, a small price. Theme, Elementor and text-editor styles (heading margins, paragraph sizes, extra <p>/<br> added by editors) can no longer change its parts.',
		),
	),
	'0.3.5' => array(
		'date'  => '30 Oct 2026',
		'items' => array(
			'New pricing table [nhm_pricing]: label above a card (e.g. Most popular), a line under the name, the price with a small currency, a small note under the price, description, features with ticks — lines starting with "-" show greyed out as not included, **words** in bold — and the button at the bottom. The parts line up across the cards. On phones, not-included lines are hidden.',
			'Members → Levels: new boxes "One line under the name", "Small text under the price", "Label above the card"; button "Fill in the suggested pricing texts" writes the agreed texts into Free, Lite, Pro and Corporate (prices stay as they are).',
			'[nhm_pricing note="…"] adds a small line under the table.',
		),
	),
	'0.3.4' => array(
		'date'  => '24 Oct 2026',
		'items' => array(
			'New shortcode [nhm_plan_card] for your dashboard: plan, company, last day and days left, a Renew button from 30 days before the end (or after it ended), an Upgrade button below the top plan, and a note about an unpaid invoice. style="light" for a white card, title="" to hide the small title.',
		),
	),
	'0.3.3' => array(
		'date'  => '24 Oct 2026',
		'items' => array(
			'Elementor: editors and admins are never redirected away from the login, registration or home page, so these pages open in the Elementor editor and its preview.',
			'Elementor: the "who sees this" box can no longer stop the editor (skipped safely if anything goes wrong); also added to widgets with Elementor\'s optimised markup.',
			'Initials avatars are small image files instead of data: links (WordPress and Elementor removed those, leaving empty pictures in the editor and admin).',
			'The REST user list stays available to logged-in browsers (block editor, Elementor); author pages only give "not found" to visitors.',
		),
	),
	'0.3.2' => array(
		'date'  => '23 Oct 2026',
		'items' => array(
			'My account → billing details: three fields a row (shorter labels), boxes line up.',
			'Upgrade buttons (My account and the Upgrade menu item): go to your pricing page when one is set in Settings → Pages & login; otherwise to the next plan up (e.g. Free → Lite, Lite → Pro), where a logged-in member gets "Get <plan>" and an invoice.',
		),
	),
	'0.3.1' => array(
		'date'  => '23 Oct 2026',
		'items' => array(
			'Fixed: on the login form, "No account yet? Create a free account" was squeezed into a narrow column (a style name was used twice). The "Keep me logged in" tick box is now the same size and lined up with its text.',
			'Fixed: My account inside the member layout sat in a second white card, with extra space above titles and a scroll arrow next to the tabs. Pages with the plugin\'s screens now use the full width; other pages are wider and centred.',
			'Sidebar without a menu now lists every Knowledge Hub section (Knowledge Hub, Legal Case Insights, FAQ, Templates, Videos), HR News & Update and Simple Payroll. Settings → Member area → "Create the member menus" turns this into normal WordPress menus you can edit.',
			'Top-bar search shows results while typing (needs Knowledge Hub 0.8.1).',
		),
	),
	'0.3.0' => array(
		'date'  => '23 Oct 2026',
		'items' => array(
			'Member area: logged-in people see every page in one layout — sidebar menu with icons, top bar with search and their photo, name and "Plan · Company", a card at the bottom of the sidebar, a small footer, and a drawer menu on phones. Visitors keep the Blocksy site. Settings → Member area. Per page: "Don\'t use the member layout"; Elementor Canvas pages keep their own look.',
			'Members who open the home page go to /dashboard/ (Settings → Pages & login). Editors and admins still see the home page.',
			'Menus: every menu item has "Who sees this" (everyone, visitors only, members only, a plan or higher, exactly these plans) and an icon. New menu items: Log in / Log out, My account, Register, Upgrade, Member name + photo. Three menu locations for the member area.',
			'Elementor: sections, containers, columns and widgets have "NeuroHR: who sees this" in the Advanced tab.',
			'Hide WordPress: wp-login.php and /wp-admin give "page not found" to visitors; everyone logs in on your login page; logout at /logout/. Author pages hidden, XML-RPC off. WordPress\'s own account emails use your templates. No emergency login address: if the login page breaks, rename the plugin folder in cPanel.',
			'Registration: email confirmation before the account works (on by default); phone with country code (saved as +60…); Location field with the Malaysian states and "Outside Malaysia".',
			'Forgot password opens as a pop-up on the login page; the link in the email comes back to the login page and opens "Choose a new password". The lost-password page is now optional.',
			'Profile photos (JPG/PNG up to 2 MB, cropped square) replace Gravatar everywhere; people without a photo get their initials.',
			'My account in tabs: Profile (details, photo, second email with confirmation, language and time zone saved for later), Security (password, where you are logged in, log out other devices, previous login), Notifications (newsletter, new content, events — MailerLite groups), Company (staff and the company\'s billing details, which the owner can now change; you get an email), Plan & billing.',
			'Invoices: always for a plan — pick the plan and 1–3 terms, price per term editable, discount. Status line instead of the PAID stamp. Membership period on the PDF. Option "prices include SST".',
			'Companies: you type the client code yourself (unique, optional). One price per company, not per seat.',
		),
	),
	'0.2.0' => array(
		'date'  => '23 Oct 2026',
		'items' => array(
			'Levels: price, billing type (one-time / recurring), access (lifetime / expire after N days-weeks-months-years / fixed date), early renewals. "+1 term" buttons and bought plans use the term.',
			'Each level has its own registration shortcode [nhm_register level="pro"] and link /register/?plan=pro for your own pricing buttons. Paid plans: account is Free first, an invoice is emailed, the plan starts when you mark it paid. Members get an upgrade / renew button.',
			'Invoices: number format, Unpaid / Paid / Void, SST on/off, PDF download in My account and in the admin, email with the PDF attached, renewal invoices for recurring plans.',
			'Settings → Business info & invoices: company name, SSM, TIN, SST no., address, logo, bank details, payment terms.',
			'Companies: client code (automatic), SSM, TIN, SST no., address, phone, billing email, contact person, industry, employees.',
			'Registration form builder: add text, long text, number, email, phone, website, date, drop-down, choose-one, choose-several and tick-box fields; required, on the form, editable in My account.',
			'Emails: Member notices and Admin notices list with Edit, Send Test and Reset to Default; From name and email; several admin addresses.',
			'Dashboard, bulk actions on the members list, CSV import for companies and people, billing details in My account, PDPA export / erase.',
		),
	),
	'0.1.0' => array(
		'date'  => '23 Oct 2026',
		'items' => array(
			'First version, replaces MemberPress (no online payment yet).',
			'Levels: Free, Lite, Pro, Corporate, Consulting (= Corporate). Add, rename, drag to reorder, delete (asks where members go; content moves to the next level up).',
			'Own plan per person with start date and last day, set on the user profile. "+1 year" button.',
			'Companies: one plan and a number of seats; owner invites and removes staff from My account; admin can add people by email.',
			'Daily check: reminders 30 and 7 days before the end (changeable), "plan ended" email, copy to admin. Access ends the day after the last day even if the check has not run.',
			'Shortcodes: [nhm_login] [nhm_register] [nhm_lost_password] [nhm_account] [nhm_pricing] [nhm_if] [nhm_user] [nhm_logout].',
			'Registration fields (company, phone, job title) each required / optional / hidden. Anti-spam, optional Cloudflare Turnstile, MailerLite opt-in.',
			'"Who can see this page" box for pages. Guests go to login, lower plans see an upgrade box.',
			'Members list with tabs (paid, ending within 30 days, ended, free), filters and CSV export. History of every change.',
			'API for the Knowledge Hub (0.7.0) and Simple Payroll (0.5.0).',
		),
	),
);
