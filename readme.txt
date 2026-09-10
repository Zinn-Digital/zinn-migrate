=== Zinn® Migrate ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-migrate
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: migration, export, backup, transfer, move
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Package the WordPress site you are leaving — files and database — into one archive, and hand Zinn Digital® a single link to pull it across.

== Description ==

**Read this first: you probably do not need this plugin.**

If your current host gives you FTP, SSH, cPanel, Plesk or DirectAdmin, put those details into the migration form on Zinn® instead. We connect to your host directly, copy the site across, and install nothing on your server at all — no migration plugin, no backup plugin, nothing. That is faster, it leaves no trace behind, and it is what nearly every migration uses.

This plugin is for the sites that cannot do that.

Some hosts hand you a WordPress login and nothing else: no FTP account, no shell, a control panel with its API switched off, or every port firewalled. If Zinn®'s checker tells you it could not reach your host on any of the usual ways in, this is the way through.

= What it does =

1. Install it on the site you are **leaving**.
2. Open **Tools → Move to Zinn®** and press **Build the package**.
3. It writes your files and a copy of your database into one archive, a few hundred files at a time, so it works on hosts that cut long requests off.
4. When it finishes you get a private link. Paste that into your migration on Zinn®, choosing *An archive of an existing site* as the source.

= Built for slow, small, locked-down hosting =

The hosts that need this plugin are the ones with a thirty-second execution limit, 128 MB of memory, `exec` disabled and no `mysqldump`. So the export never depends on a single long request: every step is bounded and resumable, the database is read through WordPress itself rather than through a command-line tool, and rows are streamed to disk instead of being held in memory.

It also checks how much free disk space there is **before** it writes anything, and says so plainly when the server will not tell it. A server that runs out of space part way through an export does not fail politely — it takes the site down.

= Treat the link like a password =

The link is a long random address inside your own uploads folder, and the folder cannot be listed. But anyone who has the link can download your whole site, database included. Do not paste it anywhere public.

The plugin deletes the package automatically 24 hours after it was built, and there is a **Delete the package now** button on the same screen. Use it as soon as your migration is done, then remove the plugin.

= What it deliberately cannot do =

* **It cannot restore.** It only ever writes an archive; it never puts one back.
* **It cannot package a broken site.** This is PHP running inside the site it is copying, so the site has to load. A site that is down needs SSH or our managed migration service.
* **It opens no route.** Nothing here registers a REST endpoint or accepts an inbound request. Zinn® cannot ring your site — you press a button, and you carry the link to us.

== Installation ==

1. Install and activate the plugin on the site you are moving **away from**.
2. Go to **Tools → Move to Zinn®**.
3. Check the disk-space line, then press **Build the package**.
4. Copy the link it gives you and paste it into your Zinn® migration.
5. Press **Delete the package now** when the migration has finished, then delete the plugin.

== Frequently Asked Questions ==

= Do I need a Zinn® account to use this? =

To migrate, yes — the link is only useful to a migration on Zinn®. The plugin itself needs no account, no API key and no pairing, which is why it works on a site that has never heard of us.

= Where is the package stored? =

Inside your own `wp-content/uploads` folder, in a directory with 32 random characters in its name, containing files that stop it being listed. It never leaves your server until you share the link.

= Does it send anything to Zinn® on its own? =

No. It writes a file and shows you a URL. Nothing is transmitted anywhere by the plugin.

= My host has no ZipArchive. What now? =

The plugin will tell you so instead of failing part way. In that case use the migration form on Zinn® with FTP or SSH details, or ask us about a managed migration.

= Will this work on a very large site? =

It builds in small steps, so size is a question of time and disk space rather than of timeouts. If the site is larger than the free space on the server, the screen tells you before you start.

== Changelog ==

= 1.0.0 =
* First release. Bounded, resumable packaging of files and database; disk-headroom check before anything is written; automatic deletion after 24 hours.
