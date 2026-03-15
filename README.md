# Kreblu

A modern, free, open-source CMS built on PHP 8.5+ and MySQL 8.4+.
Community owned. Zero restrictions. No corporate bullshit.

> **Status:** Early development (Phase 0 complete). Not ready for production use.

---

## What is Kreblu?

Kreblu is a ground-up CMS that takes everything WordPress gets right (ease of use, plugin/theme ecosystem, runs on any host) and rebuilds the foundation with modern PHP, a clean database schema, and proper architecture.

- **PHP 8.5+** — modern, fast, runs everywhere
- **MySQL 8.4+ / MariaDB 10.11+** — InnoDB, JSON columns, full-text search
- **Vanilla JS** — no framework dependency, no build step for core
- **MIT License** — do whatever you want with it
- **Free forever** — the core, the plugin directory, the theme directory. All free.
- **Plugin/theme developers can use any technology** — PHP, React, Vue, Node via REST API, whatever

## Quick Start (Development)

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running
- [Git](https://git-scm.com/) installed

### Setup

```bash
# 1. Clone the repo
git clone https://github.com/atdheboshnjaku/kreblu.git
cd kreblu

# 2. Start the containers (PHP 8.3 + MySQL 8.4 + Nginx)
docker compose up -d

# 3. Wait ~30 seconds for MySQL to initialize, then check status
docker compose ps

# 4. Install PHP dev dependencies (PHPUnit, PHPStan, etc.)
docker compose exec app composer install

# 5. Open in browser
# Site:  http://localhost:8080
# (You'll see the installer once we build it in Phase 5)
```

### Running Tests

```bash
# Run all tests
docker compose exec app vendor/bin/phpunit

# Run only unit tests
docker compose exec app vendor/bin/phpunit --testsuite Unit

# Run static analysis
docker compose exec app vendor/bin/phpstan analyse

# Run code style check
docker compose exec app vendor/bin/phpcs
```

### Stopping

```bash
# Stop containers (data preserved)
docker compose stop

# Stop and remove containers (database data preserved in Docker volume)
docker compose down

# Stop and remove everything including database data
docker compose down -v
```

### Connecting to the Database

If you want to inspect the database directly:

```
Host:     localhost
Port:     3306
User:     kreblu
Password: kreblu_dev
Database: kreblu
```

Use any MySQL client (TablePlus, DBeaver, MySQL Workbench, command line).

Or from the terminal:
```bash
docker compose exec db mysql -u kreblu -pkreblu_dev kreblu
```

---

## Project Structure

```
kreblu/
├── index.php                 # Front controller — all requests come here
├── os-config.sample.php      # Config template (installer generates os-config.php)
├── .htaccess                 # Apache rewrite rules
├── os-core/                  # Core engine (the heart of Kreblu)
│   ├── App.php               # Service container
│   ├── Config.php            # Configuration manager
│   ├── bootstrap.php         # Application bootstrap
│   ├── autoload.php          # PSR-4 autoloader (works without Composer)
│   ├── Database/             # Database connection, query builder, migrations
│   ├── Http/                 # Router, request/response, middleware
│   ├── Content/              # Posts, pages, taxonomies, comments, media
│   ├── Auth/                 # Authentication, users, roles, sessions
│   ├── Hooks/                # Action & filter hook system
│   ├── Plugin/               # Plugin loader and API
│   ├── Theme/                # Theme loader, template engine, customizer
│   ├── Cache/                # Page cache, object cache
│   ├── Api/                  # REST API router and endpoints
│   ├── Security/             # Sanitization, CSRF, rate limiting
│   ├── Modules/              # Built-in modules (Forms, SEO, i18n, etc.)
│   └── Helpers/              # Global os_* helper functions
├── os-admin/                 # Admin panel (vanilla JS, Web Components)
├── os-content/               # User content (themes, plugins, uploads)
│   ├── themes/
│   ├── plugins/
│   ├── uploads/
│   ├── cache/
│   └── logs/
├── os-cli/                   # CLI tools (for developers)
├── os-install/               # Browser-based installer
├── tests/                    # PHPUnit test suite
└── docker/                   # Docker config (dev only, not shipped)
```

## Contributing

This project is in early development. We welcome contributions but the architecture is still being built out. If you want to help:

1. Read the Engineering Blueprint (link coming soon)
2. Look at open issues
3. Join the discussion in GitHub Discussions

## License

MIT License. Do whatever you want with it. See [LICENSE](LICENSE) for details.
