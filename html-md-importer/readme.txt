=== HTML & Markdown Importer ===
Tags: import, html, markdown, pages, media
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import folders of HTML or Markdown files as pages or posts – including images, links and folder hierarchy.

== Description ==

Tools → HTML & Markdown Import

1. Choose a local folder (or a ZIP file). Files are uploaded one by one in small chunks, so server upload limits do not matter.
2. Check the plan: one row per page/post, with editable titles, image counts, duplicates and missing images.
3. Import. Images go to the Media Library (deduplicated), links are rewritten, and the content is converted to native blocks.

= How folders are mapped =

* Every folder that contains documents becomes a page; sub-folders become child pages (sort order from the folder names, e.g. "01 …", "02 …").
* Within a folder, `index.*`, then `content.*`, then `preview.*` is used. Files with identical content (e.g. content.html and preview.html) are imported once.
* Additional, different documents in a folder become child pages.
* Folders without documents (e.g. `images`) are not pages.
* When importing as posts, folders become (nested) categories.

= Content =

* All styles, classes and scripts are removed – the theme controls the design.
* HTML is converted to core blocks: paragraph, heading (with anchors), list, quote, image (with caption), table, code, separator. Anything else becomes a Custom HTML block and is reported.
* The first H1 becomes the title (optional).
* Links between imported documents point to the new pages, including #anchors.

= Markdown front matter =

    ---
    title: My page
    slug: my-page
    status: draft        # draft, pending, private, publish
    type: page           # page or post
    order: 3
    excerpt: Short text
    date: 2026-01-31
    categories: [News, Docs]
    tags: [a, b]
    ---

= Re-import =

Every page remembers its source path. Importing the same folder again updates the existing pages (same ID and URL, new revision) instead of creating duplicates.

== Changelog ==

= 0.1.0 =
* First version.
