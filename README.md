# Lookit Page Watch

[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Lint](https://github.com/Lookit-Design/page-watch/actions/workflows/lint.yml/badge.svg)](../../actions/workflows/lint.yml)
[![Coding Standards](https://github.com/Lookit-Design/page-watch/actions/workflows/coding-standards.yml/badge.svg)](../../actions/workflows/coding-standards.yml)
[![Plugin Check](https://github.com/Lookit-Design/page-watch/actions/workflows/plugin-check.yml/badge.svg)](../../actions/workflows/plugin-check.yml)
[![Tests](https://github.com/Lookit-Design/page-watch/actions/workflows/test.yml/badge.svg)](../../actions/workflows/test.yml)

Scheduled screenshots of chosen pages, compared against a locked baseline and emailed side by side.

Supports `WordPress >= 6.4` on `PHP >= 8.1`.

## Table of Contents

- [Getting Started](#getting-started)
  - [Installation](#installation)
  - [Configuration](#configuration)
- [Features](#features)
- [Security and Privacy](#security-and-privacy)
- [Development](#development)
  - [Setup](#setup)
  - [Running the Test Suite](#running-the-test-suite)
  - [Coding Standards](#coding-standards)
  - [Continuous Integration](#continuous-integration)
- [Contributing](#contributing)
- [License](#license)

## Getting Started

### Installation

This plugin is installed from GitHub, not from WordPress.org.

1. Clone or copy this repository into `/wp-content/plugins/lookit-page-watch`.
2. Activate **Lookit Page Watch** through the **Plugins** menu in WordPress.

### Configuration

1. Import `n8n/lookit-page-watch-capture-v2.json` into n8n, then set the shared token and allowed capture hosts in the Config node. See `n8n/SETUP.md`.
2. Activate the workflow and copy its production webhook URL.
3. In WordPress, go to **Page Watch → Schedule and email**. Paste the webhook URL and the same shared token, then save.
4. Use **Test the capture service** to confirm the connection.
5. Add pages to the watchlist and run a capture. The first capture of each page becomes its baseline.

The token field stays blank after save; leave it empty to keep the stored value.

## Features

* Watchlist of any URLs, on this site or elsewhere.
* Capture every 1, 6, 12 or 24 hours, anchored to a time you pick.
* A locked baseline image per page, replaced only on request.
* Side-by-side comparison in wp-admin, plus capture history.
* Whole-page and worst-area difference scoring, each with its own threshold.
* HTML digest email at a set time, or after every run.
* Retention window for old captures, with baselines exempt.

## Security and Privacy

* The capture **shared token is never rendered** back into the settings form. Submitting the field blank keeps the saved value.
* Settings are **not autoloaded**.
* Capture payloads are checked as real PNG, JPEG, or WebP images before they are stored.
* Capture responses have byte, dimension, and pixel limits.
* The n8n workflow requires an explicit hostname allowlist and refuses its placeholder credentials.
* Admin screens and AJAX actions require `manage_options`.
* On uninstall, data is kept by default (configurable under Storage).

The plugin sends each watched URL, capture width, a whole-page flag, the site address, and the shared token to the capture endpoint you configure. No page content, user data, or WordPress credentials are transmitted.

## Development

### Setup

Install the development dependencies with [Composer](https://getcomposer.org/):

```bash
composer install
```

### Running the Test Suite

The integration tests run against a real WordPress test install and a MySQL database. Install the test suite once, then run PHPUnit:

```bash
# bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp-version>
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest

composer test
```

### Coding Standards

This project follows the WordPress Coding Standards and checks PHP cross-version compatibility:

```bash
composer phpcs    # check coding standards
composer phpcbf   # auto-fix what can be fixed
composer compat   # check PHP 8.1+ compatibility
composer lint     # php -l syntax check on all files
```

### Continuous Integration

Every push and pull request runs the following GitHub Actions workflows:

| Workflow | Purpose |
| --- | --- |
| [Lint](../../actions/workflows/lint.yml) | `php -l` syntax check across the supported PHP versions |
| [Coding Standards](../../actions/workflows/coding-standards.yml) | WordPress Coding Standards (PHPCS) |
| [Plugin Check](../../actions/workflows/plugin-check.yml) | Official WordPress Plugin Check |
| [Test](../../actions/workflows/test.yml) | PHPUnit across a WordPress × PHP matrix |

A scheduled [Version Monitor](../../actions/workflows/version-monitor.yml) workflow watches for new PHP and WordPress releases so compatibility can be reviewed proactively.

## Contributing

Bug reports and pull requests are welcome on [GitHub](../../issues).

## License

This plugin is available as open source under the terms of the [GPL-2.0-or-later License](https://www.gnu.org/licenses/gpl-2.0.html).

---

_Lookit&reg; is a registered trademark of ZENOVA CORP. n8n and FluentSMTP are trademarks of their respective owners; this plugin is an independent integration and is not affiliated with, sponsored by, or endorsed by either._
