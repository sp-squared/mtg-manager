# mtg-manager

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

| Layer      | Technology                        |
|------------|-----------------------------------|
| Backend    | PHP 7.4+ (mbstring not required)  |
| Database   | MySQL 8.0 / MariaDB               |
| Frontend   | Bootstrap 5, Bootstrap Icons      |
| Card Data  | Scryfall Bulk Data API            |
| Server     | Apache (tested on Windows/XAMPP)  |

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
| `login_attempts`     | Rate limit audit log with bypass tracking   |

### Notable Design Decisions
- `user_collection.added_at` — timestamps when a card was first added, enabling "Recently Added" sort
- `cards.imported_at` — set on first Scryfall import only, enabling "Newest Import" sort; preserved across re-imports
- `daily_cards` uses a recursive CTE to generate a full date series and backfill any gaps between the earliest record and `CURDATE()`
- All migrations use `information_schema.COLUMNS` checks wrapped in temporary stored procedures for compatibility with MySQL versions that do not support `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`

---

## Setup

### Requirements
- PHP 7.4 or higher
- MySQL 8.0+ (recursive CTE support required)
- Apache with `mod_rewrite` enabled
- A `cacert.pem` bundle for SSL verification (download from [curl.se](https://curl.se/ca/cacert.pem))

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/mtg-manager.git
   cd mtg-manager
   ```

2. **Create the database config** — copy the template and fill in your credentials:
   ```bash
   cp db_config.php.template db_config.php
   ```
   Edit `db_config.php` (store outside web root in production):
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'mtg_collection');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'mtg_database');
   ```

3. **Run the schema** in MySQL Workbench or CLI:
   ```bash
   mysql -u root -p < mtg_schema.sql
   ```

4. **Enable the MySQL event scheduler** for nightly gap-fill (add to `my.ini`):
   ```ini
   [mysqld]
   event_scheduler=ON
   ```

5. **Import card data** — log in as admin (user ID 1) and navigate to `import_scryfall.php`. The importer streams Scryfall bulk data with low memory usage and can be re-run safely; existing cards are updated without overwriting their original `imported_at` timestamp.

6. **Register your account** — the first registered user (ID 1) is automatically the admin.

---

## Configuration Notes

### Password Policy
- Minimum 8 characters, maximum 32 characters
- Enforced client-side (`maxlength="32"`) and server-side (`preg_match_all` for Unicode-safe character count)
- 32-character cap ensures passwords always fall within bcrypt's 72-byte processing limit, even with multibyte characters

### SSL Certificate (Windows)
If `import_scryfall.php` cannot reach the Scryfall API, add your `cacert.pem` path to `php.ini`:
```ini
curl.cainfo = "C:/path/to/cacert.pem"
```

### Admin Account
User ID 1 is the admin. There is no separate admin registration — simply register first. The admin panel is accessible via the dashboard and provides rate limit management and the Scryfall importer.

---

## File Structure

```
mtg-manager/
├── db_config.php.template    # Copy and fill with your credentials
├── mtg_schema.sql            # Full schema + idempotent migrations
├── connect.php               # DB connection
├── functions.php             # isLoggedIn, isAdmin, requireCsrf, etc.
├── header.php                # Nav, session init, CSRF meta, fetch interceptor
├── footer.php
├── style.css
│
├── index.php                 # Login
├── portal.php                # Register
├── logout.php
├── dashboard.php             # COTD, stats, favorite decks
├── search.php                # Card search with filters
├── collection.php            # User collection
├── wishlist.php              # Wishlist
├── decks.php                 # Deck list
├── deck_editor.php           # Deck builder
├── profile.php               # Username, email, password change
│
├── import_scryfall.php       # Admin: bulk card importer
├── admin_unlock.php          # Admin: rate limit panel
│
├── add_to_collection_ajax.php
├── add_to_deck_ajax.php
├── add_to_deck.php
├── add_to_wishlist_ajax.php
├── admin_unlock_action.php
├── change_password.php
├── check_email.php
├── check_username.php
├── clear_deck.php
├── create_deck.php
├── deck_panels_partial.php
├── delete_deck.php
├── delete_export.php
├── do_import_deck.php
├── export_deck.php
├── import_deck.php
├── login.php
├── register.php
├── remove_from_collection.php
├── remove_from_deck.php
├── remove_from_wishlist.php
├── toggle_favorite.php
├── update_collection.php
├── update_deck_card.php
├── update_deck_details.php
├── update_email.php
├── update_username.php
├── update_wishlist.php
└── wishlist_partial.php
```

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
