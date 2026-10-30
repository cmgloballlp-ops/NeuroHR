# NeuroHR Members — plan and hand-over (start here)

A new self-built membership plugin to replace MemberPress on neurohr.com.my (WordPress, theme Blocksy, Elementor still used, host Yeahhost WH1 / LiteSpeed).
Written 23 Oct 2026 at the end of the Knowledge Hub chat, as the brief for the chat that builds this plugin.

## Why self-built

- No paying online members today. Revenue is mainly **Consulting** clients (contract + invoice, paid outside the website). Billing is **yearly**.
- MemberPress costs several hundred USD a year for features not used (recurring billing, checkout, coupons).
- Yearly payment = one-time payment, so FPX and cards both work later (Stripe and MemberPress cannot do FPX for recurring subscriptions).
- One place answers "what level is this user and until when" for every NeuroHR plugin: Knowledge Hub, Simple Payroll, later the HRMS.
- No migration risk: no paying members to move. Free members are just logged-in WordPress users.

## Decisions so far

- Keep the current levels: **Free < Lite < Pro < Corporate**, with **Consulting = same access as Corporate**.
- The owner must be able to **add, delete, rename and reorder levels** later in the admin, and set each level's settings.
- Levels are a ladder: a higher level reads everything a lower level reads.
- Login, registration and password reset use **WordPress core** (styled), not custom authentication.
- No SMTP inside the plugin (the site handles mail). MailerLite is used for newsletters.
- The owner develops and maintains with AI. Wants honest pros and cons, discuss first, then build. Replies in Chinese.

## Proposed scope

**Phase 1 — membership management, no online payment (build now)**
- Level manager: add / rename / reorder / delete levels. Deleting a level asks where its members go, and content that required it moves to the next level up.
- Per user: level + start date + expiry date (or "no expiry"). Set by hand in the admin (e.g. Consulting client → Corporate for one year).
- Expiry: daily check, downgrade to Free; reminder emails 30 and 7 days before expiry.
- Registration (Free), login, lost password, "My account" page (plan, expiry, upgrade button) — shortcodes so they fit the owner's own Elementor/Blocksy pages; anti-spam on registration.
- Admin member list: filter by level, expiring soon, expired; history of level changes.
- Shared API for other plugins (see below). Then deactivate MemberPress.

**Phase 2 — online payment (later, when someone wants to buy Lite/Pro themselves)**
- Hosted checkout of a gateway (Stripe, or a Malaysian one such as Billplz / CHIP), one-time yearly payment, card or FPX. Webhook → grant one year. Receipts from the gateway; formal invoices handled separately.

## Open questions to ask first

1. Corporate / Consulting: one account per company, or several staff accounts under one company (seats)? This changes the design most.
2. Online payment needed at launch, or manual activation first (phase 2 later)?
3. Which MemberPress pieces are in use today: registration page, login page, account page, pricing page, coupons, MemberPress emails?

## How the other plugins read levels today (integration contract)

**Knowledge Hub (neurohr-hub, v0.6.1)** — source is the zip the owner has (`neurohr-hub-0.6.1.zip`); ask them to attach it.
- `NH_Access::get_user_level( $user_id )` returns 0–4. 0 = guest, 1 = any logged-in user (Free). Editors/admins get the max.
- It reads MemberPress (`MeprUser::active_product_subscriptions('ids')`) through option `nh_hub_level_map`: membership ID → level. Defaults: 6366 Free → 1, 6373 Lite → 2, 6376 Pro → 3, 6379 Corporate → 4, 6382 Consulting → 4.
- A filter already exists: `apply_filters( 'nh_hub_user_level', $level, $user_id )` — the new plugin can supply the level through it without touching the hub.
- Fixed in the hub today, must become dynamic for "add/delete levels": `NH_Access::MAX_LEVEL = 4`, `DEFAULT_LEVEL = 4` (fail-closed), level names in `nh_hub_level_labels()` (helpers.php), resource meta `_nh_min_level` stores the number 0–4, type defaults option `nh_hub_type_levels`, the Settings → Membership levels tab lists `memberpressproduct` posts, login redirects go to the MemberPress login page, dashboard "Upgrade interest" counts views per level 1–3.
- Suggested contract: the membership plugin exposes ordered levels (id, name, rank, upgrade URL) and the user's rank; the hub stores ranks and asks the membership plugin for names and the ladder. Keep a fallback when the membership plugin is inactive.

**Simple Payroll (neurohr-payroll, v0.4.0)** — any logged-in user may use it; Pro & Corporate get logo / company address / pay date on the payslip. Its settings pick membership levels from the MemberPress list (Lite 6373, Pro 6376, Corporate 6379 + 6382). Must switch to the same API. Ask the owner to attach its zip too.

## Design

Same look as the hub: brand orange `#f47b20` (darker hover), rounded pill buttons, white cards with soft shadow, admin screens with tabs and cards (see the hub's assets/admin.css). Brand colour must override Blocksy's blue inside plugin markup.

## Lessons from the hub project (avoid repeating)

- Before each release run a cross-reference check that every `Class::method` and callback exists (v0.4.0 shipped with a fatal error from a removed method).
- Test scripts in the block editor: meta box inline scripts run before `wp.media` loads — bind on click.
- `wp_http_validate_url` does DNS lookups and rejects valid URLs in some hosts — validate scheme/host instead.
- Settings split across tabs must merge on save, not overwrite other tabs' values.
- Never ask for or type the owner's passwords; they log in themselves.

## Status — 23 Oct 2026: v0.1.0 built (see claude/neurohr-members-plugin-readme.md)

Answers from the owner:
- Companies: **company + several staff accounts (seats)**. Seats set per company. Both the client's owner (from My account) and the admin can add / remove staff.
- **Manual activation first**; online payment is phase 2.
- MemberPress pieces in use: registration / login / lost password, account page, pricing page, emails/coupons, and page protection (e.g. /dashboard/). Owner designs pages in Elementor/Blocksy; shortcodes must be stylable with CSS.
- Registration fields company / phone / job title: each set to required / optional / hidden in the admin. MailerLite opt-in tick box.
- No import from MemberPress: few clients, owner adds them by hand.

Built:
- neurohr-members 0.1.0, neurohr-hub 0.7.0 (levels from Members, fallback to MemberPress), neurohr-payroll 0.5.0 ("Payslip extras for" level, default Pro +).
- Level IDs 1 Free, 2 Lite, 3 Pro, 4 Corporate, 5 Consulting (= Corporate rank). IDs never reused; other plugins store IDs and compare ranks. Delete fires `nhm_level_deleted( $id, $content_to, $members_to )`.
- Tested in the build chat with a WordPress stand-in (no real WordPress could be downloaded there): 80 logic tests, 44 form/render tests, 17 admin screens, cross-reference check. **Not yet tested on a real WordPress** — the owner runs the README test checklist on the live site (or a staging copy) before deactivating MemberPress.

Next ideas: phase 2 payment (Billplz / CHIP / Stripe, FPX), protection inheriting to sub-pages, member-facing invoice list.

## Status — 23 Oct 2026 (later): v0.2.0 built

Owner's requests and decisions:
- Company records: client code (auto, format setting), SSM, TIN, SST no., address, phone, billing email, contact, industry, employees.
- Each level: price + billing (one-time / recurring) + access (lifetime / expire after N units / fixed date) + early renewals; own shortcode `[nhm_register level="pro"]` and link `/register/?plan=pro` for custom pricing CTAs.
- Paid signup without online payment: **account is Free + unpaid invoice emailed; admin marks paid → plan starts** for one term (owner's choice).
- Invoices: **one invoice, shows PAID after payment** (no separate receipt). SST: **not sure yet** → off by default, switch in Business info. e-Invoice: under RM3m turnover generally exempt from 1 Sep 2026 (owner to confirm with accountant).
- Registration custom fields: **built into the plugin** (not Fluent Forms).
- Emails: MemberPress-style Member / Admin notice list with Edit / Send Test / Reset, From name/email.
- Also added: dashboard, bulk actions, CSV import, renewal invoices for recurring levels, member billing details, PDPA export/erase.
- PDF via bundled dompdf 3.1.6 (Latin fonts only; Chinese names would not print).

Tested in the build chat (WordPress stand-in + real dompdf): 80 + 44 + 61 + 15 + 6 tests, 29 admin screens, cross-reference check. Still not tested on a real WordPress.

## Status — 23 Oct 2026 (evening): v0.3.0 built (Members 0.3.0 + Hub 0.8.0)

Owner's decisions for 0.3:
- Invoices: **no PAID chop** (Status line instead); invoices are **always for a plan** (plan + 1–3 terms, price per term editable, discount); option "prices include SST".
- Companies: **client code typed by hand** (unique, optional, aligns with the owner's own records); **one price per company**, not per seat. The owner edits company billing in My account (new invoices only) → admin email.
- **No WordPress anywhere**: wp-login.php and /wp-admin → **404** for visitors; logout /logout/; **no emergency login URL** — recovery = rename the plugin folder in cPanel (in README). XML-RPC off, author pages hidden, core account emails use the plugin templates.
- Menu visibility (everyone / visitors / members / plan+ / exact plans) + special items (Login/Logout, My account, Register, Upgrade, name + photo); same rules in Elementor (section/container/column/widget).
- Registration: email confirmation (default on), phone with country code (+60, stored +60…), Location = Malaysian states + Outside Malaysia. Profile photo upload replaces Gravatar site-wide (initials fallback).
- Forgot password = pop-up on the login page; the email link opens "choose a new password" on the login page.
- My account tabs: Profile (photo, second email with confirmation, language/time zone stored for later) · Security (password, devices, log out others, previous login) · Notifications (MailerLite groups; plan emails always on) · Company · Plan & billing. Look: light, orange #f47b20, Plus Jakarta Sans (approved preview canvas).
- **Member-area layout** for logged-in people on all pages (incl. HR News & Update and the hub); visitors keep Blocksy. Home → /dashboard/ for members (not staff). Elementor Canvas pages and pages with "Don't use the member layout" are excluded. Top bar shows "Plan · Company" (only plan without company). No bell.

Tested in the build chat (WordPress stand-in): 80 + 44 + 61 + 15 + 6 + 72 (v0.3) tests, 30 admin screens, screenshots of the member layout. Still not tested on a real WordPress — use the README checklist.

Next (0.4, agreed): Stripe — card, FPX, GrabPay; auto-renew for cards (card saved in Stripe, site charges off-session); FPX/GrabPay get a Pay-now invoice; webhooks Paid / Refunded / Partially refunded (full refund cancels the plan); payments table; Google login. Language / time zone to be made functional later.

## Status — 30 Oct 2026: v0.4.0 built (payments)

Owner's decisions: upgrades pay the full new price, new term from the payment date; Corporate may pay online; card auto-renew ticked by default, reminders 7 and 3 days before, retry 3 days after a failed charge then a Pay-now invoice (0.4.1); Google login later.
Three ways to pay on one payment page per invoice (token link, no login needed): Stripe Checkout (card, FPX, GrabPay), bank transfer (bank details), DuitNow QR (static QR picture). Bank/QR: member submits payer, date/time, amount, reference, slip (JPG/PNG/PDF ≤5 MB) → admin approves (plan starts) or rejects with a reason. All methods for all plans.
Also since 0.3: Members 0.3.1–0.3.6 fixes (login layout, account tabs, sidebar suggested menus + "Create the member menus", live search in the top bar via Hub 0.8.1, upgrade button target, Elementor editor fixes, [nhm_plan_card], new pricing table with suggested texts).
Since then: 0.4.1 (SMTP, paid signup goes straight to payment, 3-day due date for signup/upgrade invoices), 0.4.2 (invoice line "NeuroHR - Lite Membership (1 year)", QR on PDF, QR zoom, Block / Delete members, block list). Stripe test mode works on the live site (webhook 200).

## 0.4.3 — agreed 30 Oct 2026

Auto-renew (built in 0.4.3-beta1):
- Personal plans only (Lite, Pro); Corporate / Consulting never (per-level switch). Card only; FPX / GrabPay / transfer members get the normal renewal invoice, and My account offers "Add a card and turn on auto-renew" (no promo emails).
- Price lock while auto-renew stays on (option A): price rise ignored, price drop followed, upgrade → lock = upgrade price, downgrade / turn off / plan ends → lock cleared; staff can edit the lock.
- Reminders 7 + 3 days before (with the turn-off deadline), card-expiring email 30 days before, charge on the last day, renewed email with paid invoice. Failure → email, access kept 3 days, retry (or at once with a new card); second failure → renewal invoice with pay link at the locked price, auto-renew off, admin email.
- Pricing line "Renews automatically every year. Cancel any time."; turn-off asks an optional reason; admin: turn off, charge now / test-mode "Run renewal now".
- Registration: required tick box for Terms of Use + Privacy Policy pages (consent date kept). Payment page: required Refund Policy tick box (owner writes the page). Custom pickers: dd/mm/yyyy, Sunday first, 24-hour HH:mm.

Refunds (phase 2, to build):
- Member requests within **72 hours** of payment, reason + details; any number of requests over time. Access paused at once on request; every status change emails the member.
- Admin sees the member's usage since payment (Knowledge Hub opens/downloads, kept 90 days; Hub 0.8.2) and a "complete by" date (14 working days).
- Approve: amount defaults to paid minus the actual Stripe fee (editable); card → Stripe refund API; transfer/QR → owner pays back, then "Mark as refunded" with date + reference. Reject with reason → access restored.
- Any refund (full or partial, also of an upgrade) ends the plan at once and cancels auto-renew + lock. Refunds made in the Stripe dashboard sync. Chargeback → admin email + access paused (manual restore).
- **Refund Note** PDF (not credit note), own running number format in Business info (e.g. RN-{YYYY}-{####}), emailed and in My account.
- Reports: received / refunded / net; auto-renewals due in the next 30 days.
- Later: Privacy Policy text (usage logging), Google login.

## Backlog — noted 30 Oct 2026 (after 0.4.3), build together later

1. **My account → Invoices table UI**: cells have no padding / poor alignment (first column touches the border, actions column too wide). Rename "PDF" to **View invoice**; make "Request a refund" a proper button style that lines up with it.
2. **Request a refund pop-up**: broken layout on the live site: labels right-aligned, intro text runs off to the side with a horizontal scrollbar, title crowds the × button. Likely theme (Blocksy/Elementor) styles leaking into the dialog; restyle like the approved modal design.
3. **Invitation page** (replaces item 3 above; owner confirmed the invite already works through the register page): build a separate, invitation-only page. It shows the company name, the email (locked) and name / password / optional profile fields, plus the Terms tick box. No plans or pricing. People who already have an account see "Log in to accept".
4. **Company tab → seats table**: add padding and alignment. Add a **Remove** button per person (with a confirm pop-up; not for the owner), and pending invitations with Resend / Cancel.
5. **Security → Change password**: the two fields are misaligned; align the boxes and put the help text under them.
6. **GitHub**: push Members, Knowledge Hub and Simple Payroll to the owner's repositories once the repo names are given.
