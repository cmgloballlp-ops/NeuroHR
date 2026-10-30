# NeuroHR WordPress plugins

Plugins for [neurohr.com.my](https://www.neurohr.com.my) (WordPress, Blocksy, Elementor).

| Folder | Plugin | Version | What it does |
|---|---|---|---|
| `neurohr-members/` | NeuroHR Members | 0.4.4 | Membership levels, personal and company plans, login / registration / My account, member-area layout, invoices (PDF), payments (Stripe card / FPX / GrabPay, bank transfer, DuitNow QR), card auto-renew, refunds with Refund Notes. Replaces MemberPress. |
| `neurohr-hub/` | NeuroHR Knowledge Hub | 0.8.2 | Knowledge Hub, Legal Case Insights, FAQ, Templates (protected downloads) and Videos, with level-based access from NeuroHR Members. |
| `neurohr-payroll/` | Simple Payroll | 0.5.0 | Malaysian payslip calculator; payslip extras for higher levels. |

Documentation: [`docs/`](docs/) (Members readme and test checklist, Knowledge Hub readme, plan and decisions).
Version history of each plugin: `includes/changelog.php` (also shown in the plugin's Settings → Changelog).

## Install or update on WordPress

1. Zip the plugin folder itself, e.g. `neurohr-members/` → `neurohr-members.zip` (the zip must contain the folder, not only its files). Or download the zip of a release tag.
2. WordPress → Plugins → Add New → Upload Plugin → choose the zip → *Replace current with uploaded*.
3. Data and settings stay; database changes run by themselves.

## Releases

Changes come in as pull requests, so each change can be reviewed before it is merged. The version of each plugin is in the table above and in every commit message.

To mark a version in GitHub (optional): **Releases → Create a new release → Choose a tag**, type e.g. `members-v0.4.4`, pick *Create new tag on publish*, target `main`, title `NeuroHR Members 0.4.4`, paste the changelog lines, **Publish release**. You can attach the plugin zip to the release so it is easy to download later.

## Tests

`tests/` holds the checks run before every release: a small WordPress stand-in (SQLite) and scripts per version. Run them with PHP 8:
`php tests/test-v7.php .` (the argument is the folder that contains `neurohr-members/`). `python3 tests/xref.py neurohr-members` checks that every `Class::method` used exists.

## Not in this repository

Passwords, Stripe keys, webhook secrets and the SMTP password live only in each site's settings. Never commit them.
