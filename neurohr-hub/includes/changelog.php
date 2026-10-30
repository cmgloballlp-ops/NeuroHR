<?php
/**
 * Plugin changelog, shown under Knowledge Hub → Settings → Changelog.
 * Add the newest version at the top.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'0.8.2' => array(
		'date'    => '2026-10-30',
		'changes' => array(
			'Keeps a short record of what each member opened and downloaded (kept 90 days, staff not counted). NeuroHR Members 0.4.3 shows it next to refund requests.',
		),
	),
	'0.8.1' => array(
		'date'    => '2026-10-23',
		'changes' => array(
			'The NeuroHR Members top-bar search gets the hub\'s live results while typing.',
		),
	),
	'0.8.0' => array(
		'date'    => '2026-10-23',
		'changes' => array(
			'Logged-in people see hub pages (sections, topics, resources, HR News & Update) inside the NeuroHR Members member layout: sidebar, top bar with search and their name. Needs NeuroHR Members 0.3.0. Visitors who are not logged in see the normal site as before.',
			'The search box in the member top bar searches the whole hub as you type: results appear in a list under the box (Enter opens all results).',
		),
	),
	'0.7.0' => array(
		'date'    => '2026-10-23',
		'changes' => array(
			'Levels now come from NeuroHR Members: names, order and new levels follow the Members → Levels screen. Existing resource levels keep working (same IDs 1–4).',
			'Deleting a level in Members moves resources and type defaults that needed it to the next level up.',
			'Guests go to the NeuroHR Members login page; "Upgrade" buttons use each level\'s upgrade link; "Create a free account" opens the registration page.',
			'Dashboard → Upgrade interest shows a column for every level below the top.',
			'Without NeuroHR Members the hub still reads MemberPress as before.',
		),
	),
	'0.6.1' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Fixed: "Choose or upload file…" in the Template file box did nothing in the block editor. Image pickers on the settings and topic screens made safe the same way.',
		),
	),
	'0.6.0' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Content review: "Reviewed today" in the editor, "Last reviewed" date on the page, an "Updated" badge in lists after a review, a Reviewed column and a "Mark as reviewed today" bulk action, and "Due for review" on the Dashboard (Settings → General → Content review).',
			'Upgrade interest on the Dashboard: locked views per member level and upgrade button clicks for each resource.',
			'Print / PDF button on resources, printing only the article with a copyright notice that names the member (Settings → Look → Print & PDF).',
		),
	),
	'0.5.2' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Topic cards fit together with no empty space under shorter cards, still in left-to-right order.',
		),
	),
	'0.5.1' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Brand colour wins over the theme inside the hub (no more blue hovers or blue buttons with Blocksy).',
			'Round search box with an orange button; search results and list rows turn orange on hover.',
			'Topic cards are laid out left to right.',
			'All Resources: a tab per section, a topic filter, only the columns that matter, and "Add to {section}" buttons that preset the type.',
			'Settings split into General, Sections, Membership levels, Type defaults, Look and Changelog, grouped in cards. Saving one tab no longer touches the others.',
			'Dashboard redesigned: section tiles, ranked lists, a clearer "Needs attention" panel, content by level bars.',
			'Member dashboard removed (you keep your own design).',
			'Fixed: the Dashboard and Type defaults pages crashed in 0.4.0/0.5.0.',
		),
	),
	'0.5.0' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Templates section (/templates/): files are moved into a protected folder; members download through a checked link; downloads are counted.',
			'Videos section (/videos/): YouTube or Vimeo; the player is only shown to members who may watch.',
			'Member dashboard [nh_member_dashboard]: plan and upgrade, search across all sections, section tiles with new items, extra links, what is new, most read.',
			'Search across all sections at /knowledge/search/.',
			'Tool to move BetterDocs attachments stored on the site into protected downloads.',
			'Dashboard (admin): most downloaded, Downloads column.',
		),
	),
	'0.4.0' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Separate sections, each with its own address, home and topics: Knowledge Hub (/knowledge/), Legal Case Insights (/legal-case-insights/), FAQ (/faq/).',
			'Case studies use their own Case Topics again (the original legal categories). Existing cases are moved automatically.',
			'Live search: results appear while typing, searching only the current section (or topic). Enter opens all results.',
			'One design everywhere: section homes, topic pages and search results share the same look; the old filter bar and tabs are gone.',
			'WordPress posts (HR News & Update) can use the same design (Settings → General).',
			'Old /knowledge/{case}/ links and v0.3 addresses redirect to the new ones.',
			'News and Regulation resource types removed (news lives in WordPress posts).',
		),
	),
	'0.3.2' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Login required for the whole Knowledge Hub (Settings → General, on by default). Guests go to the MemberPress login page and come back afterwards.',
			'Hub kept out of search engines, sitemaps, site search and the REST API for guests.',
			'RSS feed for MailerLite works through a private key.',
			'"Public" level hidden while login is required; imported BetterDocs FAQs become Free.',
		),
	),
	'0.3.1' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Article pages: table of contents (sticky on desktop, collapsible on mobile), reading time, full breadcrumb, brand-coloured title.',
			'Locked articles still show their table of contents as a preview, linking to the upgrade box.',
			'New hub home: hero, search, type buttons and topic cards like the old BetterDocs page.',
			'Choose an Elementor page as the hub home; shortcodes [nh_hub_search], [nh_hub_types], [nh_hub_topics], [nh_hub_latest].',
			'Topic icon and display order fields.',
			'Settings → General: hero text and image, brand colour, items per topic card.',
		),
	),
	'0.3.0' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Import from a BetterDocs export file (XML) with preview; maps the 19 old categories to the new 16 topics.',
			'Carries over excerpts, view counts, related articles and attachments. BetterDocs FAQs become Public.',
			'"HR for SMEs" became the SME tag.',
			'New URLs: /knowledge/{type}/, /knowledge/{topic}/ and /knowledge/{type}/{topic}/. Old BetterDocs category URLs redirect.',
			'Topic pages show one section per type (articles, case studies, FAQs …).',
			'Related Resources box in the editor, with automatic suggestions by tag and topic.',
			'Dashboard: counts per type and level, most viewed, content health checks, searches with no results.',
			'View counting that works with LiteSpeed page cache.',
			'Settings split into tabs; this changelog.',
			'Resources can no longer use a slug that clashes with a type or topic page.',
		),
	),
	'0.2.0' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'Default access level per resource type (Lite for articles, cases and FAQs; Pro for videos and downloads).',
			'URLs moved to /knowledge/ so BetterDocs article links keep working.',
			'Tags.',
			'Templates work with both classic and block themes.',
		),
	),
	'0.1.0' => array(
		'date'    => '2026-10-22',
		'changes' => array(
			'First version: Resource post type, types, topics, MemberPress level access, hub listing, FAQ accordion, single pages.',
		),
	),
);
