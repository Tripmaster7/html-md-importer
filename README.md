# HTML & Markdown Importer (WordPress plugin)

Imports local folders of HTML or Markdown files as WordPress pages or posts. Images go to the Media Library, links are rewritten, the folder structure becomes the page hierarchy, and the content is converted to native blocks so the theme controls the design.

User documentation: [html-md-importer/readme.txt](html-md-importer/readme.txt)

## Layout

```
html-md-importer/            the plugin (this folder is what gets zipped)
  html-md-importer.php       bootstrap, autoloader, hooks
  includes/
    class-paths.php          path validation, file-type rules
    class-session.php        temp storage per import (chunked upload, ZIP extraction, manifest)
    class-document.php       loads HTML / Markdown (Parsedown) into a DOM
    class-front-matter.php   YAML front matter subset
    class-cleaner.php        strips styles/classes/scripts, normalizes markup
    class-scanner.php        folder tree → import plan (hierarchy, duplicates, media)
    class-block-converter.php  cleaned HTML → core blocks
    class-media.php          Media Library import, dedupe by MD5
    class-importer.php       media / item / finalize (link rewriting)
    class-rest.php           REST API html-md-importer/v1
    class-admin.php          Tools → HTML & Markdown Import
  assets/admin.js|css        admin screen (vanilla JS, no build step)
  lib/parsedown/             Parsedown 1.7.4 (MIT), class renamed to HMI_Parsedown
tests/
  offline.php                plan + block output without WordPress
  integration-stubbed.php    full import flow against in-memory WP stubs
  fixtures/md-site/          Markdown test site
bin/build-zip.php            builds build/html-md-importer.zip
```

## Import flow

1. `POST /sessions` → session in `uploads/hmi-sessions/<id>/` (denied by .htaccess; every file stored with `.hmi` suffix).
2. `POST /sessions/<id>/upload` per file chunk (or one chunked ZIP + `/extract`).
3. `POST /sessions/<id>/scan` → plan (items in parent-first order, media list).
4. `POST /sessions/<id>/media` per image (one request each, avoids PHP timeouts).
5. `POST /sessions/<id>/item` per page/post, parents first.
6. `POST /sessions/<id>/finalize` → links between imported documents rewritten, uploaded files deleted.

## Development

```sh
php tests/offline.php sample/Wordpress_2026_2.zip MATERIAL      # plan + blocks of one item
php tests/integration-stubbed.php sample/Wordpress_2026_2.zip page
php tests/integration-stubbed.php tests/fixtures/md-site post
php bin/build-zip.php
```

`sample/` is git-ignored (local test data).
