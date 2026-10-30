# NeuroHR Members — v0.4.1

Replaces MemberPress on neurohr.com.my. It manages levels, personal plans, company accounts with seats, plan end dates and reminders, the login / registration / account pages (as shortcodes on your own pages), and members-only pages.
It also tells the Knowledge Hub (0.8.1) and Simple Payroll (0.5.0) which level each person has, gives logged-in people one member-area layout on every page, and hides WordPress's own login screens.
Members pay their invoice online (Stripe: card, FPX, GrabPay), by bank transfer or by DuitNow QR (with a slip you approve). The plan starts when the invoice is paid. Card auto-renew and Stripe refunds come in 0.4.2.

## New in 0.3 — quick tour

1. **Member area** (Settings → *Member area*): once logged in, people see every page — Dashboard, Knowledge Hub, HR News & Update, My account — inside one layout: sidebar menu on the left, top bar with search and their photo, name and *Plan · Company*, a card at the bottom of the sidebar, a small footer. On phones the sidebar is a drawer (☰). Visitors who are not logged in keep your Blocksy site.
2. **Menus**: Settings → Member area → *Create the member menus* makes them for you (Dashboard, all hub sections, HR News & Update, Simple Payroll, My account, Log out). Or in Appearance → Menus fill the locations *Member area: main menu*, *Member area: account menu*, *Member area: footer links*. Every menu item (in every menu, also Blocksy's header menu) has an **icon** and **Who sees this**: everyone / only visitors / only members / a plan or higher / exactly these plans. The box *NeuroHR Members* on the left adds **Log in / Log out**, **My account**, **Register**, **Upgrade** and **Member name + photo** — each shows itself only to the right people.
3. **Elementor**: every section, container, column and widget → Advanced tab → **NeuroHR: who sees this** (same choices). In the editor everything stays visible.
4. **Hidden WordPress** (Settings → Pages & login): `wp-login.php` and `/wp-admin` show *page not found* to visitors. Everyone, you too, logs in on your own login page; logout is `/logout/`. Author pages and XML-RPC are off; WordPress's own account emails use your templates.
5. **Registration**: new accounts confirm their email first (on by default); phone with country code (+60 first); *Location* with the Malaysian states and *Outside Malaysia*.
6. **Forgot password** is a pop-up on the login page; the email link comes back to the login page and opens *Choose a new password*.
7. **My account in tabs**: Profile (photo, second email, preferences), Security (password, devices, log out other devices), Notifications (MailerLite groups), Company (staff + the company's billing details, which the owner can now change), Plan & billing.
8. **Invoices** are always for a plan: pick the plan and 1–3 terms; the price per term can be changed; discount. No PAID stamp — a *Status* line instead, plus the membership period. Option *Level prices already include SST*.
9. **Companies**: you type the client code yourself (e.g. your own BP-2026-014). One price per company.

### If the login page ever breaks

There is **no secret login address** (it would be a back door). If you are locked out:
1. Log in to **cPanel → File Manager** → `public_html/wp-content/plugins/`.
2. Rename the folder `neurohr-members` to `neurohr-members-off`. WordPress switches the plugin off; `wp-login.php` works normally again.
3. Log in at `https://www.neurohr.com.my/wp-login.php`, then rename the folder back to `neurohr-members` and activate the plugin again (Plugins). Your data is untouched.
4. Fix the login page (Settings → Pages & login).

While no login page is set in Settings → Pages & login, `wp-login.php` keeps working, so you cannot lock yourself out while setting up.

## New in 0.2 — quick tour

1. **Settings → Business info & invoices**: your company name, SSM, TIN, address, logo, bank details. *Preview a sample invoice* to check the PDF.
2. **Levels**: give each paid level a **price** and **term** (one-time / recurring; lifetime, expire after N days/weeks/months/years, or a fixed date). Each level shows its **registration shortcode** `[nhm_register level="pro"]` and **link** `/register/?plan=pro`.
3. In your Elementor pricing table, link each CTA button to that level's link (or `[nhm_register_url level="pro"]`).
4. Someone clicks it → creates an account (Free) → gets the invoice by email → you see it under **Members → Dashboard / Invoices → Waiting to start a plan** → *Mark as paid* → the plan starts for one term and they get the paid invoice.
5. Members who are already logged in get **Get Pro / Renew Pro** instead of the form; it makes the invoice the same way.
6. **Settings → Registration → Form fields**: add your own questions (text, drop-down, tick boxes …), required or not.
7. **Settings → Emails**: every email in one list (Member notices / Admin notices) with *Edit*, *Send Test*, *Reset to Default*, and your *From* name and address.

## Install order

1. **Back up the site** (files + database).
2. Upload and activate **NeuroHR Members 0.3.0**. Then update **Knowledge Hub to 0.8.1** and **Simple Payroll to 0.5.0** (Plugins → Add New → Upload → *Replace current with uploaded*).
3. Keep MemberPress active for now. The hub and payroll already read levels from NeuroHR Members.
4. **Members → Settings → Pages & login**: pick or create the five pages (below).
5. **Members → Companies**: add your Corporate / Consulting clients. **Members → All members** → a person → *Membership*: give people their own plan.
6. Go through the test checklist. Then **deactivate MemberPress** (do not delete it yet; keep it a few weeks).
7. Members → Settings → Setup: every line should be green.

Updating from 0.2: upload 0.3 over it (*Replace current with uploaded*), then Knowledge Hub 0.8.1. Then: Settings → Member area (check), Appearance → Menus (fill the three member-area locations), Settings → Pages & login (*Members who open the home page go to* `/dashboard/`). Before switching **Hide WordPress** on, check that you can log in on your own login page in a private window.

## Levels (Members → Levels)

| ID | Level | Rank | Note |
|---|---|---|---|
| 1 | Free | 1 | Every logged-in person. Cannot be deleted (you can rename it). |
| 2 | Lite | 2 | |
| 3 | Pro | 3 | |
| 4 | Corporate | 4 | |
| 5 | Consulting | 4 | "Same access as the level above" = Corporate |

- **Rename** freely: other plugins store the ID, not the name.
- **Reorder**: drag the ☰ handle, then *Save levels*. A higher level opens everything below it.
- **Add**: *Add a level* puts it at the top; drag it to its place.
- **Delete**: asks where its people and companies go. Hub resources, hub type defaults, protected pages and the payroll setting that needed it move to the **next level up**.
- Each level has pricing details (price, note, description, what is included, button text and link) for `[nhm_pricing]`. The button link is also where "Upgrade to …" buttons go (e.g. your contact page or a WhatsApp link). Empty = pricing page.

## Plans for people

Members → All members → click a name → section **Membership** (on the WordPress user screen):
- **Own plan** + **Start date** + **Last day**. *+1 term* adds one term of the chosen level (e.g. 1 year) to the current last day (or from today). *No end date* clears it.
- **Last day** counts: access ends the day after. You do not need to downgrade anyone by hand.
- **Note**: invoice number, payment method … (only admins see it).
- **History** at the bottom: every change, reminder and ending.

The members list has tabs: **Paid plans**, **Ending within 30 days**, **Ended**, **Free**, plus filters by plan and company, search, and **Export CSV** (opens in Excel).

## Companies (Corporate / Consulting clients)

Members → Companies → **Add company**: name, plan, **client code** (automatic, e.g. NHR-C0001 — format in Business info), start, last day, **seats**, owner email, and billing details: **SSM no., TIN, SST no., address, postcode, city, state, country, phone, billing email, contact person, industry, employees**, notes.
*Create invoice / renewal* on the company page writes an invoice for the next term; marking it paid extends the company plan.
- **Owner email**: the client's HR contact. If they already have an account, they become owner at once. If not, they get an invitation email and become owner when they sign up.
- **Add people** (one email per line): existing accounts join at once, others get an invitation.
- Seats used = people in the company (owner included) + invitations not accepted yet.
- Everybody in the company gets the company's plan while it runs. If someone also has their own plan, the higher one counts.
- A person can be in one company only. Removing someone (or deleting the company) keeps their account; they go back to their own plan or Free.

The **owner** manages staff on **My account**: invite by email, send again, cancel, remove. Staff see who manages the account and can leave.
Invitation links are valid for 14 days (Settings → Protection & look). The invited email address must be the one used to accept.

## Invoices

Members → **Invoices**: tabs All / Waiting to start a plan / Unpaid / Paid / Void, search, PDF.
- **New invoice** (0.3): bill a person (by email) or a company, choose the **plan** and **1, 2 or 3 terms** (e.g. Pro × 2 = 2 years). The line is written for you ("Pro membership — 2 years"); the price per term starts at the level's price and can be changed; discount, SST, dates, a note. When paid, the plan starts (or extends, for an early renewal) for all the terms; the PDF shows the membership period.
- Business info → **Level prices already include SST**: the SST is then taken out of the total instead of added on top.
- **Mark as paid**: date, method (bank transfer, DuitNow, FPX, cheque …), reference. Starts the plan (once) and emails *Payment Received* with the PDF (Status: Paid).
- **Email invoice**, **Void** (stays listed as VOID; its plan never starts). Paid and void invoices cannot be edited (accounting records), only their note.
- Numbers come from the format in Business info (`INV-{YYYY}-{####}` → INV-2026-0001, restarting each year).
- "Bill to" is copied from the company (or the person's *Billing details* in My account) when the invoice is made; tick *Update the Bill to block* to refresh an unpaid one.
- **SST**: off by default. Turn on in Business info only if you are SST-registered — new invoices then become "Tax Invoice" with your SST no.
- **Recurring** levels: with the first reminder email a renewal invoice is created and attached automatically (Business info → switch).
- Members see their invoices (and their company's, for owners) in **My account → Invoices** and download the PDF.
- e-Invoice: from 1 Sep 2026 businesses below RM3 million yearly turnover are generally exempt from MyInvois; these PDFs are normal invoices. Check with your accountant.

## Payments (0.4)

**Set up (Members → Settings → Payments)**
1. *Bank transfer* is on when your bank details are filled in (Business info & invoices).
2. *DuitNow QR*: upload the QR picture from your bank and the name under it. Members type the amount themselves.
3. *Stripe*: in the Stripe Dashboard → Settings → Payment methods switch on Card, FPX and GrabPay. Developers → API keys → paste the **test** publishable and secret keys. Developers → Webhooks → Add endpoint `https://www.neurohr.com.my/wp-json/nhm/v1/stripe` with the events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` → copy the signing secret (whsec_…) into the test box. Save, *Check the secret key*, switch on *Pay online with Stripe*.
4. Test: open an unpaid invoice's payment link in a private window, pay with card 4242 4242 4242 4242 (any future date, any CVC). The invoice must become Paid and the plan start. Try FPX test banks too. Then repeat step 3 with the **live** keys and a live webhook, and choose *Live*.
5. Optional: a page with `[nhm_pay]` chosen as *Payment page*; otherwise the payment page opens on My account.

Never send your secret keys (sk_…, whsec_…) to anyone. Security plugins that block the REST API for visitors must allow `/wp-json/nhm/v1/stripe`.

**Paid sign-ups (0.4.1)**
- Registration form for a paid plan → straight to the payment page (no email confirmation, no login first). The button reads *Continue to payment*.
- Online payment: the account opens and the person is logged in. Bank transfer / QR: the account opens when you approve; the *Payment Received* email has the login link.
- Until paid the account cannot log in; trying to log in or signing up again with the same email sends the payment link again.
- Sign-up and upgrade invoices are due in 3 days (Settings `online_due_days`): reminder the day before, cancelled the day after; a new account that never paid is removed. Payments being checked are never cancelled. Renewal and hand-made invoices keep the normal due date.

**Email (SMTP)**: Settings → Emails → *Send through your mailbox*: cPanel mailbox, server e.g. `mail.neurohr.com.my`, port 465, SSL, full email as username, its password. *Send a test email to me*. Turn on SPF and DKIM in cPanel → Email Deliverability.

**How it works**
- The payment page shows the invoice and the ways to pay. The link carries the invoice's secret token, so a company's accounts team can pay without logging in.
- Stripe: the member pays on Stripe's page and comes back; the invoice is marked Paid (method Card / FPX / GrabPay, reference = Stripe payment ID) and the plan starts. The webhook does the same if the member closes the browser.
- Bank transfer / QR: the member fills in the form and uploads the slip → invoice shows *Checking payment* → you get *Payment to Check* → **Members → Payments → Waiting for check**: *Open slip*, then *Approve — start the plan* or *Reject* with a reason. The plan only starts after you approve.
- Upgrades (e.g. Lite → Pro): the full price of the new plan, starting from the payment date.
- Slips: `wp-content/uploads/nhm-private/slips/` (closed with .htaccess). Check once: opening that folder in a browser must give *403 Forbidden*.

## Pages and shortcodes

Members → Settings → **Pages & login**: choose the page for each, or click *Create page*. Design the pages in Elementor or Blocksy as you like and put the shortcode in a Shortcode widget/block. You can reuse the old MemberPress pages: replace their MemberPress shortcode.

| Page | Shortcode |
|---|---|
| Login | `[nhm_login]` |
| Registration | `[nhm_register]` — one plan: `[nhm_register level="pro"]`, or link to `/register/?plan=pro` |
| Lost password | optional since 0.3 — the login page has a pop-up. If you make a page: `[nhm_lost_password]` |
| My account | `[nhm_account]` — tabs; choose which: `[nhm_account tabs="profile,security,notifications,company,billing"]`. Link a tab: `/my-account/?tab=billing` |
| Pricing | `[nhm_pricing levels="free,lite,pro,corporate" note="All plans are billed yearly."]` — texts per level in Members → Levels (line under the name, price note, label above the card, features: `-` = greyed out, `**bold**`). *Fill in the suggested pricing texts* writes the agreed NeuroHR texts. |

For your own dashboard and other pages (and see *Menus* / *Elementor* below for showing parts to some people only):

| Shortcode | Shows |
|---|---|
| `[nhm_if level="pro"]…[nhm_else]…[/nhm_if]` | first part to Pro and above, second part to everyone else |
| `[nhm_if levels="lite,pro"]…[/nhm_if]` | exactly these levels |
| `[nhm_if guest="yes"]…[/nhm_if]` / `logged_in="yes"` / `not="yes"` | guests / logged-in / the opposite |
| `[nhm_plan_card]` | the member's plan card: plan, last day, days left, **Renew** (30 days before the end or after) and **Upgrade** (below the top plan) buttons, unpaid-invoice note. `style="light"` white card; `title=""` hides "Your plan" |
| `[nhm_user field="first_name"]` | name, first_name, email, level, expiry, days_left, status, company |
| `[nhm_logout text="Log out" redirect="/"]` | logout link |
| `[nhm_register_url level="pro"]` | only the registration link of a level (for Elementor button links) |

Also in Pages & login: where people go **after login** (default `/dashboard/`), after registration, after logout, the **Renew link** (reminder emails and the Renew button; e.g. WhatsApp), and:
- **Upgrade buttons** (My account, the *Upgrade* menu item) open the **Pricing page** when it is set; without one they open the next plan up (`/register/?plan=…`).
- **Members who open the home page go to** `/dashboard/` (editors and admins still see the home page)
- **Hide WordPress** (on): wp-login.php and /wp-admin → *page not found* for visitors; logout at /logout/
- **Links to wp-login.php open my pages** (used when *Hide WordPress* is off)
- **Hide the WordPress toolbar for members** (on)
- **Keep members out of wp-admin** (on) — they go to My account.

### Styling

Everything sits in `<div class="nhm …">`. Brand colour: Settings → Protection & look (default `#f47b20`); it overrides Blocksy's blue inside the forms.
To change the look, add CSS in Blocksy → Customizer → Additional CSS, for example:

```css
.nhm { --nhm-primary: #f47b20; --nhm-radius: 16px; --nhm-max: 460px; }
.nhm .nhm-btn { border-radius: 8px; text-transform: uppercase; }
.nhm-price-card.is-highlight { transform: scale(1.03); }
```

Main classes: `.nhm-form` `.nhm-field` `.nhm-input` `.nhm-btn` `.nhm-btn--primary` `.nhm-alert` `.nhm-card` `.nhm-plan` `.nhm-company` `.nhm-pricing` `.nhm-price-card` `.nhm-locked`. Variables: `--nhm-primary` `--nhm-ink` `--nhm-text` `--nhm-muted` `--nhm-line` `--nhm-soft` `--nhm-radius` `--nhm-shadow` `--nhm-max`.
Turn off *Use the built-in form style* to style everything yourself.

## Member area

Settings → **Member area**:
- *Use the member layout for logged-in people* (on). *Keep the Blocksy styles inside* (off — turn on only if text on plain pages looks too bare).
- Logo (image, or the text logo *NeuroHR* with *HR* in orange), search box text, the card at the bottom of the sidebar (title, text, arrow link), footer text (`{year}`, `{site}`).
- The search box searches the Knowledge Hub (`/knowledge/search/`), or the whole site without the hub.
- The top bar shows the photo, the name and *Corporate · BrightPath Sdn Bhd* (only the plan when the person has no company). Its menu: My account, My company (owners), Plan & billing, Site admin (staff), Log out.
- Which pages keep their own look: pages with the **Elementor Canvas** template, and pages where you tick **Don't use the member layout** (box in the page editor). Pages built with Elementor run full width inside the layout and bring their own title; normal pages get a title and a white card.
- Your own copy of the template: `wp-content/themes/blocksy-child/neurohr-members/shell.php`.
- Caching (LiteSpeed): logged-in pages are never cached, visitors get the normal cached site, so the two looks never mix.

### Menus and who sees them

Appearance → Menus → open a menu item:
- **Who sees this (NeuroHR)**: Everyone · Only visitors who are not logged in · Only logged-in members · Members with this plan or higher · Only members with exactly these plans. Sub-items of a hidden item are hidden too. Staff pass *plan or higher*.
- **Icon in the member-area sidebar**: home, dashboard, book, news, document, calendar, video, people, calculator, invoice, star, help, settings …
- Box **NeuroHR Members** (left): *Log in / Log out* (shows the right one), *My account* (members), *Register* (visitors), *Upgrade* (members below the top plan; goes to the next plan up), *Member name + photo* (members; put sub-items under it for a drop-down in Blocksy's header).

### Elementor

Advanced tab of a section, container, column or widget → **NeuroHR: who sees this**. Example: a "Create a free account" button only for visitors, a "Pro tools" container for Pro and up, an "Upgrade to Pro" banner only for Free and Lite. Check the result in a private window or with a test member.

## Registration

Settings → **Registration**:
- **Confirm the email address** (on): new people get a link and can log in after clicking it (*Send it again* link on the login form). Company invitations and accounts you add are confirmed already.
- Name, email and password are always asked. **Form fields**: Company, Phone (with country code, saved as +60123456789), Job title, Location (Malaysian states + Outside Malaysia) — built in, can be hidden — plus your own: text, long text, number, email, phone, website, date, drop-down, choose one, choose several, single tick box. For each: label, choices, placeholder, help text, *Required*, *Show on the registration form*, *Members can change it in My account*. Drag to reorder. Answers show on the member's profile, in the CSV export and in the *New Signup* email.
- Optional **terms** tick box (links allowed).
- Spam: hidden trap field, minimum fill time, max 5 sign-ups per hour per network, 6 failed logins → 15-minute wait. Optional **Cloudflare Turnstile** (free): add the two keys.
- **MailerLite**: API token + group ID → a "send me updates" tick box. Off by default; under the PDPA it is clearer when people tick it themselves. Two more group IDs (*New content*, *Events & webinars*) add switches in **My account → Notifications**; members join or leave the groups themselves. Plan and invoice emails are always on.
- The email to you about new accounts is *Admin notices → New Signup* in the Emails tab.

## Members-only pages

The page editor has a box **Who can see this page**: Everyone / Logged-in members (Free +) / Lite + / Pro + / Corporate / …
- Guests go to the login page and come back.
- Members below the level see an upgrade box (or are sent to the upgrade link — Settings → Protection & look).
- Editors and admins always see the page. The login, registration, lost-password and pricing pages always stay open.
- Set **/dashboard/** to *Logged-in members* to replace the MemberPress rule.
- More post types (e.g. Posts): Settings → Protection & look. Knowledge Hub resources keep their own levels.

## Emails

Settings → **Emails**: *From name*, *From email*, *Admin email addresses* (several allowed), reminder days, and two lists. Each email: tick box on/off, **Edit** (subject + text with placeholders), **Send Test**, **Reset to Default**.

| Member notices | When |
|---|---|
| Welcome Email | after registration |
| Account Created For You | import / accounts you make (link to choose a password) |
| Plan Application | paid signup or upgrade request — invoice attached |
| Invoice | you click *Email invoice*; renewal invoices — PDF attached |
| Payment Received | invoice marked paid — paid PDF attached |
| Company Invitation | owner or you invite someone |
| Added To Company | someone joins a company |
| Plan Ending Soon | 30 and 7 days before the last day (company: to the owner; renewal invoice attached for recurring plans) |
| Plan Ended | the day after the last day |

| Admin notices | When |
|---|---|
| New Signup | new account (with the registration answers) |
| New Plan Application | someone asked for a paid plan |
| Invitation Accepted / Member Left A Company | company changes |
| Plan Ending Soon (copy) / Plan Ended (copy) | copies for renewal follow-up |

Emails use the site's normal mail setup (SMTP plugin or host).
Each email goes out once per last day; changing the last day starts the reminders again. Nobody gets a reminder for a personal plan when a company plan already gives them the same or more.

**Daily check timing:** WordPress runs it when someone visits the site. For an exact time add a cron job in cPanel (Yeahhost), every 15 minutes:
`wget -q -O - https://www.neurohr.com.my/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
Settings → Setup → *Run the daily check now* sends anything that is due.

## Dashboard, bulk actions, import

- **Members → Dashboard**: accounts, paid plans, active companies, ending within 30 days, waiting for payment, paid this year, unpaid; lists of applications, plans ending soon, people per plan, newest accounts.
- **All members**: tick people → *Set own plan…* (level + last day, empty = one term), *Renew own plan by one term*, *Back to the free plan*, *Export selected*.
- **Members → Import**: CSV for companies (`name, client_code, reg_no, tin, …, plan, start, last_day, seats, owner_email`) and people (`email, name, plan, start, last_day, client_code, role` + your form fields by key or label). *Download template*, *Check only* first. Dates as 2026-10-23 or 23/10/2026. Addresses: separate lines with `|`.
- **My account → Billing details**: name / company / SSM / TIN / address / invoice email for personal invoices.
- **PDPA**: WordPress *Tools → Export / Erase Personal Data* include membership, form answers and billing details. Erasing keeps invoices (accounting records) and says so.

## Test checklist (before deactivating MemberPress)

- [ ] Payments: Stripe test card payment → invoice Paid, plan started, *Payment Received* email; bank transfer with a slip → *Waiting for check* → Approve → plan started; Reject → member email with the reason
- [ ] Settings → Setup: pages green, Knowledge Hub 0.8.0, Payroll 0.5.0, Business info, prices
- [ ] Private window: log in on your login page; then `/wp-login.php` and `/wp-admin/` (logged out) show *page not found*; logout goes through `/logout/`
- [ ] Logged in: the home page goes to /dashboard/; every page (hub, HR News & Update, My account) is in the member layout; on the phone ☰ opens the menu
- [ ] Menus: Register only for visitors, Log in turns into Log out, an item set to *Pro or higher* is hidden for a Free test account
- [ ] Elementor: a widget set to *Only visitors* disappears after logging in
- [ ] Register with a new Gmail → *check your email* → link → logged in, welcome email; logging in before clicking the link shows *Send it again*
- [ ] Login → *Forgot password?* opens the pop-up → email → link opens *Choose a new password* on the login page
- [ ] My account: upload a photo (shows in the top bar), add a second email (confirm link), Security → log out other devices, Notifications switches (check the MailerLite group)
- [ ] Company owner changes the TIN in My account → Company → you get the *Company Billing Details Changed* email; the next invoice shows the new TIN
- [ ] Business info → *Preview a sample invoice*: logo, address, bank details look right
- [ ] Private window: open `/register/?plan=pro` → plan box shows → create account → invoice email with PDF arrives → My account shows "starts when invoice … is paid"
- [ ] Admin: Invoices → *Waiting to start a plan* → Mark as paid → the account is Pro until one term later; *Payment Received* email with PAID PDF
- [ ] Logged in as that member: `/register/?plan=pro` shows *Renew Pro*; `/register/?plan=corporate` shows *Get Corporate*
- [ ] Add a custom field (e.g. drop-down "Company size", required) → it appears on the form, is checked, shows in the profile and CSV
- [ ] Emails tab: *Send Test* for Plan Application and Payment Received (PDF attached)
- [ ] Private window: open a hub article → login page → log in → back on the article
- [ ] Register a new test account (with a Gmail address) → welcome email arrives → lands on /dashboard/
- [ ] Lost password: email arrives, the link opens **your** login page, new password works
- [ ] Give the test account Pro until tomorrow → Pro video plays, upgrade box gone; change last day to yesterday → video locked again
- [ ] Add a test company with 2 seats, owner = test account → My account shows the company; invite your second email → accept from the email → that account reads Corporate content; seats show 2 of 2
- [ ] Owner removes the second account → it goes back to Free
- [ ] /dashboard/ set to Logged-in members: guest goes to login
- [ ] Simple Payroll: Pro account sees logo / address / pay date; Free account sees Upgrade
- [ ] /wp-admin/ as a member → My account; WordPress toolbar hidden for members
- [ ] Settings → Emails → *Send me a test* for each email
- [ ] Mobile: login, register and account pages look right

## For other plugins (API)

```php
if ( function_exists( 'nhm_levels' ) ) {
    nhm_levels();                     // ordered ID => [id, slug, name, rank, is_base, upgrade_url, …]
    nhm_user_level( $user_id );       // level ID of the current plan (0 = guest)
    nhm_user_rank( $user_id );        // rank (0 = guest)
    nhm_user_can_level( 'pro', $id ); // ID, slug or name; editors/admins always true
    nhm_user_membership( $id );       // level, rank, source, expiry, days_left, company_id, role
    nhm_level_rank( $level_id ); nhm_max_rank(); nhm_base_level_id();
    nhm_login_url( $back ); nhm_register_url(); nhm_account_url(); nhm_pricing_url(); nhm_upgrade_url( $level_id );
}
```
Hooks: `nhm_level_deleted( $deleted_id, $content_to_id, $members_to_id )`, `nhm_levels_saved`, `nhm_plan_changed`, `nhm_company_joined`, `nhm_company_left`, `nhm_user_registered`; filter `nhm_membership`.
Store level IDs, compare ranks. IDs are never reused.

## Data

- People: user meta `nhm_level`, `nhm_start`, `nhm_expiry`, `nhm_note`, `nhm_company`, `nhm_company_role`, `nhm_company_name`, `nhm_phone` (+60…), `nhm_job_title`, `nhm_location`, `nhm_last_login`, `nhm_prev_login`, `nhm_avatar`, `nhm_email2`, `nhm_email_pref`, `nhm_lang`, `nhm_tz`, `nhm_notify_*`, `nhm_verify_hash` (until confirmed).
- Photos: `wp-content/uploads/nhm-avatars/` (400 × 400 JPG). Menu items: post meta `_nhm_vis`, `_nhm_vis_level`, `_nhm_vis_levels`, `_nhm_icon`. Pages: `_nhm_no_shell`.
- Tables: `wp_nhm_companies`, `wp_nhm_invites`, `wp_nhm_invoices`, `wp_nhm_payments`, `wp_nhm_log`. Options: `nhm_levels`, `nhm_fields`, `nhm_settings`.
- PDF library: dompdf 3.1.6 (LGPL) in `vendor/dompdf`; temporary files in `wp-content/uploads/nhm-pdf/` (closed to the web).
- Updating from 0.1: upload 0.3 over it. Tables get the new columns, your 0.1 field settings become form fields, your admin email moves to *Admin email addresses*. Client codes are not made up any more: type your own in each company.
- Deactivating the plugin keeps all data. Without it, the hub falls back to MemberPress, and everyone is Free if MemberPress is also off.

## Not in this version

- Auto-renew for cards (0.4.1), Stripe refunds (0.4.2), Google login (later).
- Language and time zone in My account are saved but not used yet.
- MyInvois e-invoice submission, credit notes, separate official receipts (the paid invoice shows *Status: Paid*).
- Coupons, MemberPress-style rules by category.
- Sub-pages do not inherit protection: set each page.
- PDF fonts: Latin script (English / Malay). Chinese characters in names or addresses would not print in the PDF.

Version history: Members → Settings → Changelog.
