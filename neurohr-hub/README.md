# NeuroHR Knowledge Hub — v0.14.0

**New in 0.14:** the phone look (timeline lists, template cards, article, template bar) moved to the **NeuroHR App** plugin, which also adds a header with a back button, section chips and the newest item on phones. Install NeuroHR App 0.1 together with this version.

**New in 0.13:** *Saved* (bookmark on every resource; list at `/{HR Insights address}/saved/`, e.g. `/hr-insights/saved/`), a *Share* button, and the phone look: timeline lists with dates, template cards with file badges and download/lock icons, Save + Share on resources, a sticky "Ready-to-use template" bar (from the resource's Templates to Download). Feeds "Latest for You" (in the reader's language) and the bottom-bar / More links of NeuroHR Members 0.5; hidden sections get no link. If the Saved page gives a 404 after updating, Settings → Permalinks → Save once.

**New in 0.12:** sections and Resource Types are managed in Settings (names, addresses, types, topic groups, new sections); types can be renamed freely.

**New in 0.11:** content languages (中文 / English, Malay reserved), Attachments box with protected files, PDF and pictures open in a new tab, `[nh_hub_latest]` with several sections and topics, Settings → Shortcodes.

**New in 0.10:** free samples without login, editable addresses, grouped references with "Referenced in" and inline cards, and FAQ anywhere (see the sections below).

**New in 0.9:** paid videos can be a video file kept on your own hosting, protected like Templates (see Videos).

**New in 0.8:** with NeuroHR Members 0.3, logged-in people see all hub pages (and HR News & Update) inside the member-area layout — sidebar, top bar with search and their name. Visitors see the normal site as before. The member top bar searches the whole hub, with results while typing (0.8.1).

Lightweight replacement for BetterDocs, Document Library Lite and Tutor LMS:
five sections (Knowledge Hub, Legal Case Insights, FAQ, Templates, Videos), live search,
and the same design for news posts. Colours follow your brand orange, also under Blocksy.

## Sections

Each section has its own address, home page, topics and search. Managed in **Settings → Sections**.

| Section | Address | Resource Type (behaviour) | Topics |
|---|---|---|---|
| HR Insights | `/hr-insights/` | HR Insights (Article) | HR Topics (15) |
| Legal Case Studies | `/legal-case-studies/` | Legal Case Studies (Legal case) | Case Topics (7 legal categories) |
| HR FAQs | `/hr-faqs/` | HR FAQs (FAQ) | none — one list of questions |
| HR Templates | `/hr-templates/` | HR Templates (Template download) | HR Topics |
| Video Learning | `/video-learning/` | Video Learning (Video) | HR Topics |

Before 0.12 the addresses were `/knowledge/`, `/legal-case-insights/`, `/faq/`, `/templates/`, `/videos/`: they all redirect (301) to the new ones, also topic pages, resources and search.

Inside a section:
- `/{section}/` — home: title, live search, topic cards (FAQ: the questions)
- `/{section}/{topic}/` — e.g. `/legal-case-studies/dismissal-cases/`
- `/{section}/?q=…` — search results
- `/{section}/{resource}/` — the resource itself; the section follows its Resource Type
- `/knowledge/tag/{tag}/` — a tag, across sections
- Search across all sections: `/hr-insights/search/?q=…` (the HR Insights address + `/search/`)

**Settings → Sections**, per section: name (breadcrumb, labels), address, the Resource Types in it (a type belongs to one section; ticking a type that is elsewhere moves it), topic group, heading texts and image, order, shown / hidden (hidden = its pages are gone for members; its resources still open). Also: **Add a section** (then tick its types and topics) and **Topic groups** (HR Topics, Case Topics, or a new group of its own, e.g. Guide Topics; its topics appear in the Knowledge Hub menu). Built-in sections can be renamed but not deleted; your own sections can be deleted once they have no types.
Changing an address: the old one keeps redirecting. An address used by a page, another section or WordPress itself is refused with a message.

**Resource Types** (Knowledge Hub → Resource Types) can be renamed freely, also the slug. **Settings → Resource types**: what each type behaves as — Article (reading), Legal case, FAQ, Video (video box and player), Template download (protected file) — its section and its default level. A new type: create it, choose its behaviour there, then tick it in a section. Types in no section are flagged on every hub screen.

The feed for MailerLite stays at `/knowledge/feed/`.

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

## Free samples (no login)

Pick **Everyone (no login)** in a resource's Access box (one resource at a time; a whole type cannot be opened while the hub needs a login). Then:
- visitors can read it, and play its video or download its template, without logging in; under it they see **"This is a free sample… Create a free account"**;
- it is the only kind of hub page Google may list (in the sitemap, no noindex). Section pages, topic pages and every other resource still send visitors to login;
- links from it to other resources show a lock for visitors.
Show your samples on a public page with `[nh_hub_latest section="all" access="public" count="6"]` (or `section="knowledge,videos"`, `topic="termination-dismissal"`). Every shortcode with its options: **Settings → Shortcodes**.

## Changing a resource's address

Editor → right sidebar → **Link** (or Summary → URL): change the slug and update. The old address redirects (301) to the new one, so links in emails and on social media keep working. Visitors are only sent straight to the new address when the resource is a free sample; otherwise they log in first.

## Languages (中文 / English)

The hub interface stays English; content can be 中文 or English. Bahasa Melayu is reserved: switch it on in **Settings → General → Languages** when you start Malay content. There you also choose what readers see first (default 中文) and the language of new resources (default 中文). All content from before 0.11 is English.

- Editor → **Language & translations** (right side): the language of the resource. To add a translation: **+ Create 中文 version** makes a linked draft copy (same type, topics, tags, level, picture, FAQ and references) to translate; or link an existing resource with *This is a translation of*. A video or template file, and attachments, are not copied: the translation gets its own.
- Readers see each content **once**, in their language: the **Language** switch on section pages (remembered), else the language in their NeuroHR Members → My account, else the default. Content without a translation in their language shows in the language it has, with a small label (e.g. `EN`); content with two versions shows `中文 · EN`.
- **Search** looks in every version (an English word finds the Chinese version too) and shows each content once.
- A translated resource shows **Also available in 中文 | English** under its title.
- All Resources has a Language column (↔ links to the other version) and a language filter.
- LiteSpeed Cache keeps one copy per language (the plugin sends the `X-LiteSpeed-Vary` header).

## Attachments

Editor → **Attachments**: *Upload or choose files…* (PDF, Word, Excel, PowerPoint, CSV, TXT, ZIP, pictures) or *Add a link*. Rename (the name members see), reorder with ↑ ↓, remove.
- Uploaded files move into `wp-content/uploads/nh-protected/attachments/` and leave the media library. Only readers of the resource can open them (visitors too on a free sample). Removing an attachment deletes its file.
- Members below the level see the names with a lock and "Available to … members".
- **PDF and pictures open in a new tab**; other files download. The same now applies to Templates.
- Old BetterDocs attachments (files still in the public media library) show **Move to protected folder** in the box; or do all at once: Knowledge Hub → Import BetterDocs → **Protect all attachment files**. They stay attachments of their resource.
- Attachment downloads are not counted on the Dashboard.

## Templates (protected downloads)

1. Add Resource → Resource Type **Download**, pick an HR Topic, write an excerpt.
2. In the **Template file** box: *Choose or upload file…* (PDF, Word, Excel, PowerPoint, CSV, TXT, ZIP). Or paste a link to a file on another site.
3. Save. The file is moved into `wp-content/uploads/nh-protected/` under a random name and **removed from the public media library**.

Members download through a checked link: guests go to login, members below the level see the upgrade box, allowed members get the file. Downloads are counted (Dashboard → Most downloaded, Downloads column).

**Check once on your server:** after saving one template, open `https://www.neurohr.com.my/wp-content/uploads/nh-protected/` in a private window. You must get **403 Forbidden**. If you can see or open files, tell me — the server is not reading `.htaccess`.

**BetterDocs attachments:** Knowledge Hub → Import BetterDocs → *Move attachments into protected downloads*. Files stored on your site (e.g. AWARD_19842.pdf) become Templates readable by the same members as the case, linked under it. Links to other sites (the hasil.gov.my PDF) stay as links. Afterwards delete the original files from Media (the report lists them).

## Videos

Resource Type **Video**, set a featured image (cover of the player; locked members see it instead). Two ways:

**A. Video file on your own hosting — for paid videos (recommended while there are few)**
- Video box → **Choose or upload video…** (MP4). On save it moves into `wp-content/uploads/nh-protected/videos/` and leaves the public media library.
- Too big for the upload limit (shown in the box)? Upload it with cPanel File Manager or FTP into `wp-content/uploads/nh-protected/incoming/`, then pick it in the Video box and save.
- Members play it through `?nh_video={id}`. Every request checks login and level: a copied address plays for nobody else, opening it in a browser tab sends you to the resource page, and other sites cannot play it. No YouTube/Vimeo title, logo or link on the player; no download button.
- Served in 2 MB pieces, so seeking works, iPhone works and shared hosting stays responsive.
- **Prepare the file** with the free HandBrake: preset *Fast 720p30*, Format *MP4*, tick *Web Optimized*. About 10 MB per minute; 50 videos of 5 minutes ≈ 2.5 GB. With 200 GB monthly bandwidth that is roughly 4,000 full plays a month.
- Cannot be stopped: screen recording, and a member with the right level who knows the developer tools can still save it. The protection is against passing the link to people without access.
- **Check once on your server:** play a video as a member, then open `https://www.neurohr.com.my/wp-content/uploads/nh-protected/videos/` in a private window — you must get 403 Forbidden.

**B. YouTube or Vimeo link** (used when there is no video file). Members who may watch see the player; others see the cover with "Available to Pro members" — the video address is never in their page. An unlisted YouTube link can be watched by anyone who has it, and YouTube always shows its title and logo. Vimeo can hide title and buttons and restrict playback to your domain, on a paid plan.

## Links for your own member dashboard

The plugin no longer has a member dashboard; keep your own Elementor design and point its buttons here:

| Button | Link |
|---|---|
| HR Insights | `/hr-insights/` |
| Legal Case Studies | `/legal-case-studies/` |
| HR FAQs | `/hr-faqs/` |
| HR Templates | `/hr-templates/` |
| Video Learning | `/video-learning/` |
| HR News & Update | your Posts page (Settings → Reading) |
| Search everything | `/hr-insights/search/?q=` — or put `[nh_hub_search section="all"]` on the page |

A topic: `/{section}/{topic}/`, e.g. `/legal-case-studies/dismissal-cases/`.

## Admin screens

- **All Resources**: tabs per section (All, Knowledge Hub, Legal Case Insights, FAQ, Templates, Videos, plus "No type" when something has no type). Inside a tab: a topic filter, only that section's columns, and **Add to {section}** which starts a new resource with the right type ticked.
- **Dashboard**: a tile per section, most viewed and most downloaded, *Needs attention* (click a line to see the items), searches with no results, content by level.
- **Settings** tabs: General (login, languages, content review, addresses, pricing page, MailerLite feed) · **Sections** (sections, addresses, types, topic groups, heading texts) · Membership levels · **Resource types** (behaviour, default level) · Look (brand colour, news posts, print notice) · **Shortcodes** (every shortcode, options, examples to copy) · Changelog. Each tab saves only its own fields.

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

## References (related resources)

Editor → **References** box: search (filter by kind) and add as many resources as you like, from any section. Under the resource they are shown in groups: **Further Reading** (articles), **Related Legal Cases**, **Related Videos**, **Templates to Download**, **Related Questions**.
- **Inline card:** "Copy card" gives `[nh_ref id="123"]` — paste it into the text (as a Shortcode block) and that resource appears there as a small card ("Download", "Watch", "Read the case"). Optional button text: `[nh_ref id="123" label="Download the letter"]`.
- **Referenced in:** shown only to you in the References box (resources that picked this one); readers do not see it.
- **You May Also Like:** 4 automatic suggestions (same topic first, then shared tags, one from another section). Tick *Do not show automatic suggestions* to switch them off for one resource.
Items the reader cannot open show a lock; their content is never shown.

## FAQ anywhere

FAQ items are resources with Resource Type **FAQ**; each keeps its own level — **Everyone** = public answer, otherwise members of that level and up.
1. Knowledge Hub → **FAQ Groups**: create groups (e.g. Membership & Payment, Payroll). Tick a group on each FAQ item.
2. Show them:
   - on any page or post (also Elementor's Shortcode widget): `[nh_faq group="membership-payment"]`, several groups `group="payroll,leave"`, chosen questions `[nh_faq ids="12,15"]`, optional `title="…"`;
   - under a resource: editor → **FAQ under this resource** (a group and/or single questions);
   - `/faq/` lists every question under its group's name ("Other questions" for those without a group).
3. Locked answers show only the question with "Log in · Create a free account" (visitors) or "Upgrade" (members).
Public answers are also given to Google as FAQ data (once per page).

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
- `[nh_hub_latest section="cases" count="6"]` — several: `section="knowledge,videos"`, by topic: `topic="dismissal-cases"`
- `[nh_hub_sections]` — links to every section with counts

## Writing content

- Use the normal WordPress editor, not Elementor.
- Set the **Resource Type** first (it decides the section), then the topic of that section.
- **Heading 2** for sections, **Heading 3** for sub-points: they become the table of contents.
- **Write an Excerpt** — it is shown in lists and to members below the required level.

## Test checklist (before going live)

- [ ] Guest: every `/hr-insights/`, `/legal-case-studies/` and `/hr-faqs/` page goes to login and back (free samples excepted)
- [ ] Old addresses (`/knowledge/`, `/videos/`, `/legal-case-insights/dismissal-cases/`) redirect to the new ones
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
