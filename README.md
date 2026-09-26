# Telecom CRM

A lightweight CRM for telecoms resellers and service providers, built with **PHP 8.1+ and MySQL / MariaDB**. There are no frameworks or Composer dependencies, so it runs on any standard LAMP stack or shared host.

## Features

| Area | What it does |
|---|---|
| **Dashboard** | Active customers, MRR/ARR, open tickets and SLA breaches, weighted pipeline, contracts up for renewal, revenue by service type, your tasks and recent activity |
| **Customers** | Business and residential accounts with auto-generated account numbers (`ACC-10001`), status (prospect / active / suspended / churned) and an account manager. The customer page shows services, tickets, opportunities, contacts and an activity log in one place |
| **Contacts** | Multiple contacts per customer, with primary and billing flags |
| **Services & lines** | Mobile SIMs (MSISDN), broadband (FTTP/SOGEA), VoIP seats, SIP trunks, hosted PBX, leased lines, Ethernet and hardware. Each one records its carrier (EE, Vodafone, O2, Openreach, CityFibre, BT Wholesale, Gamma, Colt…), price, contract start, term and end date |
| **Contract renewals** | The contract end date is calculated from start date + term. Contracts ending within 90 days (configurable) or already out of contract are flagged, and "Start renewal" creates a renewal opportunity in one click |
| **Support tickets** | Tickets get references (`TCK-000001`) and a category (fault / billing / order / porting / cancellation). Priorities P1–P4 have SLA timers, and you can record the carrier fault reference. The update thread supports internal and customer-facing notes and logs status changes |
| **Sales pipeline** | Kanban board plus list view. Each deal has monthly and one-off value, term, total contract value and a probability that follows the stage. Deals are typed as new business, upsell or renewal |
| **Products & tariffs** | A catalogue of products. Picking a product on a service fills in the price, setup fee, term, type and carrier |
| **Activities** | Calls, emails, meetings, notes and tasks with due dates |
| **Everywhere** | Global search (names, postcodes, phone numbers, circuit IDs, ticket refs), filters, sorting, pagination and CSV export on every list. Works on mobile and supports dark mode |
| **Users** | Admin and agent roles. Only admins manage users and the product catalogue |

Security: prepared statements throughout, CSRF tokens on every form, output escaping, bcrypt password hashing, session fixation protection, protection against open redirects and CSV formula injection, and security headers.

## Installation

You don't need root or SSH access. Pick whichever of these fits your hosting.

### Option A: shared hosting (cPanel, Plesk, DirectAdmin…), no SSH needed

1. **Check your PHP version.** You need PHP 8.1 or newer, with the `pdo_mysql` extension (it's on by default almost everywhere). In cPanel, check under *MultiPHP Manager* or *Select PHP Version*.
2. **Create a database.** In cPanel open *MySQL® Databases* (or use the *MySQL Database Wizard*). Create a database and a user, then add the user to the database with **All Privileges**. Write down the full names, which cPanel prefixes with your account name (e.g. `myaccount_crm`), and the password.
3. **Upload the files.** Download the code as a ZIP from GitHub (*Code → Download ZIP*). In cPanel *File Manager*, upload it into `public_html` and extract it. Rename the extracted folder to something like `crm`, so you end up with `public_html/crm/`.
   - The `.htaccess` files included in the download block web access to everything except `public/`, including `config.php` and the source code.
   - If your host lets you point a domain or subdomain at a folder (cPanel → *Domains*), you can use `crm/public` as its document root. Then only the `public/` folder is web-reachable at all.
4. **Run the web installer.** Visit `https://yourdomain/crm/` (or `https://crm.yourdomain/` if you used a subdomain). You'll be taken to the installer, which asks for:
   1. **The database details** from step 2. It tests the connection and writes `config.php` for you. If the folder isn't writable, it shows you the file's contents to copy into a new `config.php` via File Manager instead.
   2. **Your admin name, email and password.** Tick *Load demo data* to try it out with sample customers.
5. Sign in. The installer locks itself once an admin account exists. You can also delete `public/install.php` for extra peace of mind.

> Run the installer straight after uploading. Until it's finished, anyone who finds the URL could complete it.

### Option B: Linux server or VPS without sudo, over SSH

This needs PHP 8.1+ on the command line and a MySQL/MariaDB database you can log in to (ask your admin for one if you don't have one).

```bash
git clone <repo-url> ~/crm && cd ~/crm
cp config.sample.php config.php          # edit the DB details
php install/install.php --email=you@example.com --password='a-strong-password' --name="Your Name" [--demo]
```

Then either:
- point your existing web server's document root (or a symlink in `~/public_html`) at `~/crm/public`; or
- run it with PHP's built-in server: `php -S 0.0.0.0:8080 -t public`. This is fine for trying it out or for a small internal team. For anything public-facing, put it behind a proper web server with HTTPS.

The web installer from Option A works here too: skip the `install/install.php` step and just open the site.

### Option C: you do have root

Create the database and user yourself, then follow Option B:

```sql
CREATE DATABASE telecom_crm CHARACTER SET utf8mb4;
CREATE USER 'crm'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL ON telecom_crm.* TO 'crm'@'localhost';
```

For the web server, point the document root at `public/` (Apache/Nginx + PHP-FPM). With Apache, allow `.htaccess` overrides (`AllowOverride All`).

## Configuration

`config.php` also controls:

- `currency`: the currency symbol (default `£`)
- `timezone`: PHP and MySQL are kept in sync (default `Europe/London`)
- `renewal_window_days`: how far ahead a contract counts as "up for renewal" (default 90)
- `sla_hours`: SLA targets per ticket priority (default P1 4h, P2 8h, P3 24h, P4 72h)

## Tests

```bash
php tests/run.php
```

The tests run against a throwaway `<db_name>_test` database, which is created and dropped automatically. The DB user needs `CREATE`/`DROP` rights for it.

## Project layout

```
public/            web root: index.php (front controller), install.php (web installer), assets/
src/
  bootstrap.php    config + includes
  db.php           PDO helpers
  auth.php         sessions, login, roles
  entities.php     field definitions + business rules (SLA, contract dates, numbering…)
  repository.php   generic validation, CRUD, listing, formatting
  controllers.php  page handlers
  installer.php    shared install logic (CLI + web)
templates/         PHP view templates
install/           schema.sql, installer, demo data
tests/             integration tests
.htaccess, index.php  protection + redirect for installs inside public_html
```

### Adding a field

Most screens are driven by the definitions in `src/entities.php`. To add a field:

1. Add the column to `install/schema.sql`. For an existing install, also run `ALTER TABLE`.
2. Add one line to that entity's `fields` array.

The form, validation, detail view and CSV export pick it up automatically. To show it on the list page, add it to `list`.
