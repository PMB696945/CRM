# Telecom CRM

A lightweight CRM for telecoms resellers and service providers, built with **PHP 8.1+ and MySQL / MariaDB**. There are no frameworks or Composer dependencies, so it runs on any standard LAMP stack or shared host.

## Features

| Area | What it does |
|---|---|
| **Dashboard** | Active customers, MRR/ARR, open tickets and SLA breaches, weighted pipeline, contracts up for renewal, revenue by service type, your tasks and recent activity |
| **Customers** | Business and residential accounts with auto-generated account numbers (`ACC-10001`), status (prospect / active / suspended / churned) and an account manager. The customer page shows services, tickets, opportunities, contacts and an activity log in one place |
| **Head office & address book** | Each customer has a head office address plus an address book of other sites (e.g. installation addresses). Each site can have its own contact, or use the head office main contact. Services record which site they're installed at |
| **Contacts** | A main contact and an accounts contact (for invoices and statements) are entered right on the customer form. Add as many other contacts as you like. The accounts contact can be sent to Xero so invoices go to the right person |
| **Marketing preferences** | Per contact: marketing by email, phone, text and post, which topics they want, how permission was given and when it changed, and whether they get service alerts |
| **Service alerts & marketing** | Email customers by the services they have: service type, carrier, product, site postcode (for local outages), dealer. Preview the exact recipients and the email, send a test, then send. Goes through the CRM's email (in batches) or, for marketing, through Mailchimp. One-click unsubscribe in every email |
| **Services & lines** | Mobile SIMs (MSISDN), broadband (FTTP/SOGEA), VoIP seats, SIP trunks, hosted PBX, leased lines, Ethernet and hardware. Each one records its carrier (EE, Vodafone, O2, Openreach, CityFibre, BT Wholesale, Gamma, Colt…), price, contract start, term and end date |
| **Contract renewals** | The contract end date is calculated from start date + term. Contracts ending within 90 days (configurable) or already out of contract are flagged, and "Start renewal" creates a renewal opportunity in one click |
| **Support tickets** | Tickets get references (`TCK-000001`) and a category (fault / billing / order / porting / cancellation). Priorities P1–P4 have SLA timers, and you can record the carrier fault reference. The update thread supports internal and customer-facing notes and logs status changes |
| **Ticket groups & queue** | Groups such as Sales, Faults and Billing, with staff in as many as needed. New tickets go to a group (by category, or chosen) and wait in its queue, oldest first, until someone in the group picks one up or presses **Take next ticket**. Staff see their groups' tickets; managers and admins see all |
| **Sales pipeline** | Kanban board plus list view. Each deal has monthly and one-off value, term, total contract value and a probability that follows the stage. Deals are typed as new business, upsell or renewal |
| **Products & tariffs** | A catalogue of products with a sale price, cost price (and margin), sales and purchases nominal codes, setup fee, term and billing cycle (weekly, monthly, quarterly, bi-annually or yearly). Picking a product on a service or quote fills in the details, converting the price to a monthly amount for MRR. Products can be sent to Xero as items: one at a time, several ticked at once, or automatically when saved |
| **Activities** | Calls, emails, meetings, notes and tasks with due dates |
| **Everywhere** | Global search (names, postcodes, phone numbers, circuit IDs, ticket refs), filters, sorting, pagination and CSV export on every list. Works on mobile and supports dark mode |
| **Xero balances** | Connect Xero read-only to see each customer's outstanding and overdue balance and number of unpaid invoices. Adds an "In arrears" and "Over credit limit" filter, an overdue-debt total on the dashboard, and a link to the contact in Xero. Syncs on demand or by cron |
| **GoCardless Direct Debit** | Shows whether each customer has an active, pending or failed Direct Debit mandate. If they don't have one, creates a personal GoCardless setup link, prefilled with their details, that you can copy or email in one click. When they complete it, the CRM links them automatically. Adds a "No Direct Debit" filter and a dashboard count |
| **One company, several roles** | Like Xero contacts: a company can be a customer, a supplier and a dealer at once. Its page has tabs: Overview (head office, contacts, address book, files, activity), Customer, Supplier and Dealer. Tick **This company is also a supplier** on the customer, or use **Also a customer / Also a dealer** on a supplier. The name, address and phone are shared |
| **Dealers** | Mark any customer as a dealer and put other customers under it: referred by the dealer, or billed via the dealer, optionally covered by the dealer's master services agreement (MSA). Dealer pages show their customers, the group's combined MRR and commission |
| **Quotes** | Build quotes from your product catalogue and email them. The customer accepts (name, email and a tick box) or declines on a branded web page. You're emailed when they respond |
| **Contracts & e-signature** | Upload a Word template for each service type. When a quote is accepted, the contract is filled in (customer details, a table of the quoted services, totals) and emailed to the customer to sign online with the built-in e-signature. A signature certificate is saved, and the services can be added to the customer as pending |
| **Giacom broadband ordering** | Check broadband availability at a customer's head office or any site (products, speeds, earliest dates, what's on the line), place provide or migrate orders with Giacom, and follow them to completion. Completed orders make the customer's service live |
| **Roles & approvals** | Eight roles (super admin, admin, manager, staff, sales, support, finance, read only). A super admin decides what each role can see and do. Staff can ask to close or delete a customer; an approver has to agree before anything happens |
| **Audit trail** | Who did what and when, with before-and-after values for every change, on one page (filter by person, customer, record, action or date, and export) and on each customer's page |

Security is covered in detail in the **Security** section below. In summary: two-factor sign-in, sign-in lockout, session time-outs, encrypted API keys, a full audit trail, role-based permissions, approval for closing or deleting customers, strict browser security headers, and protection against the common web attacks.

## Installation

You don't need root or SSH access. Pick whichever of these fits your hosting.

### Option A: shared hosting (cPanel, Plesk, DirectAdmin…), no SSH needed

1. **Check your PHP version.** You need PHP 8.1 or newer, with the `pdo_mysql` extension (it's on by default almost everywhere). In cPanel, check under *MultiPHP Manager* or *Select PHP Version*.
2. **Create a database.** In cPanel open *MySQL® Databases* (or use the *MySQL Database Wizard*). Create a database and a user, then add the user to the database with **All Privileges**. Write down the full names, which cPanel prefixes with your account name (e.g. `myaccount_crm`), and the password.
   - **Privileges:** *All Privileges* is the simple choice and is safe here, because cPanel limits it to this one database. For the minimum, tick `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `INDEX`, `ALTER` and `REFERENCES`. (`REFERENCES` is needed on MySQL 8.0.22+ for the foreign keys between tables.)
   - After installing, you can tighten it further to just `SELECT`, `INSERT`, `UPDATE`, `DELETE`. The CRM runs fine that way. Grant the others back temporarily before an upgrade that changes the database structure.
3. **Upload the files.** Download the code as a ZIP from GitHub (*Code → Download ZIP*). Upload the **whole** CRM folder, not just `public/`, into the folder your site address points at, and extract it there. For example, put it in `public_html/crm/` for `https://yourdomain/crm/`, or straight into `public_html` if the CRM should be the whole site. Make sure hidden files (`.htaccess`) are included; in cPanel File Manager, tick *Settings → Show Hidden Files* to check.
   - The top-level `.htaccess` serves the app from `public/` behind the scenes, so the address is simply `https://yourdomain/crm/`, with no `/public/`. It works on normal domains, sub-folders and temporary URLs such as `/~account/`, without any editing.
   - The same `.htaccess` files block web access to `config.php`, the source code and the installer scripts.
   - **Alternative:** upload only the *contents* of `public/` into your web folder, and put everything else in a folder named `crm` next to `public_html` (outside the web root). The pages find it automatically, and show where they looked if they can't.
   - If your host lets you point a domain or subdomain at a folder (cPanel → *Domains*), you can also use `crm/public` as its document root.
4. **Run the web installer.** Visit your site address (e.g. `https://yourdomain/crm/`) and you'll be taken to the installer. It first asks for a **setup code**: open File Manager and copy the code from `crm/install/setup-code.txt`. This proves you control the hosting account, so a stranger who finds the installer can't take over. Don't open `crm/install/`: that folder holds the command-line installer and is deliberately blocked (403). The installer asks for:
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

## Troubleshooting

**"A CRM folder is missing", or an unexpected error after installing or moving servers.** Keep the `install/` folder. It holds the database updates the CRM checks on every page, so it mustn't be deleted after installing, and it must be copied when moving servers. It's blocked from the web, so leaving it there is safe.

**The page shows a message instead of the CRM.** The CRM checks the server on every page load and explains what's wrong. The most common problems are an old PHP version (8.1+ is needed; change it in cPanel → *MultiPHP Manager* / *Select PHP Version*) and a missing PHP extension (`pdo_mysql`, `curl`, `mbstring`).

**"Something went wrong".** The error is shown on the page and saved in `public/app/crm-error.log`. That file can't be opened from the web; use File Manager.

**"500 Internal Server Error" with no details.** This means the web server stopped before PHP ran, which is almost always an `.htaccess` setting the host doesn't allow. Check in this order:
1. Look in cPanel → *Metrics → Errors* (or the `error_log` file in the folder) for the exact reason.
2. Temporarily rename the `.htaccess` in the CRM's top folder, then open `…/crm/public/`. If that works, the host doesn't allow one of the settings in that file, usually `Options -Indexes`. Delete that line and restore the file.
3. Make sure folders are permission `755` and files `644`. Some hosts refuse to run files that are writable by other users (e.g. `777`).

## Security

**Signing in**
- **Lockout:** after 5 wrong passwords for an account (or 20 from one IP address) within 15 minutes, sign-in pauses for 15 minutes. Every attempt is logged.
- **Two-factor sign-in (TOTP):** set it up under **My profile** with any authenticator app. You get 10 single-use recovery codes. Admins can require it for everyone (Settings → Security) and reset it for someone who has lost their phone (Users).
- **Sessions:** they end after 60 minutes of inactivity (adjustable) and always after 12 hours.
- **Passwords:** at least 10 characters, not common words, and not containing your email name. They're stored as bcrypt hashes.

**Roles and permissions** (Admin → Roles & permissions, super admins only)
- **Roles:** everyone has one role: eight built in, plus any you create. A tick-box grid sets what each role can do: edit customers, close or delete customers without approval, approve requests, services, tickets, sales, delete records, export, see balances and Direct Debit, products, send alerts/marketing, settings, users, and view the audit trail.
- **Cost prices:** "See cost prices and margins" (by default admin, manager, staff, sales and finance) and "Change cost prices" (admin, manager and finance). Someone who can change cost prices but not manage products (a manager or finance, by default) can edit only the cost price.
- **Custom roles:** add your own (e.g. "Provisioning") under **Add a role**, optionally starting from an existing role's permissions. It becomes a new column in the grid and appears on the Users page. Custom roles can be renamed, or deleted once nobody has them. **Reset to defaults** only affects the built-in roles.
- **Super admins** can always do everything. Only they can change roles, create or edit other super admins, and (by default) view the audit trail. The person who installed the CRM is a super admin; existing admins became super admins when you upgraded, and agents became **Staff**.
- **Closing and deleting customers:** people with "Close/Delete without approval" do it straight away. Anyone else who can edit customers gets **Request closure / Request deletion** instead. Approvers (Admin → Approvals, with a count in the menu) see the request, the reason and what it affects, and approve or reject it. They're emailed when a request comes in and the requester is emailed the outcome. You can't approve your own request unless you're a super admin. Staff also can't mark a customer as closed by editing it.

**Data**
- **Encrypted credentials:** the SMTP password, Xero and GoCardless credentials are encrypted in the database (AES-256-GCM). Two-factor secrets are too.
- **The key:** it's in `app.key`, next to `config.php`, created automatically. You can instead set `'app_key' => '…'` in `config.php`. **Back up the key separately from the database**: without it, saved API keys can't be read, and you'd need to enter them again.
- **Storage:** contracts and templates are kept in `storage/`, and the web can't access that folder, `config.php`, `app.key`, logs or the source code.

**Audit trail** (Admin → Audit trail)
- **What it records:** sign-ins, failed sign-ins and lockouts; two-factor changes; every create, edit and delete, **with the old and new value of each field**; close/delete requests and decisions; ticket updates; emails and campaigns sent; unsubscribes; CSV exports and contract downloads; quote and contract actions; syncs; role, user and settings changes. Anything submitted that isn't covered in more detail is still logged.
- **Finding things:** filter by person, action, date range or text; open **History** on any record or customer (which includes their contacts, sites, services, tickets, quotes and so on); click a person to see everything they did. Export the results to CSV.
- **Integrity:** the CRM offers no way to edit or delete entries, and a deleted customer's history is kept.

**Web protections**
- **Common attacks:** prepared SQL statements, escaped output, and anti-forgery codes (CSRF tokens) on every form.
- **Browser headers:** a strict content security policy (no inline scripts), clickjacking protection, and HSTS when on HTTPS.
- **HTTPS:** switch on **Always use HTTPS** in Settings → Security once SSL is working.
- **Errors:** error details appear only to signed-in admins. Everyone else sees a reference code that matches the entry in `public/app/crm-error.log`.

**Your part:** use HTTPS; keep PHP updated; limit the database user's privileges; keep backups of the database, `storage/` and `app.key` somewhere secure; and disable users when people leave.

## Configuration

`config.php` also controls:

- `currency`: the currency symbol (default `£`)
- `timezone`: PHP and MySQL are kept in sync (default `Europe/London`)
- `renewal_window_days`: how far ahead a contract counts as "up for renewal" (default 90)
- `sla_hours`: SLA targets per ticket priority (default P1 4h, P2 8h, P3 24h, P4 72h)

## Xero integration

The CRM can show each customer's balance from Xero. It only **reads** from Xero and never changes anything there.

**Setup (admin, about 5 minutes):**
1. In the CRM, open **Xero** in the sidebar. It shows the exact *Redirect URI* to use.
2. At [developer.xero.com/app/manage](https://developer.xero.com/app/manage), click **New app**, choose **Web app**, and paste that Redirect URI. Xero requires `https://` except for localhost.
3. Copy the app's **Client ID** and a generated **Client secret** into the CRM, then click **Save**.
4. Click **Connect to Xero** and approve access for your organisation. The first sync runs straight away.

**How balances work:**
- **Balance** is all unpaid (authorised) sales invoices, minus any unused credit notes, converted into your base currency.
- **Overdue** is the part of that balance where the due date has passed.
- **Not included:** overpayments and prepayments. Picking those up would need the extra `accounting.payments.read` scope.

**Linking customers to Xero contacts:**
- On each sync, customers are matched to Xero contacts automatically, but only when a match is unambiguous. The CRM tries the Xero contact's **Account number** first (set it to the CRM number, e.g. `ACC-10001`), then **email**, then **company name**.
- Anything it can't match is listed on the Xero page, and you can choose the contact by editing the customer.
- Links you set by hand are never overwritten.

**Keeping balances up to date:**
- Press **Sync now** on the dashboard or the Xero page, or schedule it with a cPanel cron job:
  ```
  php /home/youraccount/path/to/crm/cron/sync.php
  ```
  This also syncs GoCardless if it's set up. The older `cron/xero-sync.php` still works.
  Hourly is plenty. Running it at least every few weeks also stops Xero expiring the connection, which happens after 60 days without use.

**Scopes:** the CRM requests `offline_access accounting.contacts.read accounting.invoices.read`, the granular scopes Xero requires for apps created from March 2026. If an older app hasn't moved to granular scopes yet, change this under *Advanced* on the Xero page.

**Upgrading an existing install:** new versions update the database automatically the first time you load a page. This needs the database user to have `CREATE`, `ALTER`, `INDEX` and `REFERENCES`. If it doesn't, the CRM shows a page saying so.

## GoCardless integration

The CRM checks each customer's Direct Debit mandate in GoCardless. If there isn't an active one, it gives you a setup link to send them.

**Setup (admin):**
1. In GoCardless, go to **Developers → Create → Access token** and choose **Read-write**. A read-only token can check mandates, but can't create setup links.
2. In the CRM, open **GoCardless** in the sidebar, paste the token, choose *Live* or *Sandbox*, then click **Save & test**.
3. Click **Sync now**. Existing GoCardless customers are matched to CRM customers by email (the account's or any contact's) or company name, only when unambiguous. You can also pick the GoCardless customer on the customer's edit form.

**On a customer's page**, the *Direct Debit* card shows one of three states:
- **Active:** the mandate reference and the next date a payment can be collected.
- **Setting up:** the customer has signed up and the bank is processing the mandate.
- **No mandate, or failed/cancelled:** use **Create Direct Debit setup link**. It creates a GoCardless hosted page, with the customer's name, email and address filled in, and gives you **Copy**, **Email to customer** and **Open** buttons. Links expire after 7 days. When the customer finishes, **Check now** (or the next sync) links them and shows the new mandate.

**Keeping it current:** schedule `php /path/to/crm/cron/sync.php` hourly in cPanel. It syncs Xero and GoCardless together.

**Optional settings:** under *Advanced*, you can set a return page to send customers to after setup (e.g. your website's thank-you page) and a scheme other than `bacs` for non-UK collections.

## Look and feel

The interface uses the design system of [TailAdmin](https://tailadmin.com), a free Tailwind CSS admin template (MIT licence; see `resources/css/TAILADMIN-LICENSE`). It includes a light/dark mode toggle, which is remembered per browser, and works on phones.

The finished stylesheet, `public/assets/app.css`, is committed along with the Outfit font files. **Hosting needs no Node.js or build step**, and no files are loaded from third-party servers.

To change the styles:

```bash
npm install          # once
npm run build:css    # rebuilds public/assets/app.css from resources/css/app.css
npm run watch:css    # or rebuild automatically while you edit
```

- `resources/css/app.css` holds TailAdmin's design tokens (colours, font, shadows) and the CRM's components (cards, tables, badges, forms and so on), written with Tailwind's `@apply`.
- Tailwind also scans `templates/` and `src/`, so you can use utility classes directly in templates too.

## Finding an address

When Giacom is connected, the customer and site forms have a **Find address** box above the address fields. It works like this:
1. Type a postcode and press **Find** (or Enter).
2. Choose the address from Giacom's list.
3. The address lines, town, county and postcode fill in.

On a new customer with no name yet, the company name at that address fills in too. You can still type an address in yourself.

## Customer details from Xero

The sync brings in each Xero contact, but it doesn't change customers unless you ask. **Admin → Xero → Customer details from Xero** covers this:
- **The list:** shows every linked customer whose name, email, phone, address or company number differs from Xero.
- **One-off update:** tick the customers and press **Update ticked customers from Xero**.
- **Automatic update:** tick **Update customers automatically on each sync**. Then whatever changes in Xero is copied over at the next sync. Only details changed in Xero since the previous sync are copied, so edits made in the CRM aren't overwritten.
- **Email:** the Xero contact's email becomes the customer's company email and their accounts contact's email.
- **Linked supplier:** changes carry over to a linked supplier record.

**Who can open Xero:** the permission **Open records in Xero** controls who gets links into Xero: the balance on a customer, supplier contacts and bills. By default Admin, Manager and Finance have it. Staff still see balances (with *See balances*) as plain figures, without the link.

## Adding customers from Xero

**Customers → Add from Xero** (also on Admin → Xero) lists the contacts in Xero that aren't customers in the CRM yet.
- **What's listed:** Xero customers by default, which are contacts you've raised a sales invoice to. Use **All contacts** for the rest, or search.
- **Adding them:** tick the ones you want and press **Add as customers**. Each becomes an active customer, already linked to Xero for balances.
- **Details brought across:**
  - their Xero account number (if not already used);
  - company email, phone, street (or postal) address, and company number;
  - the VAT number and website, in the notes;
  - Xero's contact person as the main contact, who also gets invoices;
  - Xero's other contact people, as contacts.
- **Type:** a contact named after one person becomes a residential customer.
- **Safety:** contacts already in the CRM are never added twice. A supplier already brought in from the same Xero contact is joined to the new customer as one company.
- **Refreshing:** press **Refresh from Xero** to pick up contacts added in Xero since the last sync.

**Customer account numbers** (Admin → Xero) gives customers that were already in the CRM their Xero account number too. It first lists what would change, and you can tick **Keep them in step** to update them after each sync. A number that's too long, or already used by another customer, is left alone and listed with the reason.

Xero won't let two contacts share an account number, archived ones included. When you merge contacts in Xero, the contact merged away is archived and keeps its account number. The CRM follows the merge to the contact that was kept, through several merges if need be, and uses that number. Failing that, it uses the number from the only other contact with the same name.

## Supplier invoices to Xero

When a supplier invoice is approved to pay (with **Send approved supplier invoices to Xero as bills** on, under Admin → Xero), it goes to Xero as a bill:
- **Supplier and details:** the bill goes to the supplier's Xero contact, with their invoice number, dates, and our PO number as the Reference.
- **Status:** it arrives as a draft, awaiting approval, or awaiting payment, as you choose.
- **File:** the uploaded invoice (PDF or photo) is attached.
- **Lines:** come from the purchase order when the amounts agree, otherwise from the lines read off the invoice.
- **Nominal codes:** each line is coded with the first of: the product's purchases nominal code; the supplier's **Default nominal code** (on the supplier); the default under Admin → Xero.
- **VAT, line by line**, so one invoice can mix rates. Each line uses the first of:
  1. the product's **VAT on purchases**, unless the invoice prints a different rate for that line;
  2. the rate printed on that line of the invoice (20%, 5%, 0%, exempt or reverse charge);
  3. the supplier's **Default VAT**;
  4. your reverse charge rate, if the invoice says the reverse charge applies;
  5. the standard 20% rate.

  Under Admin → Xero → Supplier bills you choose which Xero tax type is used for each printed rate, including **Reverse charge**. If Xero's VAT total for the bill differs from the invoice's, the invoice page says so.
- **VAT when you sell:** products also have **VAT on sales**, which is sent to Xero with the item.

**Load nominal codes from Xero** brings in your chart of accounts and VAT rates, so codes can be picked from a list and are checked.

If you switch on something that needs a new Xero permission, Admin → Xero shows **Press Reconnect to finish** until you've reconnected and approved it.

## Agreements on orders

When a customer accepts a quote, the CRM creates the order. It also builds the agreement from your **Contract templates**, if there's one for the services quoted or a "General" one.
- **Signed online:** the agreement is emailed to the customer to sign online straight away (see *Signing agreements online* below). You can switch this off in Settings, and send it from the order or contract page instead.
- **Signed another way:** if it's signed on paper or returned by email, use **Mark as signed** on the agreement, optionally attaching the signed PDF.

Orders show **Agreement sent** and **Agreement signed** as steps between *Quotation accepted* and *Order processing*. These steps show on the order page and on the customer's tracking page, and the customer sees an update at each one.
- **Agreement card:** the order page says what's needed next (create, send, waiting, signed).
- **Waiting:** until the agreement is signed, the order can't move on and purchase orders can't be raised. It can still be picked up or cancelled.
- **Signed:** the services are added to the customer as *pending*. If nobody has picked the order up, the onboarding team is alerted then; otherwise the person handling it is emailed.
- **Cancelled:** cancelling the agreement releases the order. The onboarding team is alerted (or the person handling it, if someone has picked it up), unless another unsigned agreement for the same quote is still holding it.
- **No agreement:** if none could be made (for example there's no template), the team is alerted when the quote is accepted and the order isn't held.

## Adding users

Under **Admin → Users → + New user**, enter their name, email and role. Leave **Email them a welcome email with a temporary password** ticked.
- **The welcome email:** they get an email with a sign-in link and a temporary password, which stops working after 7 days.
- **First sign-in:** before they can do anything else, they must choose their own password.
- **If the email can't be sent:** you're shown the temporary password once, to pass on yourself.
- **Later on:** the Users list has **Send new password** (or **Resend welcome email** if they haven't signed in yet) to email a fresh temporary password.

**When someone leaves:** use **Disable…** on the Users list.
- **Effect:** they're signed out straight away and can't sign in.
- **Their work:** you can hand their open tickets, customers, opportunities and orders to someone else in the same step.
- **History:** their name stays on everything they did.
- **Finding them:** disabled users move to the **Disabled** tab, where they can be enabled again.
- **Deleting:** only possible for users who have never signed in, such as one added by mistake.

## Limiting the CRM to your office (IP addresses)

**Settings → Security → Only allow the CRM to be used from these IP addresses** limits who can use the CRM by location.
- **The list:** one IP address or range per line, e.g. `81.2.69.0/24`, with notes after a `#`.
- **Who it affects:** anyone signing in from elsewhere is refused, and anyone already signed in who moves somewhere else is signed out.
- **People who work remotely:** tick **Can use the CRM from any location** on their user.
- **What isn't affected:** customer quote and order pages, contract signing pages, and links from Xero and GoCardless.
- **Lock-out protection:** the CRM won't save a list that doesn't include the address you're using.
- **Emergency override:** if you're ever locked out, add `'ip_allowlist_off' => true,` to config.php.

## Branding

**Settings → Branding** is where you upload your logo and choose a brand colour.
- **Logo:** PNG or JPG, up to 2 MB. Transparent backgrounds work.
- **Quote PDFs:** the logo goes at the top left in place of your company name. The bar along the top and the headings use your colour.
- **Customer pages:** the logo also shows on the quote and order tracking pages customers open from their emails.
- **Hosting:** logos are turned into PDF images by the CRM itself, so nothing extra is needed on your hosting.

## One-off charges

Use **One-off** for charges like installation, or hardware bought outright.
- **Products:** a product's billing cycle can be **One-off**. Its term is set to **One-off (no term)** and it counts as £0 a month. On a quote it goes in as a one-off charge.
- **Quote lines:** any quote line can have the term **One-off (no term)**. It's set automatically when a line has a one-off cost but no monthly price. The one-off cost is counted once in the contract value.
- **Signed contracts:** a one-off line doesn't become a pending service.

## Products and purchase orders

**Products** has its own menu section: **Products & tariffs** (what you sell) and **Supplier prices** (what each supplier charges you).
- **Supplier column:** the Products & tariffs list shows how many suppliers each product has. The **No supplier** list shows products that can't be bought in on a purchase order yet.
- **Raising a purchase order:** pick items from the supplier's price list or from your own products & tariffs.
- **Products new to a supplier:** when you pick a product the supplier doesn't have a price for yet, it's added to their price list when you save, linked to the product. Customer orders for that product then raise purchase orders with them. If they're the product's only supplier, their price becomes its cost price.

## Customers that are also suppliers or dealers

One company has one record with tabs: **Overview**, **Customer**, **Supplier** (when it supplies you) and **Dealer** (when it's a dealer).
- **From a customer:** edit it and tick **This company is also a supplier**. A supplier record is made from its details, or an existing supplier with the same name is linked. Untick to unlink; the supplier and its purchase orders are kept.
- **From a supplier:** use **Also a customer** or **Also a dealer** on its page, or pick the **Customer / dealer record** when editing it.
- **Shared details:** the name, address and phone are shared, so changing them on either side updates both. Supplier-only details (orders email, account number with them, portal, payment terms) stay on the Supplier tab.
- **Finding them:** the **Also suppliers** list on Customers shows every company that's both. Existing suppliers with the same name (or the same Xero contact) as a customer are linked when you update.

## Dealers

Edit a customer and tick **This customer is a dealer** (optionally set a commission %). For any other customer, choose the **Dealer** and the **Relationship**:
- **Referred by the dealer:** you bill the customer directly.
- **Billed via the dealer:** the dealer pays. These customers don't count as "No Direct Debit".

Tick **Covered by the dealer's MSA** when the dealer's master services agreement covers this customer's services. Their contracts then use your *Service schedule under a dealer MSA* template, and `{{msa_reference}}` fills in the dealer's signed MSA. To create a dealer's MSA, open the dealer and use **New contract / MSA**.

The CRM won't let a customer be its own dealer, sit under a customer that isn't a dealer, or form a loop.

## Quotes and contracts

1. **Settings → Email:** set a "send from" address and, ideally, SMTP details (Microsoft 365, Google Workspace or your host). Use **Send me a test email** to check.
2. **Settings → Your company:** your details appear on quotes, emails and contracts.
3. **Contract templates:** upload a Word `.docx` for each service type (mobile, broadband, leased line and so on). Add a **General** template for anything else, and optionally a **Service schedule under a dealer MSA**. Start from **Download example template**. Use `{{merge_fields}}` (the full list is on that page) and put `{{services_table}}` on its own line. There's no need for signature boxes: customers sign online.

**How a sale flows:**

1. Open a customer → **New quote** and add lines from your products. Save, then **Email quote** to a contact (the dealer's contacts are offered too).
2. The customer clicks the link and sees a branded quote page. To accept, they type their name and email and tick to confirm; they can also decline with a reason. Email security scanners that "click" links can't accept a quote by accident, because accepting needs that deliberate form.
3. On acceptance you're emailed. The contract is generated (one document per template, each with only its own services) and emailed to the person who accepted to sign online. Both steps can be switched off under Settings → Quotes & contracts, and you can do them by hand from the quote.
4. When they sign, the contract is marked signed and the signature certificate saved. The services are added to the customer as pending.

## Signing agreements online

Contracts are signed with the CRM's own e-signature. No outside service or subscription is needed.

It's built so the steps Ofcom's General Conditions require before a contract happen in the right order, and the record proves it:
- **The Contract Information** is supplied before the customer is bound.
- **The Contract Summary** is supplied before the contract is made, and the customer's agreement comes after they've received it.

1. **Going ahead with a quote isn't the contract.** The customer ticks that they'd like to go ahead, and that the contract is only made when they sign the agreement. (If agreements aren't made automatically, accepting the quote is the agreement, and the wording says so.)
2. **The documents come first.** The signing email has the **Contract Summary** and the agreement attached, so the customer holds them before they can agree to anything. The fingerprint of each attached file is recorded.
3. **A fixed order on the signing page**, enforced by the CRM rather than just shown on the page:
   1. Read the Contract Summary, and tick to confirm receiving it.
   2. Read the agreement, which is only shown after step 1.
   3. Confirm their email with a 6-digit code. It lasts 15 minutes, allows 5 tries, and only 5 codes can be sent an hour.
   4. Type their name (and position), tick the statement, and **Sign**.
   They can also decline, with a reason.
4. **A timestamped record of every step:** sent (with what was attached), page opened, documents downloaded, Contract Summary confirmed, agreement shown, code sent and confirmed, signed, and copies sent. Each step has its IP address. It's shown on the contract page and printed on the **signature certificate** (PDF), with a SHA-256 fingerprint of every document. The signer and your company email both get the certificate and the documents.
5. **Afterwards:** the order moves to *Agreement signed*, and the person handling it and the sales team are told.

**Setting it up:**
- **Contract Summary template:** under Contract templates, upload one, choosing "Contract Summary". Start from **Example Contract Summary**, which is laid out under Ofcom's standard headings. Until one is uploaded, agreements for customers can't be prepared. Dealer MSAs don't need one.
- **Wording:** under Settings → Quotes & contracts, the wording customers tick (going ahead with a quote, confirming the Contract Summary, signing) can be replaced with your solicitor's.
- **Who gets a Contract Summary:** every customer (the default), or only those Ofcom protects: consumers, microenterprises, small businesses and not-for-profits. Set each customer's **Size** on their record; a customer with no size is treated as protected.

Unsigned agreements get a reminder every 3 days (up to 3 times) from the cron job. Change or switch this off under Settings → Quotes & contracts.

This is a "simple electronic signature", which is valid for business contracts in the UK under the Electronic Communications Act 2000 and UK eIDAS. The fingerprints on the certificate show whether a document has changed since it was signed: run `sha256sum` (or `Get-FileHash` on Windows) on the Word file and compare.

Staff can also **Record acceptance** for quotes agreed by phone, **Revise** a sent quote (the old link stops working), and send reminders or cancel contracts.

Generated contracts and templates are stored in `storage/`, which the web can't access. **Include this folder in your backups.**


**When a quote is accepted** the customer is emailed a confirmation with a PDF of the quote attached. The PDF ends with an acceptance record: who accepted, their email, the date and time, their IP address and browser, the statement they ticked, and a SHA-256 fingerprint of the quote's lines and terms. A copy is filed in the customer's Files. If a member of staff records the acceptance instead, they choose whether the confirmation is sent, and the record says it was recorded by them. Any quote can be downloaded as a PDF from its page, and **Resend confirmation** sends it again.

## Products in Xero

Products & tariffs can be created in Xero as **items**, so they can be picked on invoices.

1. At Admin → Xero → Products, tick **Send products to Xero**. Optionally also tick **send automatically whenever it's created or saved**, and enter your sales account code, purchases account code and VAT tax type (e.g. `200`, `310`, `OUTPUT2`).
2. Press **Reconnect** and approve the extra permission (Xero's `accounting.settings` scope, used for items).
3. Send products:
   - from a product's page: **Send to Xero** or **Update in Xero**
   - from the product list: tick several (or the box at the top for the whole page), then **Send selected to Xero**
   - from Admin → Xero: **Send all products now**

The SKU becomes the Xero item code (Xero allows up to 30 characters), the name its name (first 50 characters), the sale price its sales price and the cost price its purchase price. Each product's **sales and purchases nominal codes** become the item's accounts (the codes on the Xero page are defaults for new products). Press **Load nominal codes from Xero** to have product forms offer your chart of accounts and reject codes that don't exist. The description notes the billing cycle. Sending again updates the same item. The product list shows whether each product is in Xero, has changed since it was sent, or had a problem, with tabs to find them. Xero's reason is shown on the product.

## Giacom: broadband availability and orders

**Set up** (Admin → Giacom): enter the API username and password Giacom gave you (and client ID if you have one), your broadband realm (for usernames like `acc10001-1@yourisp.net`) and default care level. The login is tested with Giacom before it's saved, and the password is stored encrypted. The server's PHP needs the `dom` and `simplexml` extensions (standard on almost all hosting).

**Permissions:** "Run broadband availability checks" (admin, manager, staff, sales and support by default) and "Place and cancel broadband orders" (admin and manager). Change them on Roles & permissions.

**Checking:** on a customer's page (Broadband orders → **Check broadband**) or a site's page, enter the postcode, pick the address from Giacom's list, and optionally the existing phone number. The results show the exchange, what's on the line (e.g. "use a migrate order"), and each product with its speed, estimated speeds, care levels and earliest date. Filter the results by supplier (BT Wholesale, Sky, Vodafone, CityFibre…), technology (SOADSL, SOGEA, FTTP, FTTC…) and minimum speed. The address's UPRN is looked up so CityFibre FTTP is included where available. Every check is saved on the customer and in the audit trail.

**Ordering:** press **Order** next to a product. The CRM asks Giacom for the install dates it can actually offer for that product at that address and shows the earliest; pick it, another offered date, or enter your own. The dates depend on the engineer visit type (change it and press **Show dates for this**). The chosen appointment is booked with Giacom's `amend_order` straight after the order is placed, and can be changed later from the order's page. Choose a new provide or a migrate (take-over), the required-by date, care level, engineer visit, broadband username and password, and the contact at the address (filled in from the site or main contact). The order goes to Giacom's `provide` or `migrate` call, the Giacom order number is saved, and a **pending** service is added to the customer (at the site, if you checked from one), optionally linked to one of your products for its price.

**Reference and IP addresses:** the **Order reference** you type is sent to Giacom as the order's reference; leave it blank to use the customer's account number. Choose **Dynamic IP**, **1 static IP** or a routed **block of 4, 8 or 16** static IPs. A block is requested from Giacom (`change_ips`) as soon as the order is placed. If Giacom refuses, the order still stands and **Request the IP block** on the order's page asks again.

**Customer confirmation:** tick *Email the customer an order confirmation* (on by default) to email the customer, in your company's name, the product, address, install date and their **setup details**: broadband username, password and IP type (static addresses follow once the line is live). The password is stored encrypted so the email can be sent again from the order's page; staff who can place orders can see it there too. Dealer orders send the same setup details to the dealer instead.

**Logins and IP addresses on services:** each service has a **Login & IP** card on its page with the username, the password (stored encrypted, shown on request) and the IP address(es); the customer's *Services & lines* card lists them too. Broadband orders fill these in when placed. Static addresses are only allocated when the line goes live, so the confirmation email says they'll follow, and when Giacom completes the order the CRM fetches the live IP (and login) with `service_details`. Staff can change the stored password (change it with the supplier too) and **Email username, password and IP** to the customer, e.g. once it's live.

**Tracking:** the hourly cron job (`cron/sync.php`) and **Check for updates** on the Broadband orders page fetch status changes from Giacom. Each order's page shows its history and has **Refresh from Giacom**. When Giacom completes an order its service becomes **active**; a cancelled order's pending service is marked ceased. People who can place orders can also ask Giacom to cancel one in progress (Giacom confirms whether it could).

## aBILLity billing

Customers, products and services are set up in the CRM and sent to aBILLity (Giacom's billing platform) for billing. New customers are created in Xero at the same time.

**Setting up:**
1. Under **Admin → aBILLity**, enter the system name, username and password Giacom gave you for the API, then press **Save and test**. The password is stored encrypted.
2. **Customers you already had:** those created before you connected aren't sent automatically, since they're probably already in aBILLity. Use **Send all active customers** (or **Set up in aBILLity** on a customer). Any already in aBILLity with the CRM account number as their account reference are linked rather than duplicated. To link one under a different reference, enter its aBILLity company and site IDs on the customer.
3. **Services you already had** aren't sent automatically either, since they're presumably already billed. Send one with **Send to aBILLity** on the service if needed.

**What happens:**
- **Customers:** when a customer becomes active (or their first service is sent), it's created in aBILLity and Xero together.
  - In aBILLity: a company and site, with the account number as the account reference. The address, phone and accounts contact are added, and invoices go to the accounts contact's email.
  - In Xero: a contact with the same account number.
  - Later changes to the customer or their accounts contact are sent to aBILLity.
- **Products:** saving a product creates or updates a service charge type in aBILLity, with its price, cost, billing cycle and sales nominal code. Weekly and bi-annual products are sent as monthly.
- **Services:** adding a service creates a service charge on the customer, plus a one-off charge for any setup fee. This covers services added by hand, from a signed agreement, or by a Giacom order.
  - **While pending:** the start date is provisional, 30 days ahead by default (you can change this in the settings), so nothing is billed early.
  - **When it goes live** (set to active with its start date, or Giacom completes the order): the real start date and number are sent.
  - **When it's ceased:** billing ends that day.
- **Problems:** anything that can't be sent is listed under Admin → aBILLity and retried by the cron job. It's also shown on the customer, product or service.
- **Ceased before billing started:** if a service is cancelled before it goes live, or ceased before its start date, the CRM can't remove the charge through the API. It's flagged, so remove that charge in aBILLity.

## Billing diary

**Customers → Billing diary** lists every change made to a service, month by month, so the billing can be checked against it.
- **What's recorded:** a change is noted whichever way it was made: editing a service, a signed agreement adding services, a Giacom order completing or being cancelled, a customer being closed, or a service being deleted. The changes recorded are:
  - new services
  - going live
  - price and setup-fee changes
  - product, number, start-date and term changes
  - suspensions and reactivations
  - ceases and cancellations
  - deletions
- **Each entry shows** what changed (from → to), the effect on monthly billing (e.g. +£30.00 when a service goes live, −£35.00 when one is ceased), any one-off setup charge, the effective date, who made the change, and whether it's in aBILLity.
- **Checking:** work through **To check**, and tick each change with **Check** (or tick several and press **Mark selected as checked**, with an optional note such as the invoice it was matched to). Who checked it and when is kept; **Undo** reverses a tick.
- **Totals:** the month's net change in monthly billing and its one-off charges are shown at the top. Filter by type of change or by customer, and use **Export CSV** for a spreadsheet.
- **Starting point:** the diary starts from when this update is installed; earlier changes are in each service's history.
- **Who sees it:** anyone with the "See balances, credit and Direct Debit status" permission.

## Ticket groups and the queue

- **Groups** (Admin → Ticket groups): Sales, Faults, Billing and General are created for you. For each group choose which ticket categories go to it (e.g. Fault and Porting → Faults), its members, and optionally a shared email address for new-ticket alerts (otherwise each member is emailed, when email is set up). Staff can be in several groups; you can also tick groups on each user's page.
- **New tickets** go to the group chosen on the ticket, or the group for its category, and wait **unassigned** in that group's queue. A ticket that has no group goes to the person who logged it, as before.
- **Ticket queue** (in the menu, with a count): everything waiting in your groups, oldest first, with how long each has waited and its SLA. **Take next ticket** gives you the one waiting longest; **Pick up** takes a particular one. Once someone has it, it leaves everyone else's queue; two people can't take the same ticket.
- **Tickets nobody picks up:** each group has a time limit (30 minutes by default; set a default and per-group limits on Admin → Ticket groups, 0 = off). When a ticket waits longer, people whose role can "Get alerts about tickets nobody has picked up" (admin by default) are emailed, along with the group's optional alert address, and see a warning at the top of the CRM until it's picked up. The ticket is marked "too long" in the queue and gets a note. Each ticket is alerted once. The check runs every minute while anyone is using the CRM, and on every cron run.
- **Who sees what:** staff see tickets in their own groups, tickets assigned to them and ungrouped tickets. Roles with "See tickets in every group" (admin and manager by default) see all.

## Customers: head office, sites and contacts

- **Head office:** address, main phone and company email are on the customer form.
- **Main contact and accounts contact:** filled in on the customer form too. Tick "Send invoices and statements to the main contact", or untick it and enter a separate accounts contact. Both are saved as ordinary contacts marked **Main** and **Accounts**, so they appear on tickets, quotes and so on. Ticking "Main contact" or "Accounts contact" on a contact moves the role to them.
- **Address book:** add sites from the customer page (**+ Add site**). Give each site its own contact, or leave it blank to use the head office main contact. When adding a service, choose its **Installation site**.
- **Telling Xero:** once Xero is connected, the customer page has **Send accounts contact to Xero**. It sets the Xero contact's email (and name) so Xero sends invoices and statements there. To do this automatically whenever the accounts contact changes, switch it on at Admin → Xero → Invoice emails, then press **Reconnect**. Xero will ask to approve permission to update contacts. This is the only thing the CRM ever changes in Xero.

## Service alerts and marketing emails

**Preferences (per contact):** *Service alerts* are on by default; they cover faults, maintenance and outages. *Marketing* is off until you record permission: tick email / phone / text / post, pick topics, and say how permission was given (e.g. existing customer soft opt-in, verbally, web form). The date is recorded and changes are in the audit trail. Set the topics under Settings → Marketing.

**Sending** (Marketing → Alerts & marketing, needs the "send alerts and marketing" permission):
1. Choose **Service alert** or **Marketing**.
2. Choose who gets it: live services of a type, carrier or product; site postcodes (e.g. `LS1, LS2` for a local outage, using the installation site or head office); customer status and type; a dealer and their customers. Starting from a customer's page ("Send service alert") limits it to that customer.
   - For alerts, pick who at each customer: the main contact plus the contact at any affected site (the default), the main contact only, or everyone who gets alerts.
   - For marketing, pick a topic. Only contacts who opted in to email marketing are included.
3. Write the message. Personalise with `{{first_name}}` `{{name}}` `{{company}}` `{{account_number}}`, and use `{{services}}` to list each customer's affected services.
4. **Save and check recipients.** You'll see every recipient, any matching customers nobody will hear from (no contact with an email address), and a preview. **Send me a test**, then **Send**.

Emails go out in batches (50 by default; see Settings → Marketing) while the page is open, and the cron job carries on with the rest. Every email has an unsubscribe link and one-click unsubscribe headers (`List-Unsubscribe`, which Gmail and Yahoo require for bulk senders). Unsubscribing only stops that kind of email: marketing or alerts.

**Mailchimp (optional):** Admin → Mailchimp. Paste an API key (Mailchimp → Profile → Extras → API keys) and choose your audience. Marketing emails can then be sent **through Mailchimp**: the CRM adds the recipients to your audience as subscribed (someone who unsubscribed in Mailchimp is never re-subscribed), puts them in a segment, then creates and sends a Mailchimp campaign. Opens and clicks are in Mailchimp's reports. Unsubscribes in Mailchimp are copied back to the CRM before each send and by the cron job. `{{services}}` can't be used through Mailchimp.

**Service alerts through Mailchimp:** Mailchimp's rules don't allow service messages to be sent as marketing campaigns. Its paid **Transactional** add-on (formerly Mandrill) is designed for them. To use it for all email the CRM sends, choose *Mailchimp Transactional* under Settings → Email and paste its API key.

## Dealer (partner) portal

Dealers can sign in to their own portal to check broadband availability for their customers and send orders for you to approve. **Dealers never see the wholesale supplier's name**: products appear as your products at the dealer's price, orders by your reference (`DO-000123`), and any supplier message is reworded first. The portal's pages, emails and script are tested for this.

**Setting it up**
1. In cPanel → *Domains*, create a subdomain (e.g. `partners.yourcompany.co.uk`) with the same document root as the CRM (its `public` folder), then enter it under **Settings → Dealer portal**. Without a subdomain the portal works at `…/portal.php` on the CRM's address. The staff CRM's IP allow-list doesn't apply to the portal.
2. On each broadband product dealers can order (**Products & tariffs**), fill in the **Dealer price**, optional **Dealer setup fee**, and **Supplier product IDs**: the supplier's product IDs it covers, as shown on an availability check. Only products with both are offered.
3. On the dealer's page (**Dealer** tab → *Partner portal*), give their staff access. Each is emailed a temporary password and chooses their own at first sign-in. You can send a new password or remove access there. Five wrong passwords lock the account for 15 minutes.

**Contracts.** The dealer contracts with you directly, as any customer would:
- They must have signed their **master terms** (a dealer MSA, sent from *New contract / MSA* on their page) before they can send orders. They can sign in and check availability before that.
- Every order has its **own agreement with the dealer** (Contract Summary first where required), made from your contract templates at the dealer price and emailed straight away to the dealer user who placed it. They sign it online, from the email or from the order on the portal.

**Orders.** Dealers add their own customers (created under the dealer, billed via the dealer), check an address, choose a product, the engineer visit and the install date, and send the order. It appears under **Sales → Dealer orders**. Once the agreement is signed the team is emailed. **Approve and place order** places it with the supplier exactly like a staff broadband order (login made from the settings under Admin → Giacom, the chosen appointment booked if it's still free), and the dealer is emailed. **Turn down** emails the dealer your reason. Dealers can withdraw an order until it's approved. Turning an order down or withdrawing it cancels its unsigned agreement.

## Customer portal

Customers can sign in to see **their own account**. It's read-only apart from their own portal password:
- **Overview:** company details, contacts, sites, and counts of live services, orders in progress, open tickets and agreements to sign.
- **Services:** each line and service with its status, monthly price, contract end and **setup details**: username, password (shown on request) and IP address(es) once live.
- **Orders:** broadband orders with their progress and install date, and other orders with a link to their tracking page.
- **Agreements:** signed agreements and their documents to download, and any waiting to be signed.
- **Support tickets:** their tickets and where each one is up to.

Nothing on it names the wholesale supplier.

**Setting it up:** point a subdomain (e.g. `myaccount.yourcompany.co.uk`) at the CRM's `public` folder and enter it under **Settings → Customer portal**, or use `…/account.php` on the CRM's address. On a customer's **Customer** tab, the *Customer portal* card gives people access: they're emailed a temporary password, changed when they first sign in. Five wrong passwords lock the account for 15 minutes, and you can send a new password or remove access there.

## Orders and onboarding

Accepting a quote (online or recorded by staff) creates an **order** (ORD-000001…) and emails the **onboarding team**: the *Onboarding* group under Admin → Ticket groups (change which group in Settings → Orders). Add the people who process orders to that group; if it has a shared email address, alerts go there instead.

Orders waiting to be picked up are counted next to **Orders** in the menu. Whoever picks one up moves it through the steps: **Quotation accepted → Order processing → Order confirmed → Order completed** (or Cancelled). At each step the customer is emailed a progress update with a message (a default for each step is set in Settings → Orders and can be changed each time), unless you untick it. Internal notes stay private. Customers can follow their order on a tracking page linked from every update and from the acceptance confirmation.

The order page links to everything needed to fulfil it: the contract, broadband checks and orders (Giacom), purchase orders and services. Signing the contract is noted on the order's timeline. Quotes accepted before orders existed have a **Create order** button.

## Purchase orders from orders, and supplier invoices

**Purchase orders from an order.** On an order, the *Purchase orders* section works out what to buy: each product's preferred supplier price (or the cheapest active one), grouped into one purchase order per supplier. Tick the suppliers and press **Raise purchase orders** to create them, linked to the order and emailed to each supplier's orders email. The same can happen automatically when you move the order to *Order processing*. Suppliers set to be ordered through their portal or an integration (Giacom is set this way) are left out, as are custom quote lines with no product; the order lists what wasn't put on a purchase order and why. Each purchase order shows its order, and the order shows every purchase order with its status and invoice.

**Supplier invoices** (under Purchasing) works like Hubdoc: upload invoices (PDFs or photos, several at once, or from a purchase order's page) and each is read and matched to its purchase order. It warns when:

- there's no PO number on the invoice, or it isn't one of ours
- the PO was raised with a different supplier, was cancelled, or was never sent
- the amount before VAT differs from the PO by more than the allowed difference (Settings, default £1.00)
- the PO has already been invoiced, or the invoice is a duplicate (same number from the same supplier, or the same file)

Invoices that need checking are counted in the menu, and the people who can raise purchase orders (or an address set in Settings) are emailed. Correct anything by hand and it re-matches; then **Approve to pay** or **Mark as disputed**. Suppliers ordered without POs (like Giacom) aren't warned about missing PO numbers.

**Bills in Xero.** Under Admin → Xero → *Supplier bills*, switch on sending approved supplier invoices to Xero as bills (then press Reconnect to grant the permission to create invoices and attachments). Approving an invoice then creates the bill on the supplier's linked Xero contact (or Xero matches or adds one by name), with the supplier's invoice number, the PO number as the reference, the dates, and the uploaded invoice attached. Lines come from the purchase order when the amounts agree (using each product's purchases nominal code), otherwise from the invoice. Choose whether bills arrive as Draft, Awaiting approval or Awaiting payment. **Send/Update in Xero** on the invoice re-sends it; problems Xero reports are shown on the invoice.

**Reading invoices.** The built-in reader handles PDFs made by accounting and billing systems (it reads the text in the PDF). Scanned invoices and phone photos have no text, so for those switch on **Claude** under Settings → Supplier invoices and add an Anthropic API key (console.anthropic.com). Claude reads any layout and also picks up the invoice's lines; each invoice costs a few pence. If Claude can't be reached, the built-in reader is used instead.

## Documents

**Documents** (under Sales) is a library of spec sheets, brochures and the like, in folders. People whose role can "Upload and organise documents" (admins and managers by default) add folders and upload files; everyone can view and download them. When you email a quote, tick any library documents (or the customer's own files) to attach them; up to 15 MB in total.

Each customer and supplier also has a **Files** card for documents kept against them (signed order forms, contracts, price lists). Files are stored privately in `storage/documents` and only served to signed-in users. Uploads are limited by your host's PHP settings (`upload_max_filesize` and `post_max_size`); raise them in cPanel's *MultiPHP INI Editor* if larger files are refused.

## Suppliers and purchase orders

**Suppliers** (under Purchasing) records who you buy from, their contacts and your account number, their **products and cost prices**, **purchase orders** and **files**. Link a supplier's product to one of your products and tick *Preferred supplier*: that price becomes the product's cost price and follows any price changes (converted to the product's billing cycle).

- **Price files**: on a supplier, *Import price file* reads a CSV or Excel (.xlsx) price list, lets you say which columns hold the code, description, cost and setup cost (remembered for next time), and shows every price change before anything is saved.
- **Purchase orders**: raise one from a supplier, pick lines from their products, then email it to them (or mark it as ordered through their portal) and mark it received when it arrives.
- **Xero**: *Bring in from Xero* on the Suppliers list (or switch it on for every sync under Admin → Xero) adds the contacts Xero marks as suppliers, linking any with the same name instead of duplicating them.

Permissions: *See suppliers* (staff, support, finance), *Add and edit suppliers* and *Raise purchase orders* (admins, managers, finance).

## Tests

```bash
php tests/run.php
```

The tests run against a throwaway `<db_name>_test` database, which is created and dropped automatically. The DB user needs `CREATE`/`DROP` rights for it.

## Project layout

```
resources/css/     stylesheet source (Tailwind + TailAdmin design tokens)
public/            web root: small entry files (index.php, install.php, …) that check the server,
                   then load the app from public/app/ (not web-accessible); assets/
src/
  bootstrap.php    config + includes
  db.php           PDO helpers
  auth.php         sessions, login
  permissions.php  roles, permissions and the Roles page
  approvals.php    close/delete requests and approvals
  ticket_groups.php ticket groups, queue and pick-up
  campaigns.php    service alerts and marketing: audiences, sending, unsubscribes
  mailchimp.php    Mailchimp Marketing API
  abillity.php     aBILLity billing: customers, products and services sent from the CRM
  billing_diary.php  billing diary: every service change, to check the month's billing
  giacom.php       Giacom comms API: availability checks, orders and tracking
  entities.php     field definitions + business rules (SLA, contract dates, numbering…)
  repository.php   generic validation, CRUD, listing, formatting
  controllers.php  page handlers
  xero.php         Xero OAuth, API client and balance sync
  gocardless.php   GoCardless mandates, setup links and sync
  quotes.php       quotes: totals, sending, acceptance
  contracts.php    contracts: templates, generation, signing
  esign.php        built-in e-signature: signing links, email codes, certificates
  docx.php         Word template merge
  mailer.php       email (PHP mail, SMTP or Mailchimp Transactional)
  http.php         shared HTTP client
  matching.php     unambiguous customer matching (used by both integrations)
  settings.php     key/value settings stored in the database
  installer.php    shared install logic (CLI + web)
templates/         PHP view templates
install/           schema.sql, migrations.php (upgrades), installer, demo data
cron/              scheduled jobs (sync.php: Xero, GoCardless, contracts awaiting signature, Mailchimp unsubscribes, Giacom orders, queued emails)
storage/           uploaded templates and generated/signed contracts (created automatically; not web-accessible)
tests/             integration tests, with local stand-ins for Xero, GoCardless, aBILLity, Mailchimp, Giacom and an SMTP server
.htaccess, index.php  protection + redirect for installs inside public_html
```

### Adding a field

Most screens are driven by the definitions in `src/entities.php`. To add a field:

1. Add the column to `install/schema.sql`. For an existing install, also run `ALTER TABLE`.
2. Add one line to that entity's `fields` array.

The form, validation, detail view and CSV export pick it up automatically. To show it on the list page, add it to `list`.
