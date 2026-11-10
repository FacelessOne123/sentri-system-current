# SenTri
### A Community-Driven Web-Based Safety Incident Reporting & Geospatial Monitoring System

---

## Project Structure

```
sentri-system/
│
├── index.php # Landing / welcome page
├── login.php # Community portal sign-in (community accounts only)
├── signup.php # Community registration (community accounts only)
├── logout.php # Session destroy (returns user to their portal's sign-in page)
├── dashboard.php # Main community feed, map, report form
├── admin.php # Admin control panel - renders its own sign-in form when logged out;
│ # deliberately NOT linked from any other page
├── forgot_password.php # Password reset request
├── reset_password.php # Password reset (via token)
├── verify_email.php # Email verification handler + resend endpoint
├── install.php # One-time DB installer - delete after use
│
├── gov/
│   ├── index.php             # Government portal homepage (barangay / LGU / first responder)
│   ├── login.php             # Government portal sign-in; no self-registration
│   ├── forgot_password.php   # Government password reset request
│   ├── reset_password.php    # Government password reset token handler
│   └── logout.php            # Government realm session logout
│
├── config/
│ ├── db.php # Database connection (MySQLi, socket + TCP fallback)
│ ├── email.php # SMTP credentials + APP_URL override (see note below)
│ ├── .htaccess # Blocks direct web access to config/
│ ├── csrf.php # CSRF token generation and validation
│
├── core/
│ ├── SenTriMailer.php # Minimal Gmail SMTP mailer - dynamic APP_URL, no Composer
│ └── login_handler.php # Shared sign-in POST handler (lockout, role whitelist, logs)
│
├── api/
│ ├── reports.php # Reports CRUD, voting, profile, GPS, image upload, audit log;
│ │ # admin user management incl. admin_create_official (provisioning)
│ ├── contacts.php # Emergency contacts CRUD + notification dispatch
│ ├── geocode_proxy.php # Server-side Nominatim proxy (CORS-safe)
│ ├── address_terms.php # Barangay-scoped address suggestions (public map data)
│ └── security.php # Security Monitor endpoint (failed logins, audit events)
│
├── portal/
│ ├── community.php # Community member portal
│ ├── barangay.php # Barangay official portal
│ ├── lgu.php # LGU portal
│ ├── responder.php # First responder portal
│ └── _report_table.php # Shared report table partial
│
├── uploads/
│ ├── .htaccess # Blocks PHP execution inside uploads/
│ └── reports/ # Uploaded incident photos (runtime, git-ignored)
│
│
└── sql/
 ├── sentri.sql # Full fresh-install schema
 └── migrations/
 ├── 001_map.sql # Geo columns (lat/lng/radius)
 ├── 002_email_verification.sql # Email token columns
 ├── 003_profile.sql # Avatar colour, GPS columns
 ├── 004_report_images.sql # Photo upload table
 ├── 005_emergency_contacts.sql # LGU/Hospital/Traffic contacts
 ├── 006_roles.sql # Expanded role ENUM + community columns
 ├── 007_force_password_reset.sql # Force password change on provisioned accounts
 ├── 008_guide_seen.sql # First-login guide video seen flag
 ├── 009_barangay_lock.sql # Anti-abuse barangay change lock timestamp
 ├── 010_resident_address.sql # Resident home address column
 ├── 011_barangay_address_terms.sql # Barangay-scoped address suggestion dictionary
 ├── 012_gps_fix_metadata.sql # GPS fix accuracy/timestamp/source metadata
 └── 013_reports_custom_type.sql # Free-text incident type for the Other category
```

---

## Quick Install

### Requirements
- PHP 8.0+
- MySQL 8.0
- Apache or Nginx with PHP-FPM
- Local stack: XAMPP / Laragon / MAMP

### Steps

1. Copy the project folder into your web server root 
 (e.g. `htdocs/sentri-system/` for XAMPP)

2. Configure `config/email.php`:
 - Set your Gmail App Password credentials (see instructions inside the file)
 - Set `APP_URL` **only if** you want to pin a specific address (see note below)

3. Run the installer in your browser:
 ```
 http://localhost/sentri-system/install.php
 ```
 This creates the `sentri` database and all tables automatically, including
 all community columns and the full role ENUM.

4. The installer **automatically locks itself** after a successful run via . You do not need to delete - it will refuse to run again. To re-run setup on a fresh DB, delete first.

5. Access the system:
 - Community: `http://localhost/sentri-system/`
 - Government portal (barangay / LGU / first responder): `http://localhost/sentri-system/gov/` (or `gov/login.php`)
 - Admin panel (renders its own sign-in; not linked from any other page): `http://localhost/sentri-system/admin.php`
 - Default admin: set via `install.php` on first run - change immediately after setup
 ⚠️ Change the admin password immediately after first login.

---

## Email Verification Links - Dynamic APP_URL

Email links (account verification, password reset) are built using `APP_URL`
defined in `config/email.php`.

**The default value is `http://localhost` which is a placeholder only.**

### How auto-detection works

`core/SenTriMailer.php` checks whether `APP_URL` is still the default placeholder
at runtime. If it is, `sentri_detect_app_url()` reads the live HTTP request to
build the correct base URL automatically:

| Scenario | Auto-detected link |
|---|---|
| XAMPP default | `http://localhost/sentri-system` |
| Custom port | `http://localhost:8080/sentri-system` |
| Live domain (root) | `https://sentri.example.com` |
| Live domain (subdir) | `https://example.com/sentri` |
| Reverse proxy | Reads `X-Forwarded-Proto` / `X-Forwarded-Host` |

### Loopback hosts always follow the live request

When the browser is on a loopback host (`localhost`, `127.0.0.1`, `[::1]`,
`*.localhost`), the detected live origin wins **even if `APP_URL` is pinned to
a different local port**. This means switching between `http://localhost`,
`http://localhost:4446`, `http://127.0.0.1:8081`, etc. needs no config change.

Production domains are unaffected: if `APP_URL` is a non-loopback URL, it stays
authoritative (so a reverse proxy without forwarding headers still gets the
right public URL).

`debug.php` renders old outbox messages on the current host too: it rebuilds
each link from its `verify_email.php` / `reset_password.php` tail, so links
stored with a dead port or stale subdirectory still work.

### When to set APP_URL manually

You only need to set `APP_URL` explicitly when:
- Your server sits behind a proxy that does **not** set forwarding headers
- You run background/CLI scripts that send emails (no live `$_SERVER` available)
- You want to enforce a canonical URL regardless of how PHP detects the host

Example in `config/email.php`:
```php
// Production domain - remove this line to use auto-detection
define('APP_URL', 'https://sentri.example.com');
```

Leave it as `http://localhost` to keep auto-detection active everywhere.

---

## Features

### Community (Registered Users)
- Submit geo-tagged incident reports with title, category, severity status, and location pin
- Report location comes only from the dropped pin - no default or saved profile location; the server tags the barangay from authoritative Imus geometry
- My Profile keeps the resident Barangay + Home Address for identity/verification (home address is only shown to barangay/LGU/responder staff, never in the public feed), and barangay changes are rate-limited by an anti-abuse lock
- Barangay-scoped address suggestions: the residence fields in signup, My Profile and the "Complete your resident profile" popup autocomplete streets, subdivisions, phases, blocks, puroks and sitios for the selected barangay. Suggestions are advisory only - residents can still type a free-form house number/street
- Attach up to 3 photos per report (JPG, PNG, WEBP - max 5 MB each)
- Adjustable affected radius slider (50 m to 3 km)
- Upvote / downvote reports for community credibility scoring
- Interactive Leaflet.js map with colour-coded severity overlays
- In-app GTA-style heading-up navigation to report locations (no Google Maps redirect), with auto-arrival detection
- Filter feed by status, category, or keyword
- Dark / light mode toggle
- GPS chip that always says where the position came from: **GPS Live**, **Last Known** or **Registered Address** - stale positions are never presented as live

### Role-Based Portals
- **Community** - report submission, feed browsing, map view
- **Barangay Official** - barangay-scoped report management
- **LGU** - city/municipal-level report oversight
- **First Responder** - BFP / PNP / EMS response view (with responder type)
- **Admin** - full moderation, user management, audit log, security monitor

Each role signs in on its own dedicated page:
- Community members: `login.php` (self-registration via `signup.php`, email verification required)
- Government officials: `gov/login.php` (no self-registration)
- Administrators: `admin.php` (renders its own sign-in form; the URL is not linked from any other page)

Government accounts (Barangay, LGU, First Responder) are **provisioned by the
system administrator** from the admin panel (Users tab → Create Official
Account). They are created pre-approved with a temporary password and are
required to set their own password at first sign-in; credentials are emailed
to the official. Legacy self-registered official accounts still go through
administrator approval (Users tab → Approve).

### Admin Panel
- Moderate reports (archive / restore / delete)
- Manage user accounts and roles; approve pending official accounts; provision new official accounts (Users tab → Create Official Account)
- Emergency Contacts directory - LGU offices, hospitals, traffic, police, fire
- Reports Audit Log - full create/edit/delete history
- Security Monitor tab - failed login tracking, suspicious activity

### Automatic Notifications
When a **Dangerous** report is submitted, `api/contacts.php` emails all active
emergency contacts whose city (and optionally barangay) matches the report location.

---

## Technology Stack

| Layer | Technology |
|--------------|-----------------------------------------|
| Backend | PHP 8.0+ (procedural + MySQLi) |
| Database | MySQL 8.0 (InnoDB, utf8mb4) |
| Frontend | Vanilla HTML5, CSS3, JavaScript ES6+ |
| Map Engine | Leaflet.js 1.9.4 + OpenStreetMap |
| Geocoding | Nominatim (server-proxied) |
| Icons | Font Awesome 6.5 |
| Typography | Google Fonts - Inter / Poppins |
| Auth | PHP native sessions + bcrypt (cost 12) |
| Email | Gmail SMTP via SenTriMailer (no Composer) |
| Web Server | Apache or Nginx + PHP-FPM |

---

## Database Tables

| Table | Purpose |
|------------------------|-------------------------------------------------|
| users | Accounts - roles: community / barangay / lgu / first_responder / admin |
| reports | Incident reports with geo fields |
| report_images | Photo attachments linked to reports |
| report_votes | Upvote / downvote records (1 per user/report) |
| emergency_contacts | LGU, hospital, traffic, police, fire directory |
| contact_notifications | Log of emails sent per report |
| barangay_address_terms | Barangay-scoped street/subdivision/phase/block/purok/sitio dictionary (address suggestions) |
| login_logs | Authentication audit trail |

### users table columns (current)
`id`, `first_name`, `last_name`, `email`, `password`, `role`, `phone_number`,
`org_name`, `position`, `barangay_name`, `municipality`, `responder_type`,
`is_approved`, `email_verified`, `verification_token`, `token_expires_at`,
`reset_token`, `reset_token_expires`, `created_at`, `gps_lat`, `gps_lng`,
`gps_accuracy`, `gps_at`, `gps_source`

---

## GPS location

Browsers only allow geolocation on **HTTPS** or **localhost**. When SenTri is
opened from another device on the LAN over plain `http://`, the browser blocks
GPS entirely and the app has no position. SenTri degrades gracefully instead of
failing silently.

### Location source chain

| Priority | Source | Chip shows | Precision |
|----------|--------|------------|-----------|
| 1 | Live browser geolocation | `GPS Live` (green) | 20-100 m |
| 2 | Last known position (device cache, else server copy) | `Last Known · 2h ago` (amber) | as recorded |
| 3 | Registered barangay centre | `Registered Address` (grey) | ~barangay |
| 4 | Nothing available | `GPS Off` (grey) | - |

**The browser already does WiFi + cell-tower positioning.** Chrome and Safari
fuse GPS satellites, nearby WiFi access points and cell towers internally and
hand the page one blended fix - that is why step 1 is accurate even on a
laptop with no GPS chip. HTTPS is simply the switch that lets the browser do
it. No website can read the raw WiFi/cell identifiers itself, so there is no
API that could add a second positioning method.

### What each source may and may not be used for

| Purpose | Live GPS | Last known | Registered address |
|---------|----------|------------|--------------------|
| Report pin (where the incident is) | yes | yes, with confirmation | **never** |
| Proximity alerts ("nearby reports") | yes | yes | **never** (false toasts) |
| Copy My GPS (emergency context) | yes | yes, labelled with age | address text only, never coordinates |
| "My barangay" identity context | - | - | yes |

A resident who is commuting is somewhere else entirely, so the registered
address is never presented as "where you are". It is identity context, the
same thing the barangay shown on the profile is.

### Reporting from another barangay

A report's location is **always the dropped pin**, never the reporter's
position or registered address. A resident may therefore report an incident in
any barangay, including one they are not registered in - including a sighting
from a commute that they file later from home. The server tags the report's
barangay from the pin's coordinates against the authoritative Imus geometry, so
the correct barangay officials receive it. The modal shows which barangay the
pin currently routes to, so a misplaced pin is visible before submitting.

### Resilience details

- Every good fix is cached in `localStorage` (`sentri:lastFix:v1`, 7-day
  window) **scoped per account**, and mirrored to the server, so the position
  survives a dropped connection and follows the user to another browser.
- The geolocation watcher **reconnects with backoff** instead of dying on the
  first error, and re-arms when the tab becomes visible again.
- "Use My Location" offers the last known position when a live fix fails, and
  always leaves the pin draggable.
- "Copy My GPS" labels the copied text with its source and age, so a stale
  position is never handed to a responder as if it were live.
- Report pins are never auto-placed from a stale position - the resident always
  confirms it and can drag the pin.

### Why there is no IP-based fallback

Public-IP geolocation is not a usable fallback for SenTri. Carriers and
business connections commonly sit behind **CGNAT**, so the address that gets
geolocated belongs to the ISP's aggregation point rather than the user - a
residential subscriber in Dasmariñas can resolve to Sorsogon, Laguna or
Batangas. SenTri is also Imus-only, so an out-of-city result would be
rejected by the geo-fence or, worse, silently mislead someone in an
emergency. A wrong location is worse than no location, so the chain stops at
the registered address instead.

### HTTPS is the real unlock

Step 1 of the chain (live GPS, including WiFi and cell-tower positioning) only
works on a secure origin. Serving SenTri over HTTPS turns on accurate
positioning for every device at once, with no extra hardware, no second
device and no user setup. A minimal Caddy setup:

```
# /etc/caddy/Caddyfile
sentri.example.com {
    reverse_proxy 127.0.0.1:80
}
```

Caddy provisions and renews the certificate automatically. An nginx +
certbot arrangement works equally well.

### Database

Migration `sql/migrations/012_gps_fix_metadata.sql` adds the `gps_accuracy`,
`gps_at` and `gps_source` columns to `users`:

```bash
mysql -uroot sentri < sql/migrations/012_gps_fix_metadata.sql
```

---


## Address-term dictionary (barangay suggestions)

The residence fields (signup, My Profile and the "Complete your resident profile" popup)
autocomplete against `barangay_address_terms`, a MySQL dictionary of streets, roads,
subdivisions, phases, blocks, puroks and sitios scoped to one of the 95 Imus barangays.
Suggestions are advisory only: residents can always type a house number, street or
purok that is not in the list.

**Data sources**
- OpenStreetMap snapshot of Imus (named highways, residential landuse, place nodes
  and `addr:place`/`addr:street` tags), assigned to a barangay by point-in-polygon.
- The existing street dataset (`assets/geo/imus-streets.json`) for guaranteed coverage.
- An optional LGU override CSV for terms OSM does not carry (puroks, sitios, blocks).

**Rebuild and seed** (after changing the snapshot or overrides):
```bash
php scripts/build-address-terms.php --fetch --check   # fetch OSM + write assets/geo/imus-address-terms.json
php scripts/seed-address-terms.php                    # upsert into MySQL (idempotent)
php scripts/seed-address-terms.php --fresh            # clear the table first
```
Run `sql/migrations/011_barangay_address_terms.sql` (or a fresh `install.php`) before seeding.
The public API falls back to the committed JSON snapshot if the table is missing or empty.

**LGU overrides** - edit `assets/geo/imus-address-overrides.csv`:
```csv
barangay,term,type,parent
Bucandala I,Purok 3,purok,
Anabu II-C,Vallejo Place Phase 4,phase,Vallejo Place
```
`type` is one of `street`, `road`, `subdivision`, `phase`, `block`, `purok`, `sitio`,
`landmark`, `other`. Overrides always win over OSM rows with the same key.

**Attribution** - address suggestions are derived from
© OpenStreetMap contributors, available under the
[Open Database License (ODbL)](https://opendatacommons.org/licenses/odbl/).
The attribution is shown in the suggestion dropdown and must be kept in any
redistribution of the dataset.

---

## Security Notes

- `config/` is protected by `.htaccess` (direct web access denied)
- `uploads/` blocks PHP execution via `.htaccess`
- All DB queries use prepared statements with bound parameters
- Passwords hashed with `password_hash()` at bcrypt cost 12
- Role-based session checks on every protected page and API endpoint
- Rate limiting: 10 reports per user per hour
- Failed login attempts tracked; Security Monitor tab surfaces suspicious IPs

---

## Changelog

| Date | Change |
|------------|--------|
| 2026-11-10 | **feat(consultation):** NOV 9 consultation fixes. The community login page now uses a taller split layout - the tutorial video and the sign-in card stretch to the same height and fill the available space - and the tutorial video can be **minimized** (remembered per browser, with a "Show tutorial video" restore pill); the community tutorial modal gained the same minimize-to-pill behaviour. The report form's **Other** category now shows a free-text "Please specify" field, persisted to the new `reports.custom_type` column (migration `013`) and shown in the report detail and category tag. The community feed and My Reports gained a **time-range filter** (Last 15 min / hour / 24 hours / 7 days / 30 days) and cards now show relative timestamps ("15m ago") with the exact time on hover. Includes the NOV 8 carry-over items already delivered: pin/location polish and responder + pop-up alerts |
| 2026-11-09 | **feat(gps):** Honest location fallback chain - `GPS Live` → `Last Known · 2h ago` → `Registered Address` → `GPS Off`. The browser's own geolocation already fuses GPS, WiFi access points and cell towers internally (HTTPS is the switch that enables it), so there is no second positioning API to add; instead the app now degrades gracefully instead of failing silently: every good fix is cached per account in `localStorage` (`sentri:lastFix:v1`, 7-day window) and mirrored to the server (`users.gps_lat/gps_lng/gps_accuracy/gps_at/gps_source`), so the position survives a dropped connection and follows the user to another browser; the geolocation watcher reconnects with backoff instead of dying on the first error; "Use My Location" offers the last known position; "Copy My GPS" labels the copied text with its source and age - and when only the registered address exists it copies the address text, never invented coordinates. The registered address is identity context only: it is never used as a report pin, for proximity alerts, or as emergency coordinates, so a resident who commutes and files a report elsewhere is never mislocated. A report's location remains the dropped pin alone, and the report modal now shows which barangay the pin routes to. IP-based lookup was deliberately excluded: CGNAT makes carrier IPs resolve to the wrong province (a Dasmariñas subscriber appearing in Sorsogon/Laguna/Batangas), and a wrong location is worse than no location. A short-lived GPS-bridge experiment (`api/gps_bridge.php`, `tools/gps-bridge/`) was removed before merge as too complex for residents |
| 2026-11-09 | **feat(address):** Barangay-scoped address suggestion box for the residence fields (signup, My Profile and the "Complete your resident profile" login popup). A new `barangay_address_terms` table (migration `011`) stores streets, roads, subdivisions, phases, blocks, puroks and sitios for all 95 Imus barangays; the dataset is built from an OpenStreetMap snapshot plus an optional LGU override CSV (`assets/geo/imus-address-overrides.csv`). New public endpoint `api/address_terms.php?barangay=...` (validated against the 95 canonical barangays, no PII) with a committed JSON fallback (`assets/geo/imus-address-terms.json`) so the UI works before seeding. Shared `assets/js/sentri-address-autocomplete.js` renders grouped, badged, keyboard-accessible suggestions and always allows free-form addresses. Build/seed with `php scripts/build-address-terms.php --fetch` and `php scripts/seed-address-terms.php` |
| 2026-11-08 | **feat(community):** Community reports are now located solely by the dropped pin - the report modal no longer prefills or auto-drops a pin from a stored location; users may pin anywhere inside Imus and the server tags the report's barangay from the authoritative 95-barangay geometry, rejecting pins that do not resolve to an Imus barangay. My Profile keeps the resident Barangay + Home Address for identity/verification (the home address is scoped to barangay/LGU/responder staff and never returned in the public feed), and the barangay change lock was reintroduced at **1 minute for testing** (`SENTRI_BRGY_LOCK_MINUTES` in `config/geo.php`, restore to `43200`/30 days for production). Also added the responder-first resolve lock: while a first responder is assigned, barangay/LGU cannot resolve the report, and resolve updates are race-safe. Removed the `_maketest.php` session-spoofing backdoor |
| 2026-11-08 | **feat(alerts):** Responder and LGU portals now poll `poll_report_alerts` every 15-20 s and show right-side toasts for new dangerous reports (BFP/fire = dangerous fire only, PNP = dangerous crime only, other responders = any dangerous) and LGU escalations. Fixed status-upgrade toasts for previously unseen caution reports and added update-watermark polling for responders so caution → dangerous upgrades are announced. Kept the tracked `.env` placeholders and `.env.local` documentation after merging PR #13 |
| 2026-11-06 | **feat(barangay):** Barangay Incident Map is now locked to the Imus perimeter (`SentriGeo.lockToImus` + hard max-bounds), rejects pins outside Imus, and draws community-style labels on all 95 barangay outlines (`labels: true`) with pin-overlap avoidance. Old outbox verification/reset links are rebuilt on the current host, and loopback hosts always follow the live request origin even when `APP_URL` is pinned to a stale local port |
| 2026-11-06 | **fix(debug):** Debug console verification/reset links now follow the host you are actually browsing (`sentri_app_url()`) instead of the `APP_URL` placeholder or a stale port baked into old outbox messages (e.g. `127.0.0.1:4446`). Historical `.eml` links have their origin rewritten at render time, so they still work after switching to `http://localhost` (port 80). Added an "Effective base URL" row to the Environment panel |
| 2026-11-05 | **fix(barangay):** Strictly scope barangay portal reports and actions to the assigned barangay territory - fixed query in `portal/barangay.php` and `api/reports.php` which previously queried `(r.barangay = ? OR u.barangay_name = ?)`, leaking incidents to other barangay portals whenever a resident's home address differed from the incident location (e.g. Palico IV officials receiving Pasong Buaya II reports). Scoped stats, dashboard lists, exports, and map markers strictly to `r.barangay`. Added server-side authorization guards to `update_report_status`, `resolve_report`, `escalate_report`, and `get_lifecycle` ensuring barangay officials can only access and update reports within their own assigned jurisdiction |
| 2026-11-05 | **feat(community):** Mandatory barangay assignment for community reporting + portal gating - unassigned members are guided to assign their barangay in My Profile (via a dismissible welcome modal and persistent banner). Unassigned members can view the Incidents Map and Emergency Contacts, but the Community Feed, My Reports, and report creation are locked. Added an anti-abuse lock on barangay changes (`sql/migrations/009_barangay_lock.sql`) and validated barangay selections against the 95 canonical Imus barangays. **Partially superseded on 2026-11-08 by pin-only report location** - the profile residence and barangay lock were retained (lock shortened to 1 minute for testing) |
| 2026-11-05 | **feat(report):** Auto-detecting suggestions and bidirectional address-map synchronization for community create-report - added smart autocomplete for City, Barangay (all 95 Imus barangays), and specific Street/location. Street suggestions are dynamically scoped to the selected barangay to prevent cluttering; entering/selecting a manual address automatically drops a pin and centers the map; conversely, dropping or dragging a pin automatically reverse-geocodes and populates the street, city, and barangay fields |
| 2026-11-05 | **feat(map):** Highlight barangays and limit community report pin drop map to Imus only - integrated `SentriGeo.addBarangayOverlay` into `pickerMap` with progressive labels, interactive hover states, and dynamic highlighting of the active/auto-detected barangay. Applied `SentriGeo.lockToImus(pickerMap, {fit: true})` and strictly restricted pin drops, marker drags, and report submissions to valid coordinates within the 95 Imus City barangay perimeters |
| 2026-11-05 | **feat(gov):** Dedicated government portal homepage (`gov/index.php`) and home navigation isolation - added dedicated landing page for the government portal (`/gov/`) highlighting the 3 official branches (Barangay, LGU, First Responders), infrastructure capabilities, and official provisioning notices. Fixed the "Home" and brand links in `gov/login.php`, `gov/forgot_password.php`, and `gov/reset_password.php` to point to `index.php` within `gov/` instead of `../index.php`, preventing foreign community realm redirects to citizen portal accounts when logged in |
| 2026-11-05 | **fix(auth):** Fix 404 on community portal logout and isolate government portal links - updated `portal/community.php` to link to `../logout.php` instead of relative `logout.php` (which resolved to nonexistent `/portal/logout.php`), and added a fallback forwarder at `portal/logout.php`. Stripped all community portal cross-links from `gov/login.php` (top navigation link and bottom "Community member? Sign in" card row) and fixed its "Forgot Password?" card link to stay within `gov/forgot_password.php` |
| 2026-11-05 | **feat(login):** Community sign-in page two-column layout with autoplay video and no media controls (`to_analyze_implement_scribbled.png`) - the "How SenTri Works" tutorial video sits on the left with `autoplay`, `muted`, `loop`, `playsinline` and media controls stripped away, directly beside the sign-in card on the right. Clicking the video toggles audio mute/unmute so visitors can hear the narration if desired. Below 1000px the columns stack with the form retaining top priority. Dead modal markup and handlers removed |
| 2026-11-04 | **fix(map):** Barangay labels made readable - the name chips were faint at city zoom, too small up close, and the big barangays had the text off-centre. Names now sit on a white chip with a border and shadow instead of bare navy text on a light tile, and the type scales with the zoom (11px at z13 -> 14px at z17). The anchor is now the **pole of inaccessibility** of the barangay polygon (the point furthest from the boundary, i.e. the visual middle) instead of the geojson's stored `center` - that property sat up to 940 m from the true middle and left the name visibly off-centre in the largest barangays (Anabu I-G was only 7 m from its own boundary). Leaflet's GeometryUtil is not in the build, so `polylabel` is implemented in `assets/js/sentri-geo.js`; all 95 anchors are verified to fall inside their own polygon and to be deeper than the old centre. Names and report pins cluster in the same places, so labels are now **placed greedily**: the biggest barangays pick their spot first, then each name is nudged to the nearest free spot that clears the report pins and the names already placed - and a candidate is only accepted if it is still inside its own barangay, so a name never crosses a boundary |
| 2026-11-04 | **feat:** Barangay name labels on the community map - the Imus barangay boundaries are now labelled in place, anchored on the geojson's own `center` property (verified to fall inside the matching polygon for all 95 barangays, so a name always sits on its own area). Labels are drawn as non-interactive markers in the marker pane with `zIndexOffset` below the report pins, so they never intercept a click meant for the boundary underneath and never hide an incident; the boundary click still opens the "Brgy X - N reports here" popup. Because Imus has 95 barangays, many of them slivers in the Poblacion / Bayan Luma core, labelling every one at the city-wide zoom turned the map into a wall of text - so the names appear progressively with the zoom: the largest barangays at z12-13 (9 names), then 24 at z14, 45 at z15, 80 at z16 and all 95 by z17. `SentriGeo.addBarangayOverlay()` takes the new `labels` option (object form: `onlyName`, `opacity`, `fontSize`, `allAt`) |
| 2026-11-04 | **feat:** Community incident map isolated to Imus City - every Leaflet map on the community portal (Incidents Map, the overview map toggle, the "Drop a Pin on Map" picker and the per-report mini map) is now locked to the Imus perimeter set. `SentriGeo.lockToImus()` applies `maxBounds` (the viewport can no longer be panned outside the city) plus a `minZoom` cap derived from the container size (the user can no longer zoom out past the city), and the maps open centred on Imus instead of the old Manila default. Only reports falling inside one of the 95 Imus barangay polygons are pinned - an exact polygon test rather than the bounding box, so sea area and neighbouring municipalities are excluded - and the counter now reads e.g. "19 pinned reports in Imus · 5 outside the city", so nothing silently disappears. The location picker flags a pin dropped outside Imus ("outside Imus"), so a report is never filed against an area the map cannot show. New helpers in `assets/js/sentri-geo.js`: `imusBounds()`, `imusLatLngBounds()`, `isInImus()`, `geoReady()`, `lockToImus()`, `applyImusZoomLimit()` |
| 2026-11-04 | **feat:** First-login guide video popup - the first time a community member signs in, a one-time "How to Use SenTri" tutorial modal plays before they land on their portal. `api/guide.php` flips the new `users.guide_seen` flag atomically (0 -> 1), so the popup plays exactly once per account even across a double-click or a second tab, and returns `{"show":false}` on every later sign-in. The endpoint is realm-aware (it reads the identity from the community realm session via `sentri_identity()`, not a raw `$_SESSION` read), CSRF-protected, and never blocks sign-in - a database or media error degrades to a normal redirect. Schema: migration `sql/migrations/008_guide_seen.sql`, with the column also added to `sql/sentri.sql` and `install.php` so fresh installs get it too |
| 2026-11-04 | **fix/security:** Realm separation enforced - government and admin flows never share pages with community. New `gov/reset_password.php` + `gov/forgot_password.php` (officials-only, gov realm) and `admin/reset_password.php`; `gov/logout.php` / `admin/logout.php` replace realm-parameterized `logout.php` links (community page destroys only `sentri-comm`). Email link in official reset mail now role-aware (routes to the account's own realm). Separated reset engine `core/password_reset_engine.php` validates tokens before the identity redirect and auto-forwards foreign-realm tokens to their own portal page — fixes officials-redirected-to-community-portal during forced first sign-in while a citizen session is active. `(require_login(role-aware): anonymous gov portal visits route to gov sign-in)`. Homepage cleanup: direct Government Portal links removed from `index.php` (nav, mobile nav, CTA band), `login.php` (nav, gov sign-in row, footer) and `signup.php` (nav, official row) — gov access lives only on its own pages now |
| 2026-11-04 | **fix:** GPS acquisition delay diagnosed and fixed in `SentriGeo.getUserLocation` — `maximumAge` was 3000ms on every call, forcing a cold GPS lock each time (30-60s perceived hang). Added an acquisition ladder: instant cached-fix probe (≤60s old, ≤±250m resolves in ms) → high-accuracy acquire (cache ≤15s, fixes worse than ±1500m ignored instead of resolved) → 60s high-accuracy watch → coarse last-resort resolve instead of an error at ~76s. `watchUserLocation` timeout raised 20s→60s so cold-start tracking no longer dies silently. Verified headless: fast-path resolves in 202ms; no-hardware case errors cleanly at 76s with the map-pin fallback message |
| 2026-10-27 | **feat:** Portal separation - government sign-in moved to `gov/login.php` (barangay/LGU/responder, no self-registration); community sign-in/registration simplified to community-only; admin panel renders its own unlinked sign-in; officials provisioned by admin (`admin_create_official`) with emailed credentials and forced password change on first sign-in (migration 007); shared auth logic extracted to `core/login_handler.php`; danger-alert emails now link to the government portal instead of admin |
| 2026-06-11 | feat: Live GTA-style heading-up in-app navigation (Leaflet/OSM) replacing Google Maps redirects; auto-arrival detection |
| 2026-06-11 | feat: Auto-arrival resolves report without flipping status to Safe; added Resolved/Unresolved badge |
| 2026-06-11 | fix: Duplicate responder marker on repeat navigation |
| 2026-06-10 | fix: responder.php and lgu.php errors (prepared statement handling, fatal error on line 44) |
| 2026-06-09 | feat: Barangay portal toast notifications, state-aware modal buttons, escalate button + escalated badge in report table |
| 2026-06-09 | fix: Removed ob_start/ob_end_flush causing 500 on action requests; added shutdown error handler; audit-log resolve_report |
| 2026-06-08 | fix: Resolved vulnerability assessment crash (normalized scan shape, guarded statusMap lookups) |
| 2026-06-08 | fix: dashboard.php / community role routing redirect to portal/community.php (backward compat) |
| 2026-06-03 | **fix:** `SenTriMailer` now auto-detects `APP_URL` dynamically - email links work on any port, domain, or subdirectory without touching config |
| 2026-06-03 | **fix:** `install.php` creates full `users` schema with all community columns and correct role ENUM |
| 2026-06-02 | **fix:** Auto-migrations moved before POST handler in `signup.php`; free result sets to fix `bind_param` error |
| 2026-05-30 | **feat:** Security Monitor tab added to `admin.php` + `api/security.php` endpoint |
| 2026-05-30 | **fix:** `signup.php` extra `'s'` parameter bug in `bind_param` |
| 2026-05-30 | **feat:** `getFailedAttemptsCount()` helper added to `login.php` |
| 2026-05-29 | **feat:** `sql/migrations/006_roles.sql` - expanded role ENUM + community columns |
| 2026-05-29 | **feat:** Role-based portal pages (`portal/community.php`, `barangay.php`, `lgu.php`, `responder.php`) |
| 2026-05-29 | **feat:** Reports Audit Log tab in `admin.php`; audit trail on delete/restore |
| 2026-04-27 | **fix:** Map bleeding over sidebar (Leaflet stacking context isolation) |
| 2026-04-26 | **fix:** Double-close bug in `post_report`; block `.php` disguised uploads; admin guard on `notify_report` |
| 2026-03-28 | **fix:** Forgot-password link no longer expires immediately on send |
| 2026-03-19 | **feat:** Email verification system added |

---

## Roadmap

- [ ] Real-time proximity alerts via polling or WebSocket
- [ ] Optional TOTP-based two-factor authentication (Google Authenticator)
- [x] CSRF protection on all endpoints
- [ ] Report editing and commenting
- [ ] In-app notification system
- [ ] Data export (CSV/PDF)
- [ ] Report analytics and trend charts

### Deferred (budget-dependent)
- [ ] SMS notification support (Semaphore / Vonage PH) - requires paid API
- [ ] Auto-call integration for Dangerous reports (Twilio) - requires paid API
- [ ] Mobile app wrapper

---

## Local development (Linux Mint / Debian / XAMPP)

```
sudo bash scripts/setup-linux-mint.sh     # packages + DB + .env + seed + serve :8080
BASE=http://localhost:8080 bash scripts/smoke-test.sh   # 32-check full-stack battery
php scripts/seed-demo.php                 # re-seed demo users + [SMOKE] incidents
php scripts/build-address-terms.php --check   # verify the address snapshot (95 barangays)
php scripts/seed-address-terms.php            # sync address suggestions into MySQL
```

- **Offline emails**: `APP_DEBUG=1` in `.env` diverts all mail to `logs/mail/*.eml` —
  signup + verification work with NO internet; open `debug.php` (standalone dev
  console, invisible in production) for one-click verification links, the realm-
  session inspector, data snapshots and geo-asset integrity checks.
- Demo accounts: `community@ / barangay@ / responder@ / lgu@demo.ph` → `Demo@1234`,
  `admin@sentri.ph` → `Admin@1234` (seeded by `scripts/seed-demo.php`).
