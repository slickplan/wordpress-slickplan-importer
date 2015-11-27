=== Plugin Name ===
Contributors: slickplan
Donate link: http://slickplan.com/
Tags: slickplan, import, xml
Requires at least: 3.0
Tested up to: 4.4
Stable tag: trunk
License: GPL-3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

The Slickplan import plugin allows you to quickly import your Slickplan projects into your WordPress site.

When you are finished planning your website project, import your Slickplan website plan. Upon import, your pages, navigation structure, and content will be instantly ready in your CMS.

== Installation ==

1. Upload the `slickplan-importer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the 'Tools' -> 'Import' screen, Click on 'Slickplan'

== Changelog ==

= 2.0 =
* New: Import is now a 2 steps process, import options have been moved to Step 2
* New: Added content gathering support - plugin can import page content, SEO meta and download files
* Fix: Dropped a PHP's libxml extension requirement, plugin now requires DOMElement which is default PHP5 extension

= 1.2 =
* New: Option to set menu order number when importing

= 1.1 =
* Fix: Support for PHP 5.2

= 1.0.1 =
* New: WordPress 4.x compatibility

= 1.0 =
* New: Options to manipulate page titles prior to import
* Fix: updated styling to suit WordPress 3.8

= 0.4 =
* New: Option to ignore pages marked as 'External' page type

= 0.3 =
* New: Import sections

= 0.2 =
* New: Import notes as pages contents
* New: Import footer section items

= 0.1 =
* Initial release