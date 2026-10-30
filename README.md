# NeuroHR WordPress plugins

Plugins for [neurohr.com.my](https://www.neurohr.com.my) (WordPress, Blocksy, Elementor).

| Folder | Plugin | Version | What it does |
|---|---|---|---|
| `neurohr-members/` | NeuroHR Members | 0.4.3 | Membership levels, personal and company plans, login / registration / My account, member-area layout, invoices (PDF), payments (Stripe card / FPX / GrabPay, bank transfer, DuitNow QR), card auto-renew, refunds with Refund Notes. Replaces MemberPress. |
| `neurohr-hub/` | NeuroHR Knowledge Hub | 0.8.2 | Knowledge Hub, Legal Case Insights, FAQ, Templates (protected downloads) and Videos, with level-based access from NeuroHR Members. |
| `neurohr-payroll/` | Simple Payroll | 0.5.0 | Malaysian payslip calculator; payslip extras for higher levels. |

Documentation: [`docs/`](docs/) (Members readme and test checklist, Knowledge Hub readme, plan and decisions).
Version history of each plugin: `includes/changelog.php` (also shown in the plugin's Settings → Changelog).

## Install or update on WordPress

1. Zip the plugin folder itself, e.g. `neurohr-members/` → `neurohr-members.zip` (the zip must contain the folder, not only its files). Or download the zip of a release tag.
2. WordPress → Plugins → Add New → Upload Plugin → choose the zip → *Replace current with uploaded*.
3. Data and settings stay; database changes run by themselves.

## Releases

Tags per plugin: `members-v0.4.3`, `hub-v0.8.2`, `payroll-v0.5.0`. Changes come in as pull requests, so each change can be reviewed before it is merged.

## Not in this repository

Passwords, Stripe keys, webhook secrets and the SMTP password live only in each site's settings. Never commit them.
