# NeuroHR Knowledge Hub — v0.8.1

**New in 0.8:** with NeuroHR Members 0.3, logged-in people see all hub pages (and HR News & Update) inside the member-area layout — sidebar, top bar with search and their name. Visitors see the normal site as before. The member top bar searches the whole hub, with results while typing (0.8.1).

Lightweight replacement for BetterDocs, Document Library Lite and Tutor LMS:
five sections (Knowledge Hub, Legal Case Insights, FAQ, Templates, Videos), live search,
and the same design for news posts. Colours follow your brand orange, also under Blocksy.

## Sections

Each section has its own address, home page, topics and search.

| Section | Address | Contains | Topics |
|---|---|---|---|
| Knowledge Hub | `/knowledge/` | Articles | HR Topics (15) |
| Legal Case Insights | `/legal-case-insights/` | Case studies | Case Topics (7 legal categories) |
| FAQ | `/faq/` | FAQs | none — one list of questions |
| Templates | `/templates/` | Downloads (protected files) | HR Topics |
| Videos | `/videos/` | YouTube / Vimeo videos | HR Topics |

Inside a section:
- `/{section}/` — home: title, live search, topic cards (FAQ: the questions)
- `/{section}/{topic}/` — e.g. `/legal-case-insights/dismissal-cases/`
- `/{section}/?q=…` — search results
- `/{section}/{resource}/` — the resource itself; the section follows its Resource Type
- `/knowledge/tag/{tag}/` — a tag, across sections

**No normal WordPress page may use `knowledge`, `legal-case-insights`, `faq`, `templates` or `videos` as its address**, or the section takes over that address.
Search across all sections: `/knowledge/search/?q=…` .

Old addresses redirect (301): old article and case links, BetterDocs category pages, and v0.3 addresses.

## Login required

The whole hub needs a login (Settings → General → Access, on by default). Guests go to the NeuroHR Members login page (MemberPress login page if NeuroHR Members is not active) and come back afterwards. Hub pages stay out of Google, sitemaps, site search and the REST API for guests.

## Access levels

Levels come from **NeuroHR Members** (Members → Levels). Rename, reorder, add or delete them there; the hub follows. Knowledge Hub → Settings → Membership levels shows the current ladder.

| ID | Level | Who |
|---|---|---|
| 1 | Free | Any logged-in user |
| 2 | Lite | |
| 3 | Pro | |
| 4 | Corporate | |
| 5 | Consulting | same access as Corporate |

Higher levels read everything below them. Editors and admins see everything. Resources store the level ID, so renaming a level changes nothing; deleting a level moves its resources and type defaults to the next level up.
Without NeuroHR Members the hub reads MemberPress as before (memberships 6366 Free, 6373 Lite, 6376 Pro, 6379 + 6382 Corporate).
Defaults per type (Settings → Type defaults): Article, Case Study, FAQ → Lite; Video, Download → Pro. Pick a level on a resource to override it (e.g. an article opened to Free). The imported platform FAQs are Free.

## Templates (protected downloads)

1. Add Resource → Resource Type **Download**, pick an HR Topic, write an excerpt.
2. In the **Template file** box: *Choose or upload file…* (PDF, Word, Excel, PowerPoint, CSV, TXT, ZIP). Or paste a link to a file on another site.
3. Save. The file is moved into `wp-content/uploads/nh-protected/` under a random name and **removed from the public media library**.

Members download through a checked link: guests go to login, members below the level see the upgrade box, allowed members get the file. Downloads are counted (Dashboard → Most downloaded, Downloads column).

**Check once on your server:** after saving one template, open `https://www.neurohr.com.my/wp-content/uploads/nh-protected/` in a private window. You must get **403 Forbidden**. If you can see or open files, tell me — the server is not reading `.htaccess`.

**BetterDocs attachments:** Knowledge Hub → Import BetterDocs → *Move attachments into protected downloads*. Files stored on your site (e.g. AWARD_19842.pdf) become Templates readable by the same members as the case, linked under it. Links to other sites (the hasil.gov.my PDF) stay as links. Afterwards delete the original files from Media (the report lists them).

## Videos

Resource Type **Video** → paste a YouTube or Vimeo link in the **Video** box (optional length), set a featured image.
Members who may watch see the player; others see the featured image with "Available to Pro members" — the video address is never in their page.
For paid videos use **Vimeo with "embed only on specific domains"** (your site). An unlisted YouTube link works for anyone who has it.

## Links for your own member dashboard

The plugin no longer has a member dashboard; keep your own Elementor design and point its buttons here:

| Button | Link |
|---|---|
| Knowledge Hub | `/knowledge/` |
| Legal Case Insights | `/legal-case-insights/` |
| FAQ | `/faq/` |
| Templates | `/templates/` |
| Videos | `/videos/` |
| HR News & Update | your Posts page (Settings → Reading) |
| Search everything | `/knowledge/search/?q=` — or put `[nh_hub_search section="all"]` on the page |

A topic: `/knowledge/{topic}/`, e.g. `/legal-case-insights/dismissal-cases/`.

## Admin screens

- **All Resources**: tabs per section (All, Knowledge Hub, Legal Case Insights, FAQ, Templates, Videos, plus "No type" when something has no type). Inside a tab: a topic filter, only that section's columns, and **Add to {section}** which starts a new resource with the right type ticked.
- **Dashboard**: a tile per section, most viewed and most downloaded, *Needs attention* (click a line to see the items), searches with no results, content by level.
- **Settings** tabs: General (login, addresses, pricing page, MailerLite feed) · Sections (hero texts) · Membership levels · Type defaults · Look (brand colour, news posts) · Changelog. Each tab saves only its own fields.

## Content review ("Last reviewed")

Update existing content instead of adding new versions, then mark it as reviewed:
- Editor → **Content review** box → tick **Reviewed today** (or pick a date). Or in All Resources: tick several → Bulk actions → **Mark as reviewed today**.
- Members see **Last reviewed {date}** on the page (or *Published {date}* if never reviewed), and an **Updated** badge in lists and topic cards for 30 days.
- Dashboard → Needs attention → **Due for review**: not reviewed (or published) for 12 months. The Reviewed column shows "due" in red and can be sorted.
- Change the 12 months / 30 days in Settings → General → Content review.

At first almost everything shows as due (the imported content is from 2025). Go through it once and bulk-mark what is still correct.

## Upgrade interest

Dashboard → **Upgrade interest**: for each resource that members opened above their plan, how many members of each level below the top opened it and how many clicked the upgrade button. Editors, admins and bots are not counted. Use it to decide what to write more of and what to keep behind Pro.

## Print / PDF

Members who may read a resource get a **Print / PDF** button. The printout has only the article (no menu, sidebar, related, search), the site name and address at the top, and the copyright notice at the end and, in Chrome and Edge, at the foot of every page with the page number.
Edit the notice in Settings → Look → Print & PDF. Placeholders: `{year} {site} {name} {email} {date} {url}` — `{name}` and `{email}` are the member who prints, which discourages passing copies around. It cannot stop someone copying the text; it makes the source and the licensee visible.

## Topics

**HR Topics** (Knowledge Hub; later also Templates and Videos): Employment Law & Compliance · Employment Contracts · Termination & Dismissal · Discipline & Conduct · Probation & Confirmation · Workplace Safety & Health · Occupational Health Psychology · Talent Acquisition · Performance & Growth · Learning & Capability Building · Total Rewards · Workforce & Talent Management · Culture & Engagement · HR Strategy & Function · HR Tech & Analytics

**Case Topics** (Legal Case Insights): Dismissal Cases · Misconduct & Disciplinary Actions · Probation & Confirmation · Employment Contract Disputes · Discrimination & Harassment · Human Rights · Workplace Safety & Forced Labour

Topic cards only show topics that have content. Each topic has an **Icon** and an **Order** (Knowledge Hub → HR Topics / Case Topics → edit). "HR for SMEs" is the **SME** tag.

## Search

Typing 2+ letters in any search box shows results right away, from that section only (on a topic page: that topic only). Arrow keys and Enter pick a result; Enter alone opens the full results page. Searches that find nothing are listed on the Dashboard.

## Related resources

Shown under every resource. Pick them in the editor's "Related Resources" box (any section). If fewer than 4 are picked, the rest are suggested: same topic first, then shared tags, and one slot goes to the best match from another section (e.g. an article under a case).

## News posts (HR News & Update)

News and regulations are ordinary WordPress posts. With Settings → General → "News posts" on (default), single posts get the hub article layout (breadcrumb, reading time, table of contents) and the posts page and post categories get the hub list design. Set **Settings → Reading → Posts page** so the breadcrumb links to your news list. Posts are not part of the membership levels.

## Importing from BetterDocs

1. On the BetterDocs site: Tools → Export → **Docs** (and **FAQ**) → download the XML file.
2. **Back up the database** of the site you import into.
3. Install and activate this plugin. Deactivate BetterDocs on that site.
4. Knowledge Hub → Import BetterDocs → upload the file → check the preview → **Run import**.
5. Settings → Permalinks → Save.

Articles get HR Topics, cases get Case Topics, FAQs become Free. Excerpts, view counts, related articles and attachments come along. Existing slugs are skipped, so running it twice is safe.
Tested with the 22 Oct 2026 export: 52 Articles, 9 Case Studies (7 Dismissal, 2 Human Rights), 4 FAQs, 0 failures.

**Upgrading from v0.3:** nothing to do. Cases move to Case Topics automatically on the first page load after the update.

## Hub home layout (optional)

The built-in Knowledge Hub home is recommended. To design it yourself, build a page (Blocksy editor or Elementor), choose it under Settings → General → Knowledge Hub home layout, and use shortcodes for the live parts:

- `[nh_hub_search section="knowledge"]`
- `[nh_hub_topics section="cases" items="5" columns="3"]`
- `[nh_hub_latest section="cases" count="6"]`
- `[nh_hub_sections]` — links to every section with counts

## Writing content

- Use the normal WordPress editor, not Elementor.
- Set the **Resource Type** first (it decides the section), then the topic of that section.
- **Heading 2** for sections, **Heading 3** for sub-points: they become the table of contents.
- **Write an Excerpt** — it is shown in lists and to members below the required level.

## Test checklist (before going live)

- [ ] Guest: every `/knowledge/`, `/legal-case-insights/` and `/faq/` page goes to login and back
- [ ] Free / Lite / Pro / Corporate see full content only at or below their level
- [ ] Each section home shows only its own topics; search stays in its section
- [ ] Old links (`/knowledge/{case}/`, `/knowledge/knowledge-hub/employment-law-compliance/`) redirect
- [ ] A news post shows the hub layout; the site front page is unchanged
- [ ] Templates: Pro downloads, Lite sees the upgrade box, guest goes to login; `/wp-content/uploads/nh-protected/` gives 403
- [ ] Videos: Pro sees the player, Lite sees the locked image
- [ ] Print / PDF from Chrome: only the article, notice with the member's name on every page
- [ ] With Blocksy active: search button orange and round, no blue hovers in the hub

## Outside the plugin

- **Blocksy**: the hub forces its own orange, but set Blocksy's palette colour 1 to the same orange so the rest of the site matches.
- **LiteSpeed Cache**: Enable Cache ON, Cache Logged-in Users OFF. Ask Yeahhost about Redis/Memcached object cache — with the hub behind login this matters most for speed.
- **MailerLite**: RSS campaign with the feed address from Settings → General (includes a private key).
- **SiteSEO**: exclude the Resource post type and the hub taxonomies from its sitemap and set them to noindex.
- **NeuroHR Members**: replaces MemberPress (login, registration, account, levels, companies). After login members go to `/dashboard/` (Members → Settings → Pages & login).

## Known follow-ups

- `AWARD_19842.pdf` is still public until you run *Move attachments into protected downloads* and delete the original from Media.
- "The Strategic Triangle for Organizational Resilience" exists twice (Dashboard → duplicate titles).

Version history: Knowledge Hub → Settings → Changelog.
