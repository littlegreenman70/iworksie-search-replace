=== iWorks.ie Search & Replace ===
Contributors: littlegreenman
Tags: search replace, migration, change domain, database, serialized
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search and replace in your database with a free row-by-row preview, a Change domain preset and one-click undo. Nothing locked behind a Pro version.

== Description ==

Most search and replace plugins show you a count and ask you to trust them, then offer no way back. This one shows you every change first and lets you undo any run.

**See every change before it happens (free).** Preview is not optional and not a paid extra: you get a row-by-row list with the table, column and row, and the text before and after with the match highlighted. Nothing is written until you click Apply.

**Undo any run with one click.** Before a value is changed, its original is saved. The History & undo tab lists your recent runs with an Undo button. If a value was edited again after your run, Undo leaves it alone so newer work is never overwritten.

**Change domain, done properly.** Moving a site or switching to https? Enter the old and new address and it finds every form the old one is stored in:

* http://, https:// and protocol-relative //old-site.com links
* JSON-escaped links (https:\/\/old-site.com) used by blocks and page builders
* URL-encoded links (https%3A%2F%2Fold-site.com)
* the www and non-www versions
* look-alikes such as old-site.com.au are left alone

**Safe with serialized data.** Widget settings, theme options and many plugin settings are stored as serialized PHP. Changing the text inside them without fixing the stored lengths breaks them. This plugin rewrites the lengths correctly, including serialized data nested inside serialized data, and it never unserializes your data into PHP objects, so no plugin code is triggered while it works. If a value can't be handled safely, it is left unchanged and the preview tells you.

**Guard rails.**

* Case sensitivity explained in plain words, not a cryptic checkbox
* A warning before very short searches that would match inside other words
* WordPress tables ticked by default; pick any others you need
* Passwords, post GUIDs (unless you ask) and the plugin's own data are never changed
* Regular expressions are available for developers, off by default
* Large tables are processed in small batches, so it won't time out; an interrupted run can be resumed

Privacy: this plugin makes no external requests and collects no data.

Need a custom plugin? iWorks builds them: https://www.iworks.ie

== Installation ==

1. Upload the `iworksie-search-replace` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New.
2. Activate the plugin through the Plugins screen.
3. Open **iWorks Tools → Search & Replace**.

== Frequently Asked Questions ==

= Can it damage my site? =

Any tool that edits the database can, which is why this one makes you preview first and saves the original of every value it changes. You can undo a run from History & undo. For big changes on a live site, take a full database backup too.

= Is the preview really free? =

Yes. There is no Pro version. Preview, undo, Change domain and regular expressions are all included.

= How long is undo data kept? =

The last 5 runs keep their undo data; older runs are removed automatically. You can delete a run's undo data yourself at any time. Developers can change the number with the `iwiesr_keep_runs` filter.

= Why does it say some values were left alone? =

A few plugins store data in a serialized format that can't be edited safely as text. Rather than risk breaking it, the plugin skips those values and tells you how many there were.

= Who can use it? =

Administrators on a single site. On multisite, only super admins, because user tables are shared by every site in the network.

= Does it send data anywhere? =

No. It makes no external HTTP requests and collects no analytics.

== Screenshots ==

1. Search & replace with plain-English options.
2. Row-by-row preview with before and after.
3. Change domain preset.
4. History & undo.

== Changelog ==

= 1.0.0 =
* Initial release: free row-by-row preview, one-click undo, Change domain preset, serialized-safe editing, batched and resumable runs.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
