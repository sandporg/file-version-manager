=== File Version Manager ===
Contributors: @rsandb, @codelyfe
Tags: version, file, manager, version manager
Requires at least: 6.6
Tested up to: 6.7.1
Stable tag: trunk
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

File Version Manager is a slimmed version of WP Filebase Pro. File Version Manager allows you to manage your file versions without needing to replace every instance of the file on your site.

== Description ==

File Version Manager is a slimmed version of WP Filebase Pro. File Version Manager allows you to manage your file versions without needing to replace every instance of the file on your site.

== Frequently Asked Questions ==

= Do you have a question? =

If you have a question or concern, please submit an issue on the GitHub repository.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.(png|jpg|jpeg|gif). Screenshots are stored in the /assets directory.
2. This is the second screen shot

== Changelog ==

= 0.14.2 =
* Added searching by id for files

= 0.14.1 =
* Added filter support for the file item render

= 0.14.0 =
* Updated Plugin Update Checker to 5.5
* Added Simple History logging support for category adds, deletions, and updates

= 0.13.5 =
* Added more icons for file formats

= 0.13.4 =
* Updated file manager class

= 0.13.3 =
* Added a new option to disable file scanning

= 0.13.2 =
* Updated table name variables to use esc_sql()

= 0.13.1 =
* Update the appearance of the shortcode toggle template
* Remove the file version number from the file table

= 0.13.0 =
* List categories hierarchically in the file edit modal

= 0.12.4 =
* Fix child categories not appearing in the search results on the category admin page

= 0.12.3 =
* Fix a bug where the inital render of the toggle shortcode was showing toggle content

= 0.12.2 =
* Added Simple History logging support for file uploads, deletions, and updates

= 0.12.1 =
* Replace the file download URL with a query arg temporarily to fix the file download issue on nginx servers

= 0.12.0 =
* Refactor most of the codebase to follow Wordpress Coding Standards

= 0.11.8 =
* Add support for showing description, version, size, and categories in the file table template

= 0.11.7 =
* Fix a bug where the file upload wasn't working

= 0.11.6 =
* Add database upgrade functionality

= 0.11.5 =
* Add better nginx detection

= 0.11.4 =
* Add support for multiple file uploads

= 0.11.3 =
* Update the edit modal UI for categories. It now uses a single template and fetches the file data via AJAX.

= 0.11.2 =
* Update the edit modal UI. It now uses a single template and fetches the file data via AJAX.

= 0.11.1 =
* Visual changes

= 0.11.0 =
* Updated the migration method to include file and category exclusion from the file browser
* Updated toggle template to use the exclusion settings

= 0.10.2 =
* Fixed toggle template not displaying if there were no files in the parent category
* Fix a memory issue if the id was set to 0 in the toggle shortcode

= 0.10.1 =
* Added a new template: Toggle

= 0.10.0 =
* Added a new table to handle file-category relationships
* Updated edit file UI to reflect changes

= 0.9.14 =
* Fixed an issue where a migration would cause an error if there were more than 3 files with the same name.

= 0.9.7 =
* Added prepared statements to all database queries to prevent SQL injection