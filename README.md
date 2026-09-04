# Pornalizer Connect

WordPress plugin: embed and sync videos from [Pornalizer](https://pornalizer.app) — live grid, lightbox player, AJAX search, and a WP-Cron sync job that pulls new videos automatically.

## Installation

1. Download the latest `pornalizer-connect.zip` from [Releases](../../releases).
2. In your WordPress admin: **Plugins → Add New → Upload Plugin**, select the zip, click **Install Now**, then **Activate**.
3. Configure your Pornalizer connection under **Settings → Pornalizer Connect**.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Security

Embed links are signed with HMAC-SHA256 tokens that expire after 2 hours and are bound to the referring domain, preventing hotlinking and share-link abuse.

## License

GPL-2.0-or-later
