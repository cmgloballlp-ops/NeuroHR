# NeuroHR App — v0.1.1

The NeuroHR member area as a phone app. Phones only (768px and narrower); computers keep the NeuroHR Members sidebar.
Needs **NeuroHR Members 0.6** or newer; works with **NeuroHR Knowledge Hub 0.14** or newer.

## What it does

- **Bottom bar**: a floating capsule with Home · Hub · Search · Templates · More; the open tab is a dark pill with an orange icon.
- **Search**: full-screen, results while typing (Knowledge Hub live search), recent searches.
- **More sheet**: profile, shortcuts (Appearance → Menus → location *Phone: More shortcuts*, each item with an icon; default: HR News, Legal Case Studies, Video Learning, Saved, HR FAQs, Payroll), My Account, Plan & Billing, My Company, Notifications, Install the app, Log Out.
- **App home** on the Dashboard page: date, greeting (`[nhm_greeting]` texts from Members), swipeable plan card with days left, today's line, Renew / Upgrade card, *Latest for You*.
- **Hub pages**: small header with a back button (resources: Save + Share), section chips and the newest item on section homes, two-column topic cards, timeline lists, template cards, sticky template bar.
- **Never wider than the phone**: long links wrap, pictures and videos shrink, and **tables scroll sideways on their own** (the whole table moves, "Swipe the table to see more").
- **Speed**: every tap is answered at once (tab turns dark, a bar runs along the top, grey placeholders). Chrome/Android start loading a page when the finger touches its link, and load the bottom-bar pages in the background. Never loaded early: log out, downloads, videos, admin, addresses with `?` (forms, payment links). Loading early runs no JavaScript, so it does not count as a view or download. The back button uses the browser's back (instant when the page is still in memory). Page changes fade (Chrome, Safari 18+).
- **Install as an app (PWA)**: `/nhm-manifest.webmanifest`, `/nhm-sw.js`, `/nhm-offline/` (same addresses as Members 0.5, so installed apps keep working). Pages are never stored on the phone.

## Settings

Members → **Phone App**: phone layout, app home, the three speed switches, app name / icon / colours. Settings from Members 0.5 are taken over on first use.

## Install / update

1. Update **NeuroHR Members to 0.6.0** and **Knowledge Hub to 0.14.0**.
2. Install and activate **NeuroHR App 0.1.0** (Plugins → Add New → Upload).
3. Open the site on a phone. If you use a caching plugin, exclude `/nhm-sw.js` and `/nhm-manifest.webmanifest`.

Turning NeuroHR App off: phones get the normal Members layout (sidebar drawer) again; nothing is lost.

## Test checklist (phone)

- [ ] Bottom bar floats; the open tab is dark; tapping a tab turns it dark at once and a bar runs along the top.
- [ ] An article with a table: the page does not move sideways; only the table scrolls, all columns together.
- [ ] Hub home: small title, search, section chips, newest item, topic cards in two columns.
- [ ] Topic page and article: back button works; Save and Share on articles.
- [ ] More sheet, search screen, My Account list, Plan & Billing look as before.
- [ ] Android Chrome: open a page, wait a second, tap Hub: it opens almost at once.
