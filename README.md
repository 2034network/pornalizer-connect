# Pornalizer Connect

[![Latest Release](https://img.shields.io/github/v/release/2034network/pornalizer-connect)](https://github.com/2034network/pornalizer-connect/releases/latest)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net)

A WordPress plugin that embeds and syncs video content from [Pornalizer](https://pornalizer.app) into your site — a live grid, lightbox player, AJAX search, and a WP-Cron job that pulls in new content automatically.

## Features

- **Live video grid** — drop-in shortcode, no manual embedding
- **Lightbox player** — clean in-page playback, no redirect to a third-party page
- **AJAX search** — instant client-side filtering across your synced catalog
- **Auto-sync** — WP-Cron job keeps your site's catalog current, with an optional drip mode for gradual publishing
- **Signed embed links** — every link is HMAC-SHA256 signed, expires after 2 hours, and is bound to the referring domain, preventing hotlinking and link-sharing abuse

## Installation

1. Download `pornalizer-connect.zip` from the [latest release](https://github.com/2034network/pornalizer-connect/releases/latest).
2. In your WordPress admin: **Plugins → Add New → Upload Plugin**, select the zip, click **Install Now**, then **Activate**.
3. Configure your connection under **Settings → Pornalizer Connect**.

## Requirements

| | |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |

## Security

All embed links are signed with HMAC-SHA256 tokens that expire after 2 hours and are bound to the referring domain — this keeps playback seamless for real visitors while preventing hotlinking and share-link abuse.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[GPL-2.0-or-later](LICENSE)
