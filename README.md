# ⚔️ MTG Manager
![screenshot](/mtg-manager/img/mtg_cards_medium.png)

Create a local account and start building your collection today!

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WOTC](https://img.shields.io/badge/WOTC-blue.svg)](https://company.wizards.com/en)
[![Scryfall](https://img.shields.io/badge/Scryfall-blue.svg)](https://scryfall.com/docs/faqs)
![Static Badge](https://img.shields.io/badge/Magic:%20The%20Gathering-8A2BE2)

A self-hosted Magic: The Gathering collection, deck, and wishlist manager built with PHP, MySQL, and Bootstrap. Designed for local area network (LAN) deployment — run it on your own machine and share it with friends.

Card data is sourced from the [Scryfall](https://scryfall.com) bulk data API. No third-party account or subscription required.

---

## 🌟 Key Features

### Collection Management
- Add cards to your personal collection with quantity tracking
- Foil quantity tracked separately
- Sort by recently added, name, CMC, rarity, set, or quantity
- Full-text search by card name, oracle text, keyword, or type line
- Search by Scryfall UUID for exact card lookup
- Color identity filter with colorless toggle (greys out colour options when selected)
- **Bulk Import** — paste a card list in MTGO/Arena format (`4 Lightning Bolt`, `4x Bolt`, or plain `Bolt`) to add cards in bulk; lines starting with `//` or `#` are treated as comments
- **Collection Value History** — daily snapshot of your total collection value recorded on each dashboard visit; displayed as a line chart on the dashboard once two or more days of data exist

### Deck Builder
- Create and manage multiple decks
- Add cards directly from search results to a deck in one step (bypasses collection ownership check for import-first workflows)
- Sideboard support
- Token card support — tokens are imported from Scryfall and tracked separately from the main deck count; displayed in their own panel in the Deck Summary
- Favorite up to 18 decks (pinned to dashboard)
- Deck import via shareable export codes
- Export snapshot stored as immutable JSON with import count tracking
- **Public Deck Pages** — every export code has a public read-only URL (`public_deck.php?code=MTG-XXXXXXXX`) viewable without an account; linked from the Profile export list
- **Missing Cards** — Deck Summary panel compares deck contents against your collection and lists every card you still need, with quantity shortfall, current price, and a one-click "Add to Wishlist" button

### Price Tracking
- Admin-run price updater pulls USD, USD Foil, EUR, EUR Foil, and MTGO Tix prices from Scryfall bulk data
- Current prices displayed on every card in Collection, Search, and Wishlist
- Daily snapshots stored in `card_price_history` — run the updater regularly to build price trends
- 30-day USD sparkline chart in the Prices tab of each card detail modal
- Collection value banner on Collection page and dashboard stat card (total USD value × quantity owned)
- Wishlist value banner showing total buy cost for all priced wishlist cards

### Wishlist
- Priority levels: Low / Medium / High
- Add from search results in one click
- Current market price shown per card
- Sort by priority, price ascending, or price descending
- **Price Alerts** — set a target USD price per card; the dashboard shows a notification banner the next time you log in if the price has dropped to or below your target

### Card of the Day
- Daily rotating card displayed on the dashboard
- Gap-fill via recursive MySQL CTE — missed days backfilled automatically on next login
- MySQL scheduled event fires nightly at midnight even with no active users
- Fully database-driven, no PHP date logic

### Recently Viewed
- Replaces "Recently Added" on the dashboard
- Tracks the last 8 cards opened in a modal across Search, Collection, and the dashboard
- Recorded via a fire-and-forget AJAX call on every card modal open; stored in `recently_viewed` with a per-user unique constraint so only the most recent visit time is kept per card

### Search
- Filter by name, type line, oracle text, keyword/ability, set, rarity, CMC range, color identity
- Color mode: Any / All / Exactly
- Sort by: Newest Import, Name, CMC ↑↓, Rarity, Set, Price ↑↓
- Clean URLs — empty parameters stripped from query string
- Scryfall UUID search for direct card lookup
- Default sort is Newest Import so freshly imported cards are immediately visible

### Admin
- Admin panel for rate limit management (user ID 1 is admin)
- Three-state login status: Locked / Logged in while locked / Unlocked
- Bypass event tracking preserved in audit log
- Scryfall bulk data importer (admin-only, streaming JSON parser, low memory footprint) — accessible via the **Import** navbar link
- Token cards included in the Scryfall import (art series, vanguard, scheme, and emblems are still skipped)
- Price updater (`admin/update_prices.php`) — streams the same bulk file, updates `card_prices` and appends a daily row to `card_price_history`

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

| Layer      | Technology                                        |
|------------|---------------------------------------------------|
| Backend    | PHP 7.4+ (mbstring not required)                  |
| Database   | MySQL 8.0+ (recursive CTE required)               |
| Frontend   | Bootstrap 5, Bootstrap Icons, Chart.js 4.4        |
| Card Data  | Scryfall Bulk Data API                            |
| Server     | Apache (tested on Windows / Apache 2.4)           |

---

## Database Schema

### Tables
| Table                | Purpose                                                        |
|----------------------|----------------------------------------------------------------|
| `player`             | User accounts with session token                               |
| `cards`              | Scryfall card data with import timestamp                       |
| `sets`               | Set metadata                                                   |
| `colors`             | W/U/B/R/G reference                                           |
| `card_colors`        | Card ↔ color junction                                         |
| `formats`            | Format names                                                   |
| `format_legalities`  | Card legality per format                                       |
| `user_collection`    | Cards owned per user with added timestamp                      |
| `wishlist`           | Wanted cards with priority                                     |
| `decks`              | Deck metadata with favorite flag                               |
| `deck_cards`         | Cards in each deck with sideboard flag                         |
| `deck_exports`       | Shareable immutable deck snapshots                             |
| `daily_cards`        | Card of the Day pile with display dates                        |
| `login_attempts`     | Rate limit audit log with bypass tracking                      |
| `card_prices`        | Latest USD/EUR/Tix prices per card, updated by price updater  |
| `card_price_history` | Daily price snapshots per card for trend tracking              |
| `recently_viewed`    | Per-user card view history (upserted on each modal open)       |
| `collection_value_history` | Daily total collection value snapshots per user          |
| `price_alerts`       | Per-user target prices; marked triggered when price is hit     |

### Notable Design Decisions
- `user_collection.added_at` — timestamps when a card was first added, enabling "Recently Added" sort
- `cards.imported_at` — set on first Scryfall import only, enabling "Newest Import" sort; preserved across re-imports via `IFNULL(imported_at, NOW())`
- `daily_cards` uses a recursive CTE to generate a full date series and backfill any gaps between the earliest record and `CURDATE()`
- `card_prices` is a `PRIMARY KEY` keyed on `card_id` — upserted on every price update run, always reflects the latest known price
- `card_price_history` uses a `UNIQUE KEY (card_id, recorded_date)` with `ON DUPLICATE KEY UPDATE` so running the updater multiple times in one day refreshes rather than duplicates today's snapshot
- Both price tables are created automatically on first run of `admin/update_prices.php` — no migration needed for existing installs
- `recently_viewed` uses a `UNIQUE KEY (user_id, card_id)` with `ON DUPLICATE KEY UPDATE viewed_at = NOW()` — one row per user/card pair, always reflects the most recent view time
- `collection_value_history` uses a `UNIQUE KEY (user_id, recorded_date)` with `ON DUPLICATE KEY UPDATE` — snapshot recorded on dashboard visit, refreshed if visited again the same day
- `price_alerts` uses a `UNIQUE KEY (user_id, card_id)` so setting a new target for the same card replaces the old one; `is_active` is set to 0 and `triggered_at` recorded when the condition is first met
- New tables (`recently_viewed`, `collection_value_history`, `price_alerts`) are created automatically via `CREATE TABLE IF NOT EXISTS` on first page load — no manual migration required
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
    ├── dashboard.php           # home — stat cards, COTD, recently viewed, value history chart, price alert banner
    ├── search.php
    ├── collection.php
    ├── wishlist.php
    ├── decks.php
    ├── deck_editor.php
    ├── profile.php
    ├── import_deck.php
    ├── bulk_import.php         # paste MTGO/Arena card list to add cards in bulk
    ├── public_deck.php         # public read-only deck view via export code (no login required)
    ├── price_alerts.php        # manage price drop alerts per card
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
    │   ├── card_price_history.php      ← price history + sparkline data for a card
    │   ├── card_autocomplete.php       ← card name search for price alerts
    │   ├── bulk_import_collection.php  ← parses and imports a pasted card list
    │   ├── record_view.php             ← upserts recently_viewed on card modal open
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
    │   ├── delete_account.php  ← wipes all user data and destroys session
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
        ├── update_prices.php
        └── admin_unlock.php
```

---

## Setup

### Requirements
- PHP 7.4 or higher
- MySQL 8.0+ (recursive CTE support required)
- Apache 2.4+ (tested on Windows)
- A `cacert.pem` bundle for SSL verification (download from [curl.se](https://curl.se/ca/cacert.pem))

### 🛠️ Installation Guide

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

8. **Load prices** — navigate to `http://localhost/mtg-manager/admin/update_prices.php` and click **Start Price Update**. This streams the same Scryfall bulk file and populates `card_prices` and `card_price_history`. Run it regularly (daily or weekly) to build price trend data for the sparkline charts. The price tables are created automatically on first run.

---

## 🔍 Configuration Notes

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
If `admin/import_scryfall.php` or `admin/update_prices.php` cannot reach the Scryfall API, add your `cacert.pem` path to `php.ini`:
```ini
curl.cainfo = "C:/path/to/cacert.pem"
```
The importer will attempt to auto-resolve common certificate paths and fall back gracefully with an error log entry rather than failing silently.

### Admin Account
User ID 1 is the admin. There is no separate admin registration — simply be the first to register. The admin panel (`admin/admin_unlock.php`) and importer (`admin/import_scryfall.php`) are linked in the nav bar and protected at the PHP level — non-admin users are redirected away.

---

## 📄 License

MIT License

Copyright (c) 2026 Colin Morris-Moncada

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

## Acknowledgements

Card data provided by [Scryfall](https://scryfall.com) under their [terms of service](https://scryfall.com/docs/terms). This project is not affiliated with or endorsed by Wizards of the Coast or Scryfall.
