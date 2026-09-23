=== SiteWatch Connector ===
Contributors: sitewatch
Tags: monitoring, uptime, errors, security, health
Requires at least: 5.2
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.2.0
License: GPLv2 or later

Connects a WordPress site to SiteWatch monitoring: the real cause of fatal errors, a daily health and security report, and a change log.

== Description ==

SiteWatch checks your sites from the outside. This plugin adds the inside view:

* **Fatal errors with their cause**: message, file, line and the plugin or theme responsible, captured without WP_DEBUG and without showing anything to visitors. SiteWatch adds the cause to its "website down" alert.
* **Daily health report**: WordPress, PHP and database versions, pending core/plugin/theme updates, installed plugins and themes, database size, scheduled task health.
* **Security checks**: modified WordPress core files, PHP files in the uploads folder, open registration with a privileged role, the "admin" username, file editing, debug output and more.
* **Change log**: plugins and themes installed, updated, activated or switched, WordPress updates, administrator sign-ins, new administrators, failed sign-in counts and changes to the site address.
* **File watch**: an alert when wp-config.php or .htaccess changes outside WordPress (FTP, hosting panel, malware). Only a fingerprint is kept; the contents never leave the site.

Everything is pushed from this site to your SiteWatch server over HTTPS and signed with a secret unique to this site. The plugin opens no public endpoints.

== Installation ==

1. In SiteWatch, open the website, go to the WordPress section and click **Download plugin**.
2. In WordPress, go to Plugins → Add New → Upload Plugin, choose the zip file and activate it.
3. In SiteWatch, click **Create connection key** and copy it.
4. In WordPress, go to Settings → SiteWatch, paste the key and click **Connect**.

== Changelog ==

= 1.2.0 =
* Watches wp-config.php and .htaccess. Changes made outside WordPress are reported as critical; changes made while WordPress activates or updates plugins or writes its rewrite rules are logged with who did it. Only size, time and a short fingerprint are sent, never the contents.

= 1.1.0 =
* Updates itself from your SiteWatch server (with automatic rollback on WordPress 6.6+).
* Reports whether WordPress is serving pages, so SiteWatch can hold back alerts caused by blocked checks.

= 1.0.0 =
* First release: fatal error capture, health and security snapshot, change log, 5-minute heartbeat.
