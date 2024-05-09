=== BMLT2Calendar ===  

Contributors: otrok7, bmltenabled
Tags: na, meeting list, bmlt
Requires PHP: 8.0
Requires at least: 6.2
Tested up to: 6.5.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


== Description ==

This plugin gets information about Narcotics Anonymous Meetings from a BMLT root server and converts it into a format that can be displayed in a calendar.
The calendar information can be downloaded by the user and imported into an Outlook, Google or Apple calendar, or it my be fed to another system and displayed
on a webpage.

Currently 2 formats are supported, the ICal format defined in RFC 5545 and the JSon format supported by the popular Fullcalendar.io framework.
These are implemented as two separate feeds, normally "/feed/bmlt2ics" and "/feed/bmlt2Json" respectively.

Arguments can be included in the query string sent to the feed.  The ICal feed accepts "meeting-id" as an argument.  In this case, the next occurance of the
meeting with the specified ID will be downloaded.

As described above, the JSON feed is typically used to provide data to the fullcalendar.io logic, and so the query string is expected to conform to the 
fullcalendar.io login.  Namely, the string is expected to contain start and end dates in the form YYYY-MM-DD.
Start and end dates can also be provided to the ICS feed.  In this case, however, the dates should be in the ICal (RFC 5545) format.

== Installation ==

1. Place the 'bmlt2Calendar' folder in your '/wp-content/plugins/' directory.

2. Activate the plugin "BMLT 2 Calendar".

3. Visit the administration page "Settings->Permalinks".


== Changelog ==
= 1.0.0 =
* Initial wordpress release.