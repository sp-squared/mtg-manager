# mtg-manager

![screenshot](/mtg-manager/img/logo.png)

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WOTC](https://img.shields.io/badge/WOTC-blue.svg)](https://company.wizards.com/en)
[![Scryfall](https://img.shields.io/badge/Scryfall-blue.svg)](https://scryfall.com/docs/faqs)
![Static Badge](https://img.shields.io/badge/Magic:%20The%20Gathering-8A2BE2)

A self-hosted Magic: The Gathering collection, deck, and wishlist manager built with PHP, MySQL, and Bootstrap. Designed for local area network (LAN) deployment — run it on your own machine and share it with friends.

Card data is sourced from the [Scryfall](https://scryfall.com) bulk data API. No third-party account or subscription required.

---

## Features

### Collection Management
- Add cards to your personal collection with quantity tracking
- Foil quantity tracked separately
- Sort by recently added, name, CMC, rarity, set, or quantity
- Full-text search by card name, oracle text, keyword, or type line
- Search by Scryfall UUID for exact card lookup
- Color identity filter with colorless toggle (greys out colour options when selected)

### Deck Builder
- Create and manage multiple decks
- Add cards directly from search results to a deck in one step (bypasses collection ownership check for import-first workflows)
- Sideboard support
- Favorite up to 18 decks (pinned to dashboard)
- Deck import via shareable export codes
- Export snapshot stored as immutable JSON with import count tracking

### Wishlist
- Priority levels: Low / Medium / High
- Add from search results in one click

### Card of the Day
- Daily rotating card displayed on the dashboard
- Gap-fill via recursive MySQL CTE — missed days backfilled automatically on next login
- MySQL scheduled event fires nightly at midnight even with no active users
- Fully database-driven, no PHP date logic

### Search
- Filter by name, type line, oracle text, keyword/ability, set, rarity, CMC range, color identity
- Color mode: Any / All / Exactly
- Sort by: Newest Import, Name, CMC ↑↓, Rarity, Set
- Clean URLs — empty parameters stripped from query string
- Scryfall UUID search for direct card lookup
- Default sort is Newest Import so freshly imported cards are immediately visible

### Admin
- Admin panel for rate limit management (user ID 1 is admin)
- Three-state login status: Locked / Logged in while locked / Unlocked
- Bypass event tracking preserved in audit log
- Scryfall bulk data importer (admin-only, streaming JSON parser, low memory footprint)

### Security
- bcrypt password hashing (32-character max enforced to stay within 72-byte bcrypt limit)
- Per-request CSRF tokens injected via fetch interceptor
- Single-session enforcement via database token
- Rate limiting: 5 attempts per minute triggers lockout, admin unlock available
- All write endpoints use `isLoggedIn()` + `requireCsrf()` + prepared statements
- AJAX endpoints use `ob_start()` / `ob_end_clean()` to prevent notice leakage into JSON responses
- Import transactions with rollback on failure
- Atomic favorite cap enforcement via subquery

---

## Tech Stack

| Layer      | Technology                              |
|------------|-----------------------------------------|
| Backend    | PHP 7.4+ (mbstring not required)        |
| Database   | MySQL 8.0+ (recursive CTE required)     |
| Frontend   | Bootstrap 5, Bootstrap Icons            |
| Card Data  | Scryfall Bulk Data API                  |
| Server     | Apache (tested on Windows / Apache 2.4) |

---

## Database Schema

### Tables
| Table                | Purpose                                      |
|----------------------|----------------------------------------------|
| `player`             | User accounts with session token             |
| `cards`              | Scryfall card data with import timestamp     |
| `sets`               | Set metadata                                 |
| `colors`             | W/U/B/R/G reference                          |
| `card_colors`        | Card ↔ color junction                        |
| `formats`            | Format names                                 |
| `format_legalities`  | Card legality per format                     |
| `user_collection`    | Cards owned per user with added timestamp    |
| `wishlist`           | Wanted cards with priority                   |
| `decks`              | Deck metadata with favorite flag             |
| `deck_cards`         | Cards in each deck with sideboard flag       |
| `deck_exports`       | Shareable immutable deck snapshots           |
| `daily_cards`        | Card of the Day pile with display dates      |
| `login_attempts`     | Rate limit audit log with bypass tracking    |

### Notable Design Decisions
- `user_collection.added_at` — timestamps when a card was first added, enabling "Recently Added" sort
- `cards.imported_at` — set on first Scryfall import only, enabling "Newest Import" sort; preserved across re-imports via `IFNULL(imported_at, NOW())`
- `daily_cards` uses a recursive CTE to generate a full date series and backfill any gaps between the earliest record and `CURDATE()`
- All migrations use `information_schema.COLUMNS` checks wrapped in temporary stored procedures for compatibility with MySQL versions that do not support `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`

---

## Repository Structure

```
mtg-manager-repo/               ← GitHub repo root
├── README.md
├── LICENSE
├── .gitignore
├── database/
│   └── mtg_schema.sql          ← run this once to set up your database
│
└── mtg-manager/                ← drop this entire folder into htdocs/
    ├── index.php               # login
    ├── portal.php              # register
    ├── dashboard.php
    ├── search.php
    ├── collection.php
    ├── wishlist.php
    ├── decks.php
    ├── deck_editor.php
    ├── profile.php
    ├── import_deck.php
    ├── style.css
    │
    ├── includes/               # shared PHP — not directly browser-accessible
    │   ├── db_config.template.php   ← copy to db_config.php and fill in credentials
    │   ├── db_config.php            ← gitignored, you create this
    │   ├── connect.php
    │   ├── functions.php
    │   ├── header.php
    │   └── footer.php
    │
    ├── ajax/                   # JSON endpoints called via fetch()
    │   ├── add_to_collection.php
    │   ├── add_to_deck.php
    │   ├── add_to_wishlist.php
    │   ├── admin_unlock_action.php
    │   ├── change_password.php
    │   ├── check_email.php
    │   ├── check_username.php
    │   ├── deck_panels_partial.php
    │   ├── delete_export.php
    │   ├── export_deck.php
    │   ├── remove_from_deck.php
    │   ├── remove_from_wishlist.php
    │   ├── toggle_favorite.php
    │   ├── update_deck_details.php
    │   ├── update_email.php
    │   ├── update_username.php
    │   ├── update_wishlist.php
    │   └── wishlist_partial.php
    │
    ├── actions/                # form POST handlers — redirect after processing
    │   ├── login.php
    │   ├── register.php
    │   ├── logout.php
    │   ├── add_to_deck.php
    │   ├── clear_deck.php
    │   ├── create_deck.php
    │   ├── delete_deck.php
    │   ├── do_import_deck.php
    │   ├── remove_from_collection.php
    │   ├── update_collection.php
    │   └── update_deck_card.php
    │
    └── admin/                  # admin-only pages (user ID 1 only)
        ├── import_scryfall.php
        └── admin_unlock.php
```

---

## Setup

### Requirements
- PHP 7.4 or higher
- MySQL 8.0+ (recursive CTE support required)
- Apache 2.4+ (tested on Windows)
- A `cacert.pem` bundle for SSL verification (download from [curl.se](https://curl.se/ca/cacert.pem))

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/sp-squared/mtg-manager.git
   ```

2. **Drop the app folder into your htdocs**
   ```
   Copy mtg-manager/ into C:\Apache24\htdocs\
   ```
   Your app will be available at `http://localhost/mtg-manager/`

3. **Create your database config** — copy the template and fill in your credentials:
   ```
   Copy: mtg-manager\includes\db_config.template.php
     To: mtg-manager\includes\db_config.php
   ```
   Edit `db_config.php`:
   ```php
   define('APP_BASE', '/mtg-manager'); // must match your htdocs folder name

   define('DB_HOST', 'localhost');
   define('DB_USER', 'mtg_collection');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'mtg_database');
   ```

4. **Run the schema** in MySQL Workbench or CLI:
   ```bash
   mysql -u root -p < database/mtg_schema.sql
   ```

5. **Enable the MySQL event scheduler** so the Card of the Day gap-fill runs at midnight even when no one is logged in. Add to `my.ini` (usually `C:\ProgramData\MySQL\MySQL Server 8.0\my.ini`):
   ```ini
   [mysqld]
   event_scheduler=ON
   ```
   Then restart MySQL:
   ```
   net stop MySQL80
   net start MySQL80
   ```

6. **Register your account** — the first registered user (ID 1) is automatically the admin.

7. **Import card data** — log in, then navigate to `http://localhost/mtg-manager/admin/import_scryfall.php`. The importer streams Scryfall bulk data with low memory usage and can be re-run safely. After importing, sort Search by "Newest Import" to immediately see what was added.

---

## Configuration Notes

### APP_BASE
`APP_BASE` is defined in `includes/db_config.php` and controls all absolute URL redirects and nav links throughout the app. It must match the subfolder name you dropped into htdocs. Set it once and nothing else needs changing.

| htdocs setup | APP_BASE value |
|---|---|
| `htdocs/mtg-manager/` → `http://localhost/mtg-manager/` | `'/mtg-manager'` |
| `htdocs/cards/` → `http://localhost/cards/` | `'/cards'` |
| Apache root points directly at the folder | `''` |

### Password Policy
- Minimum 8 characters, maximum 32 characters
- Enforced client-side (`maxlength="32"`) and server-side using `preg_match_all` for Unicode-safe character counting — no mbstring extension required
- 32-character cap ensures passwords always fall within bcrypt's 72-byte processing limit, even with multibyte characters such as emoji or CJK

### SSL Certificate (Windows)
If `admin/import_scryfall.php` cannot reach the Scryfall API, add your `cacert.pem` path to `php.ini`:
```ini
curl.cainfo = "C:/path/to/cacert.pem"
```
The importer will attempt to auto-resolve common certificate paths and fall back gracefully with an error log entry rather than failing silently.

### Admin Account
User ID 1 is the admin. There is no separate admin registration — simply be the first to register. The admin panel (`admin/admin_unlock.php`) and importer (`admin/import_scryfall.php`) are linked in the nav bar and protected at the PHP level — non-admin users are redirected away.

---

## License

MIT License

Copyright (c) 2026 Colin Morris-Moncada

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

## Acknowledgements

Card data provided by [Scryfall](https://scryfall.com) under their [terms of service](https://scryfall.com/docs/terms). This project is not affiliated with or endorsed by Wizards of the Coast or Scryfall.
