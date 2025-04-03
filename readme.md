# File Version Manager

**Contributors:** Riley Sandborg
**Tags:** files, file manager, version control, download manager, wp-filebase
**Requires at least:** 6.2
**Tested up to:** 6.7.2
**Stable tag:** 1.0.0
**License:** GPLv3 or later
**License URI:** https://www.gnu.org/licenses/gpl-3.0.html

Conveniently upload, manage versions, and provide access to files on your WordPress site.

## Description

File Version Manager provides a streamlined interface for managing downloadable files within WordPress. Upload files to a dedicated directory, easily update them while maintaining consistent access links, organize them with categories, and embed them using simple shortcodes.

This plugin aims to be a lightweight alternative for managing file downloads and versions, including features inspired by WP-Filebase Pro.

**Key Features:**

-   **File Management:** Upload, edit details (display name, description), replace files, and delete.
-   **Versioning:** Tracks file versions, with an option to auto-increment versions upon replacement.
-   **Category Management:** Create, edit, delete, and organize files into hierarchical categories.
-   **Dedicated Upload Directory:** Files are stored in `/wp-content/uploads/filebase/` (by default).
-   **Shortcode Support:** Embed single files or lists of files from categories using `[fvm] [...]`.
-   **Direct Download Links:** Files are accessed via clean URLs (e.g., `/?file=your-file.pdf`).
-   **WP-Filebase Pro Migration:** Includes tools to migrate categories and files from WP-Filebase Pro.
-   **Optional File Scanning:** Can automatically scan the upload directory for added/removed files (can be disabled for performance).
-   **Database Upgrades:** Handles necessary database schema updates between versions.

## Installation

1.  Download the plugin `.zip` file.
2.  Navigate to **Plugins > Add New** in your WordPress admin area.
3.  Click **Upload Plugin** and choose the downloaded `.zip` file.
4.  Activate the plugin through the **Plugins** menu in WordPress.
5.  (Optional) Configure plugin settings under the **Files > Settings** menu.

Alternatively, upload the `file-version-manager` folder to the `/wp-content/plugins/` directory via FTP and activate the plugin.

## Usage

### Managing Files

Navigate to the **Files > All Files** menu:

-   **Upload:** Drag & drop files or use the "Select Files" button.
-   **Edit:** Click the "Edit" action link for a file to modify its Display Name, Description, Version (if auto-increment is off), Categories, and Offline status. You can also replace the file here.
-   **Delete:** Use the "Delete" action link or the bulk delete option.

### Managing Categories

Navigate to the **Files > Categories** menu:

-   **Add:** Use the form on the left to add new categories, optionally assigning a parent.
-   **Edit:** Click the "Edit" action link for a category to modify its Name, Description, Parent, and Exclude from Browser status.
-   **Delete:** Use the "Delete" action link or the bulk delete option.

### Shortcodes

Use the `[fvm]` shortcode to display files in your posts or pages:

-   **Single File:** `[fvm tag="file" id="123"]` (replace `123` with the actual File ID)
-   **Category Listing:** `[fvm tag="category" id="45"]` (replace `45` with the actual Category ID)

_(Note: Additional template options (`tpl="..."`) may be available depending on your theme or further development.)_

## Settings

Navigate to **Files > Settings**:

-   **Settings Tab:**
    -   **Auto-Increment Version:** Enable/disable automatic version incrementing when replacing files.
    -   **File Scanning:** Enable/disable automatic scanning of the upload directory for changes.
    -   **Debug Logs:** Enable detailed logging to `wp-content/fvm_*.log` files for troubleshooting.
    -   **Database Upgrade:** (Appears when needed) Run manual database upgrades required by plugin updates.
-   **WP-Filebase Pro Tab:**
    -   **One-Click Migration:** Import categories and files from existing WP-Filebase Pro tables.
    -   **Update Shortcodes:** (Currently unavailable) Tool to convert WP-Filebase shortcodes.

## Developer Notes

-   The plugin uses custom database tables (`fvm_files`, `fvm_categories`, `fvm_relationships`).
-   It includes a basic upgrade mechanism (`FVM_Upgrade`).
-   AJAX is used for operations like fetching data for edit modals.
-   Plugin includes a custom admin UI with toolbar and header.

## Support

For issues, questions, or feature requests, please submit an issue on the [GitHub repository](https://github.com/sandporg/file-version-manager/).
