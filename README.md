# Play for Purpose Ohio Tournament Platform

This project provides a PHP 8+ and MySQL 8+ powered tournament management platform for [game.playforpurposeohio.com](https://game.playforpurposeohio.com). It includes an installation wizard, user management with email verification, admin tools, tournament management for single, double, and round-robin formats, dynamic brackets/groups using self-hosted jQuery plugins, and player statistics.

## Features

- **Installation Wizard** (`/install`)
  - Collects database settings, admin credentials, and SMTP settings.
  - Writes configuration to `config/.env.php` and locks installation.
  - Seeds database schema defined in `database/schema.sql`.
- **User System**
  - Registration with email verification, password hashing, CSRF protection, and session-based login.
  - Role-based access control (admin vs. standard user).
- **Admin Tools**
  - Manage SMTP configuration, send test emails, view system info, bump version, rebuild stats.
  - Create, open, start, and complete tournaments; manage entrants; edit brackets/groups.
  - Live match center for recording scores and winners with automatic stat updates.
- **Tournament Support**
  - Single and double elimination via [jQuery Bracket](assets/vendor/jquery-bracket).
  - Round-robin via [jQuery Group](assets/vendor/jquery-group).
  - Dynamic JSON-driven brackets/groups stored in MySQL and rendered client-side.
  - Public tournament hubs with participant lists, bracket views, and join/withdraw workflows.
- **Player Statistics**
  - Tracks tournaments played, wins, losses, win rate, and recent match history.
  - Script `scripts/rebuild_stats.php` rebuilds statistics from match data.
- **Version Tracking**
  - Site version stored in `VERSION`. Use `scripts/bump_version.php` or the admin panel to increment.

## Requirements

- PHP 8.0+
- MySQL 8+
- Web server configured for PHP (e.g., Nginx, Apache)

## Installation

1. Ensure the web root points to this repository.
2. Provide write access to the `config/` directory for the web server during installation.
3. Browse to the site root; you will be redirected to `/install`.
4. Complete the wizard:
   - **Step 1:** Database credentials (ensure the database exists).
   - **Step 2:** Admin account (a strong password suggestion is provided).
   - **Step 3:** SMTP settings with optional test email.
5. After completion, the installer writes `config/.env.php`, creates a lock file, and redirects to the login page.

## Configuration

- Runtime configuration is stored in `config/.env.php` as a PHP array.
- A sample template is provided in `config/.env.sample.php`.
- To rerun the installer, delete `config/.env.php` and `config/.install_lock`.

## Development Notes

- All assets (jQuery, jQuery Bracket, jQuery Group, PHPMailer) are self-hosted.
- Use `scripts/bump_version.php` to increment the numeric suffix of the site version.
- Player statistics can be rebuilt with `scripts/rebuild_stats.php`.
- Database schema is defined in `database/schema.sql` and loaded during installation.

## Security Considerations

- Passwords stored with `password_hash()`.
- All POST forms include CSRF tokens.
- SQL operations use prepared statements.
- Configuration files are set to read-only (`chmod 0440`).

## Directory Structure

```
assets/                Front-end assets (CSS, JS, vendor libraries)
config/                Configuration storage
includes/              Core PHP helpers and domain logic
install/               Installation wizard
pages/                 Public and user-facing pages
admin/                 Admin dashboard and tools
api/                   CSRF-protected POST endpoints
scripts/               Maintenance CLI scripts
vendor/                Third-party PHP libraries (PHPMailer)
database/schema.sql    MySQL schema
VERSION                Site version identifier
README.md              Project documentation
```

## Versioning

The `VERSION` file begins at `alpha 0.0001`. Each call to the bump script increments the final numeric block by 1 (e.g., `alpha 0.0001` → `alpha 0.0002`). The admin panel exposes a button to trigger this process.

## Testing

- Manual web testing is recommended after configuring the database and SMTP.
- Ensure PHP has permissions to write to `config/` during installation.

Enjoy building tournaments for the Play for Purpose Ohio community!
