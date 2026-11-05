# Clikgate for WordPress

The Clikgate WordPress plugin (WordPress.org slug `clikgate`): device sign-in, the Clikgate web script, and
payment confirmations from WooCommerce and supported payment-form plugins.

**This repository is a read-only mirror.** The plugin is developed in Clikgate's main repository and copied
here on each release. Please open issues here; pull requests are closed automatically.

Documentation: https://clikgate.com/docs/wordpress

## Development

```sh
composer install
vendor/bin/phpunit
php scripts/check-version.php            # plugin header, readme.txt "Stable tag" and CLIKGATE_VERSION match
php scripts/check-version.php 1.0.0      # ... and match this release version
sh scripts/build-zip.sh                  # build/clikgate.zip (what WordPress.org gets)
```

License: GPL-2.0-or-later.
