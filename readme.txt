=== Stop Logging Me Out ===
Contributors: lev0
Tags: login, sessions, annoyances
Requires at least: 4.0.0
Tested up to: 6.5.2
Stable tag: 1.0.0
Requires PHP: 7.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stops WordPress from forcibly logging you out of a valid session.

== Description ==

WordPress asks you to reauthenticate when your session expires, or for other security reasons. This is great *except* when you've just done so, and WordPress mistakenly believes you need to do it again and again.

This plugin modifies a small portion the login system to prevent login cookies being erased when they don't need to. It may not work in rare instances where that system is already being modified by other third party code in a way that isn't completely compatible with the default WordPress functions.

== Installation ==

Install in the usual manner by searching for this plugin by name, or uploading it, on your **Add Plugins** page.

No configuration is required but it only fixes reauthentication pages generated *after* the plugin is installed, so visiting any that have the old `reauth=1` in the URL will still trigger WordPress' bad behaviour.

== Changelog ==
 
= 1.0.0 =

* Offer to return to a current session from regular login pages as well.
 
= 0.1.1 =

* Fix for compatibility with older WordPress versions.
