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

1. **Create a database and user**
   ```sql
   CREATE DATABASE telecom_crm CHARACTER SET utf8mb4;
   CREATE USER 'crm'@'localhost' IDENTIFIED BY 'choose-a-password';
   GRANT ALL ON telecom_crm.* TO 'crm'@'localhost';
   ```

2. **Configure**
   ```bash
   cp config.sample.php config.php   # then edit the DB details
   ```
   You can also set `CRM_DB_HOST`, `CRM_DB_NAME`, `CRM_DB_USER` and `CRM_DB_PASS` as environment variables.

3. **Install the schema and create an admin user**
   ```bash
   php install/install.php --email=you@example.com --password='a-strong-password' --name="Your Name"
   # add --demo to load sample customers, lines, tickets and deals
   ```

4. **Serve the `public/` directory**
   - Quick local run: `php -S localhost:8080 -t public`, then open http://localhost:8080
   - Apache/Nginx: point the document root at `public/`. Only that folder should be web-accessible, because `config.php`, `src/` and `install/` sit outside it.

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
public/            web root: index.php (front controller), assets/
src/
  bootstrap.php    config + includes
  db.php           PDO helpers
  auth.php         sessions, login, roles
  entities.php     field definitions + business rules (SLA, contract dates, numbering…)
  repository.php   generic validation, CRUD, listing, formatting
  controllers.php  page handlers
templates/         PHP view templates
install/           schema.sql, installer, demo data
tests/             integration tests
```

### Adding a field

Most screens are driven by the definitions in `src/entities.php`. To add a field:

1. Add the column to `install/schema.sql`. For an existing install, also run `ALTER TABLE`.
2. Add one line to that entity's `fields` array.

The form, validation, detail view and CSV export pick it up automatically. To show it on the list page, add it to `list`.
