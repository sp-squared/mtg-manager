# MTG Manager

![screenshot](/mtg-manager/img/mtg_cards_medium.png)

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WOTC](https://img.shields.io/badge/WOTC-blue.svg)](https://company.wizards.com/en)
[![Scryfall](https://img.shields.io/badge/Scryfall-blue.svg)](https://scryfall.com/docs/faqs)
![Static Badge](https://img.shields.io/badge/Magic:%20The%20Gathering-8A2BE2)

A self-hosted Magic: The Gathering collection, deck, and wishlist manager built with PHP, MySQL, and Bootstrap. Designed for local area network (LAN) deployment — run it on your own machine and share it with friends.

Card data is sourced from the [Scryfall](https://scryfall.com) bulk data API. No third-party account or subscription required.

> For database schema, design decisions, and file structure see [ARCHITECTURE.md](ARCHITECTURE.md).

---

## 📸 Screenshots

*Application screenshots will be added during development*

### Create Account Page

![screenshot](/mtg-manager/img/dev001.png)

### Login Page

![screenshot](/mtg-manager/img/dev002.png)

### Dashboard

![screenshot](/mtg-manager/img/dev003.png)
![screenshot](/mtg-manager/img/dev013.png)
![screenshot](/mtg-manager/img/dev004.png)

### Card Search

![screenshot](/mtg-manager/img/dev005.png)

### Card Collection

![screenshot](/mtg-manager/img/dev006.png)

### My Decks Page

![screenshot](/mtg-manager/img/dev007.png)

### My Wishlist Page

![screenshot](/mtg-manager/img/dev014.png)

### Import Deck Page

![screenshot](/mtg-manager/img/dev008.png)

### Bulk Import Cards Page

![screenshot](/mtg-manager/img/dev009.png)

### Price Alerts Page

![screenshot](/mtg-manager/img/dev010.png)

### Profile Page

![screenshot](/mtg-manager/img/dev011.png)
![screenshot](/mtg-manager/img/dev012.png)

## Features

### Collection
- Quantity and foil quantity tracking per card
- Bulk import via pasted MTGO/Arena format (`4 Lightning Bolt`, `4x Bolt`, or plain `Bolt`); lines starting with `//` or `#` are skipped
- Sort by recently added, name, CMC, rarity, set, quantity, or price
- Collection value banner (total USD × quantity owned)
- Daily value snapshots with a line chart on the dashboard once two or more days of data exist

### Deck Builder
- Create and manage multiple decks with sideboard support
- Token card support — tokens tracked separately, displayed in their own Deck Summary panel
- Favorite up to 18 decks (pinned to dashboard)
- Missing Cards panel — compares deck contents against your collection, shows quantity shortfall, current price, and one-click "Add to Wishlist"
- Export decks as shareable codes (`MTG-XXXXXXXX`) with optional expiry (1d / 7d / 30d / never)
- Import any exported deck via code — preview before importing

### Public Deck Preview
- `public_deck.php` — read-only deck view, no login required
- Enter an export code directly on the page (same MTG- prefilled input as the import page); no URL editing needed
- Accessible via the **Preview Deck** navbar link for guests, or direct link from Profile for logged-in users
- "Log in to Import" prompt for guests viewing a shared deck

### Price Tracking
- Admin price updater pulls USD, USD Foil, EUR, EUR Foil, and MTGO Tix from Scryfall bulk data
- Current prices shown on every card in Collection, Search, and Wishlist
- 30-day USD sparkline chart in the Prices tab of each card detail modal
- Wishlist value banner showing total buy cost for all priced cards

### Price Alerts
- Set a target USD price per card from the Alerts page
- Dashboard shows a notification banner when any tracked card drops to or below your target
- Inline edit of target price per alert row; delete individual alerts

### Wishlist
- Priority levels: Low / Medium / High
- Add from search results in one click
- Sort by priority, price ascending, or price descending

### Search
- Filter by name, type line, oracle text, keyword/ability, set, rarity, CMC range, and color identity
- Color mode: Any / All / Exactly; colorless toggle greys out color options
- Sort by: Newest Import, Name, CMC, Rarity, Set, Price
- Clean URLs — empty parameters stripped from query string
- Scryfall UUID search for exact card lookup

### Card of the Day
- Daily rotating card on the dashboard
- Gap-fill via recursive MySQL CTE — missed days backfilled automatically on login
- MySQL scheduled event fires nightly at midnight with no active users required

### Recently Viewed
- Tracks the last 8 cards opened in a modal across Search, Collection, and the dashboard
- Fire-and-forget AJAX call on every modal open; upserted per user/card pair

### Life Counter (Mobile)
- Full-screen two-player life counter at `life_counter.php`
- Player 2 at the top (blue, rotated 180°) — readable from the other side of the table
- Player 1 at the bottom (red, normal orientation)
- ±1 and ±5 buttons per player; reset to 20 (Standard) or 40 (Commander)
- Always visible in the navbar as **Life Counter (Mobile)**

### Admin
- Scryfall bulk data importer — streaming JSON parser, low memory footprint, re-runnable safely
- Price updater — streams same bulk file, upserts `card_prices`, appends daily row to `card_price_history`
- Rate limit panel — lock/unlock user login; bypass events preserved in audit log
- User ID 1 is admin; no separate admin registration required

### Security
- bcrypt password hashing (32-character max to stay within bcrypt's 72-byte limit)
- Per-request CSRF tokens auto-injected into all POST `fetch()` calls via a header.php interceptor
- Single-session enforcement via database token
- Rate limiting: 5 attempts per minute triggers lockout; admin unlock available
- All write endpoints require `isLoggedIn()` + `requireCsrf()` + prepared statements

---

## Tech Stack

| Layer    | Technology                                 |
|----------|--------------------------------------------|
| Backend  | PHP 7.4+ (mbstring not required)           |
| Database | MySQL 8.0+ (recursive CTE required)        |
| Frontend | Bootstrap 5, Bootstrap Icons, Chart.js 4.4 |
| Card Data | Scryfall Bulk Data API                    |
| Server   | Apache (tested on Windows / Apache 2.4)    |

---

## Setup

### Requirements
- PHP 7.4+
- MySQL 8.0+
- Apache 2.4+
- A `cacert.pem` bundle for SSL verification on Windows ([download from curl.se](https://curl.se/ca/cacert.pem))

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/sp-squared/mtg-manager.git
   ```

2. **Copy the app folder into htdocs**
   ```
   Copy mtg-manager/ into C:\Apache24\htdocs\
   ```
   The app will be at `http://localhost/mtg-manager/`

3. **Create your database config**
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

4. **Run the schema**
   ```bash
   mysql -u root -p < database/mtg_schema.sql
   ```

5. **Enable the MySQL event scheduler** (for Card of the Day midnight gap-fill). Add to `my.ini`:
   ```ini
   [mysqld]
   event_scheduler=ON
   ```
   Then restart MySQL:
   ```
   net stop MySQL80 && net start MySQL80
   ```

6. **Register your account** — the first registered user (ID 1) becomes admin automatically.

7. **Import card data** — go to `admin/import_scryfall.php`. The importer streams Scryfall bulk data and can be re-run safely.

8. **Load prices** — go to `admin/update_prices.php` and click **Start Price Update**. Run regularly (daily or weekly) to build price trend data. Price tables are created automatically on first run.

### Configuration Notes

**`APP_BASE`** must match the subfolder name you used in htdocs:

| Setup | Value |
|---|---|
| `htdocs/mtg-manager/` | `'/mtg-manager'` |
| `htdocs/cards/` | `'/cards'` |
| Apache root pointing directly at the folder | `''` |

**SSL on Windows** — if the Scryfall importer cannot reach the API, add to `php.ini`:
```ini
curl.cainfo = "C:/path/to/cacert.pem"
```

**Password policy** — minimum 8 characters, maximum 32. The 32-character cap ensures passwords always fall within bcrypt's 72-byte processing limit, even with multibyte characters.

---

## License

MIT License — Copyright (c) 2026 Colin Morris-Moncada

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

## Acknowledgements

Card data provided by [Scryfall](https://scryfall.com) under their [terms of service](https://scryfall.com/docs/terms). This project is not affiliated with or endorsed by Wizards of the Coast or Scryfall.
