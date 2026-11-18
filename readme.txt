=== DashLights ===
Contributors: ckreativedev
Tags: dashboard, domain, ssl, updates, backup
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plain-English status lights on your dashboard: domain and certificate renewal dates, waiting updates, and recent backups.

== Description ==

DashLights puts one simple answer on your WordPress dashboard: is anything about to go wrong with your website?

It checks four things once a day and shows each one as a green, yellow, or red light:

* **Domain renewal.** When your domain name expires. Let it lapse and your website and email stop working.
* **Security certificate.** When the certificate that keeps your site on https expires. Let it lapse and visitors see a security warning.
* **Updates.** How many WordPress, plugin, and theme updates are waiting.
* **Backups.** When your last backup ran, if you use UpdraftPlus.

The light at the top shows the most urgent one. No technical terms, no scores, no settings to learn.

**Reminder emails.** DashLights emails you 30 and 7 days before your domain expires, and 7 days and 1 day before your security certificate expires. You can change who gets them, or turn them off.

**What DashLights doesn't do.** It doesn't scan for malware, test your speed, or watch for downtime. A site can't tell you when it's down, because when it's down, it can't send anything. Other tools do those jobs well.

DashLights is free and made by [CKreative Digital](https://ckreativedigital.com/), a website care company in Stow, Ohio. There's no account to create and nothing to upgrade.

== External services ==

To find your domain's renewal date, DashLights uses RDAP, the public lookup service run by domain registries.

* **IANA RDAP bootstrap file** (`https://data.iana.org/rdap/dns.json`). Downloaded about once a month to find which registry handles your domain ending (.com, .org, and so on). Nothing about your site is sent. IANA is run by ICANN: [terms of service](https://www.icann.org/privacy/tos), [privacy policy](https://www.icann.org/privacy/policy).
* **Your domain registry's RDAP server** (for example, Verisign for .com). Contacted once a day with your domain name only, to read its expiration date. Each registry publishes its own terms; IANA lists every registry and its contact details in the [Root Zone Database](https://www.iana.org/domains/root/db).

To read your security certificate, DashLights connects to your own website over https. No other site is contacted.

DashLights does not track you, collect statistics, or send any information to CKreative Digital.

== Installation ==

1. Install DashLights from Plugins → Add New, or upload the `dashlights` folder to `/wp-content/plugins/`.
2. Activate it.
3. Go to your Dashboard. The first check runs within a minute.
4. Optional: go to Settings → DashLights to change who gets reminder emails.

== Frequently Asked Questions ==

= DashLights says it couldn't find my domain's renewal date. =

Some domain endings, mostly country domains, don't offer a public lookup. Go to Settings → DashLights and enter the date yourself. You'll find it in your account with the company you registered the domain with.

= My domain renews automatically. Will I still get emails? =

Yes. The registry only shows the new date after the renewal goes through, so DashLights can't tell it's set to renew. If you know it is, you can ignore the email or turn reminders off.

= It says it couldn't read my security certificate. =

Some hosts block a website from connecting to itself. Your certificate is probably fine. Your host can confirm when it expires.

= It says it can't see a backup plugin, but my host backs up my site. =

That's common. Backups your host makes happen outside WordPress, where DashLights can't see them. Ask your host how often they back up and how long they keep backups.

= Who can see the dashboard widget? =

Only users who can manage the site (administrators).

= Does it slow down my site? =

No. The checks run once a day in the background, and nothing loads on the pages your visitors see.

== Screenshots ==

1. The DashLights widget on the WordPress dashboard.
2. Settings → DashLights.

== Changelog ==

= 1.0.0 =
* First release.
