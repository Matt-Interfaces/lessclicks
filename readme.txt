=== Lessclicks Analytics for Matomo - Dashboard Widget ===
Contributors: matt-interfaces, choooomedia
Donate link: https://matt-interfaces.ch/zahlen
Tags: analytics, dashboard, matomo, statistics, widget
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 0.9.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Matomo Analytics in a WordPress dashboard widget: visitors, page views, bounce rate, trends. Works with Matomo for WordPress, self-hosted and Cloud.

== Description ==

Lessclicks Analytics for Matomo shows your Matomo Analytics data in a dashboard widget inside the WordPress admin. It connects to Matomo for WordPress, a self-hosted Matomo installation or Matomo Cloud. You need no additional account.

= What the widget shows =

* Unique visitors
* Page views
* Bounce rate
* Average session duration
* Visitor trends over time
* Operating system families as a pie chart

= What you can set up =

* Line, bar and pie charts
* Any date range, chosen with a date picker
* An accent color that matches your brand
* PDF and PNG export of the current view

= Three ways to connect Matomo =

* **Matomo for WordPress:** Matomo runs inside your WordPress site as a plugin. Lessclicks detects it and connects when you click "Connect automatically".
* **Self-hosted Matomo:** You run Matomo on your own server. Enter the Matomo URL, the Site ID and an API token.
* **Matomo Cloud:** Matomo hosts the installation for you. Enter your cloud URL, for example https://your-company.matomo.cloud, and an API token.

= How Lessclicks handles data =

Lessclicks adds no tracking code to your website and collects no visitor data. It requests reports from your Matomo installation through the Matomo Reporting API. The requests run on your server.

The plugin stores the Matomo URL, the Site ID, your display settings and the API token (encrypted) in WordPress options. Whether your measurement meets GDPR (DSGVO) requirements depends on your Matomo configuration. See https://matomo.org/privacy/ for details.

= Access and security =

The widget appears for administrators only. All REST endpoints use WordPress nonces and require the `manage_options` capability.

= Requirements =

* WordPress 5.8 or later
* PHP 7.4 or later
* A Matomo installation (Matomo for WordPress, self-hosted or Matomo Cloud) and an API token
* One website per WordPress installation

= Open source =

Lessclicks is open source software, licensed under GPLv2 or later. The source code is on GitHub: https://github.com/Matt-Interfaces/lessclicks. Pull requests are welcome.

Developed by Matt Interfaces. Plugin page: https://matt-interfaces.ch/lessclicks

== Installation ==

1. In WordPress, go to **Plugins > Add New**, search for "Lessclicks Analytics for Matomo", then click **Install Now** and **Activate**. To install manually, use **Plugins > Add New > Upload Plugin** with the plugin ZIP.
2. Open **Lessclicks** in the admin menu.
3. If Matomo for WordPress is installed, click "Connect automatically". Otherwise enter your Matomo URL, Site ID and API token.
4. Click "Test connection", then save the settings.
5. Open the WordPress Dashboard to see the widget.

== Frequently Asked Questions ==

= Does Lessclicks work without a Matomo account? =

No. Lessclicks displays data from an existing Matomo installation, so you need Matomo for WordPress, a self-hosted Matomo or Matomo Cloud. Lessclicks itself needs no separate account.

= Where do I find my Matomo API token? =

Open your Matomo dashboard and go to Settings > Personal > Security. Under "Auth Token" you can copy your token or create a new one.

= Does the plugin work with Matomo Cloud? =

Yes. Enter your Matomo Cloud URL, for example https://your-company.matomo.cloud, together with your API token and Site ID.

= Can I show more than one website? =

Not yet. Lessclicks supports one website per WordPress installation. You can change the Site ID in the settings at any time.

= Does Lessclicks add tracking code to my site? =

No. Lessclicks only reads reports from your Matomo installation and displays them in the admin. It adds no frontend tracking code and stores no visitor data.

= Who can see the widget? =

Administrators only. The widget is registered for administrators, and the REST endpoints require the `manage_options` capability.

== Screenshots ==

1. Dashboard widget with a line chart of Matomo visits.
2. Dashboard widget with a bar chart.
3. Operating system families from Matomo shown as a pie chart.
4. Lessclicks settings page in the WordPress admin.
5. Dashboard widget with a pie chart and the report export menu for PDF and PNG.

== Changelog ==

= 0.9.3 =
* Settings page translated into English and German; English is the source language.
* Removed the duplicate connection status below the header. It now appears only in the header badge, at the auth token field and in the Chart Settings card.
* Settings labels aligned with the admin design: Chart Settings, Site ID, Auth token, Chart-Type, Main color, Initial Date filter, Live preview, Reset, Save.
* Added a "Use Matomo for WordPress" checkbox so manual Matomo configuration stays available when the Matomo plugin is installed.
* Fixed the help tab, which referenced a non-existent connection tab and test button.

= 0.9.2 =
* Added screenshots to the plugin directory listing.
* Excluded directory icon files from the plugin package.

= 0.9.1 =
* Updated the readme title, short description, tags, description, installation steps and FAQ.
* Added the Lessclicks plugin icon to the WordPress.org directory assets.
* Removed the developer README, the Open Graph marketing image and the unused share icon from the plugin ZIP.
* Removed the Plugin Update Checker from the distribution package.

= 0.9.0 =
* Renamed the plugin to Lessclicks Analytics for Matomo.
* Analytics and device REST endpoints now require the `manage_options` capability; the dashboard widget is only registered for administrators.
* Removed WordPress.org directory assets from the plugin ZIP.
* Removed the remote CDN reference from the bundled PDF export script.
* Added the missing changelog entries for 0.8.4 to 0.8.8.

= 0.8.8 =
* Asset cache busting now uses the plugin version instead of file modification times.

= 0.8.7 =
* Maintenance release for the update flow.

= 0.8.6 =
* Aligned the plugin version constant with the plugin header.

= 0.8.5 =
* Added WordPress.org plugin screenshots.

= 0.8.4 =
* Bundled the missing Parsedown and PucReadmeParser files.
* Added an automated GitHub release workflow.

= 0.8.3 =
* Migrated repository references to Matt-Interfaces organization.
* All documentation translated to English.
* Added project rules under `.clinerules/lessclicks.md`.

= 0.8.2 =
* Integrated WordPress-native updates via GitHub Releases until the WordPress.org directory is active.

= 0.8.1 =
* Corrected donate link to https://matt-interfaces.ch/zahlen.

= 0.8.0 =
* PDF/PNG export now respects the user-defined accent color instead of fixed colors
* Report badge in export is now twice as wide for better readability
* Version number in settings header is now pulled dynamically from plugin metadata
* Translation template (.pot) included in distribution package
* Readme updated for WordPress 6.7
* Preparation for WordPress.org submission (PHPCS, CI, i18n)

= 0.3.0 =
* Complete redesign of the user interface
* New modern settings page with WordPress admin UI
* Automatic detection of Matomo for WordPress
* Improved security with nonce verification
* REST API with proper permission checks
* 4 different chart types
* Responsive dashboard widget
* Statistic cards with key metrics
* Internationalization prepared
* WordPress Coding Standards compliant

== Upgrade Notice ==

= 0.8.0 =
Updates export styling to the selected accent color. Please review your settings after updating.

== Privacy Policy ==

Lessclicks itself does not collect user data. The plugin only displays statistics from your Matomo installation.

Stored data:
* Matomo URL (in WordPress options)
* Site ID (in WordPress options)
* API token (encrypted in WordPress options)
* Display settings (in WordPress options)

For privacy information about Matomo visit: https://matomo.org/privacy/

== Additional Info ==

= Developer =

* Plugin page: https://matt-interfaces.ch/lessclicks
* GitHub: https://github.com/Matt-Interfaces/lessclicks
* Website: https://www.matt-interfaces.ch
* Support: hoi@matt-interfaces.ch

= Contribute =

Pull requests are welcome! Visit our GitHub repository.

= Support =

If you like the plugin, I appreciate:
* A positive rating on WordPress.org
* A coffee: https://matt-interfaces.ch/zahlen
