=== DH Contact ===
Author: Deepak Hasija
Version: 1.0.0
Requires at least: 5.8
Requires PHP: 7.4

== Description ==
Contact form handler for deepakhasija.com.
Stores all submissions in the database, sends email notifications,
and provides a full admin interface to manage entries.

== Features ==
* Stores all form submissions in a custom database table
* Sends email notification to your chosen address on every submission
* Sends an automatic reply to the visitor
* Admin entries page — view, filter, mark as read, delete, export CSV
* Settings page — notification email, CC, success message, redirect URL
* Honeypot spam protection (no CAPTCHA needed)
* Admin bar unread count indicator
* No third-party dependencies — pure WordPress

== Installation ==
1. Upload the dh-contact/ folder to /wp-content/plugins/
2. Activate via Plugins > Installed Plugins
3. Go to DH Contact > Settings and set your notification email
4. The form on your contact page will now work immediately

== The Form ==
The contact page form uses these field names (already built into your Divi page):
  name, email, company, service, budget, timeline, message, source

The plugin listens for clicks on any element with class "dh-submit"
and submits all named fields via AJAX.

== Settings ==
Navigate to: Dashboard > DH Contact > Settings

  Notification Email  — where new submission alerts are sent
  CC Email            — optional second recipient
  Success Message     — shown inline after submission
  Redirect URL        — optional redirect instead of inline message
                        (set to /thank-you/ to use your Thank You page)
  Honeypot            — recommended on (catches bots silently)
  Store Entries       — recommended on (saves all submissions to DB)

== Admin Bar ==
When there are unread submissions, a "📬 N new enquiries" indicator
appears in the WordPress admin bar for quick access.

== Changelog ==
= 1.0.0 =
* Initial release
