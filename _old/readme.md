# WP File Version Manager

**Stable tag:** trunk

**License:** GPLv3 or later

File Version Manager is a WordPress plugin that allows you to conveniently manage file versions. It uploads files to a custom directory and allows for easy updating without having to change links everywhere.

This plugin is meant as a lite replacement for WP-Filebase Pro.

## Description

This plugin provides an easy way to manage file versions across your WordPress site. It offers features such as:

-   Custom upload directory management
-   File versioning
-   Category management for files
-   Shortcode support for easy file embedding
-   WP-Filebase Pro migration support

## Installation

1. Upload the `file-version-manager` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin settings under the 'File Version Manager' menu in the WordPress admin panel.

## Usage

### Managing Files

To manage files:

1. Go to the 'Files' page under the 'File Version Manager' menu.
2. Upload new files or manage existing ones.
3. Edit file details, replace files, or delete them as needed.

### Categories

> **TODO:** Add category editing and link count value to a query on the file page to show all files within that category

Organize your files into categories:

1. Navigate to the 'Categories' page under the 'File Version Manager' menu.
2. Create, edit, or delete categories as needed.

### Shortcodes

Use shortcodes to embed files in your posts or pages:

-   For a single file: `[fvm tag="file" id="1"]`
-   For a category of files: `[fvm tag="category" id="1"]`

#### Shortcode options:

-   `tag`: Use "file" for a single file or "category" for a list of files.
-   `id`: The ID of the file or category.
-   `tpl`: Template option (e.g., "urlonly", "table", "thumbnail-grid-btns").

## Settings

Configure plugin settings:

1. Go to the 'Settings' page under the 'File Version Manager' menu.
2. Set your custom upload directory.
3. Enable or disable auto-increment versioning.
4. Configure other options as needed.

## WP-Filebase Pro Migration

If you're migrating from WP-Filebase Pro:

1. Go to the 'WP-Filebase Pro' tab in the settings.
2. Follow the instructions to migrate your files and categories.

## Developer Notes

-   The plugin uses custom database tables for file and category management.
-   Debug logs can be enabled in the settings for troubleshooting.

## Support

For support, please submit an issue on this GitHub repository.
