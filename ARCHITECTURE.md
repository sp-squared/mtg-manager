# MTG Manager — Architecture & Structure

## Database Schema

### Tables

| Table                      | Purpose                                                       |
|----------------------------|---------------------------------------------------------------|
| `player`                   | User accounts with session token                              |
| `cards`                    | Scryfall card data with import timestamp                      |
| `sets`                     | Set metadata                                                  |
| `colors`                   | W/U/B/R/G reference                                          |
| `card_colors`              | Card ↔ color junction                                        |
| `formats`                  | Format names                                                  |
| `format_legalities`        | Card legality per format                                      |
| `user_collection`          | Cards owned per user with added timestamp                     |
| `wishlist`                 | Wanted cards with priority                                    |
| `decks`                    | Deck metadata with favorite flag                              |
| `deck_cards`               | Cards in each deck with sideboard flag                        |
| `deck_exports`             | Shareable immutable deck snapshots                            |
| `daily_cards`              | Card of the Day pile with display dates                       |
| `login_attempts`           | Rate limit audit log with bypass tracking                     |
| `card_prices`              | Latest USD/EUR/Tix prices per card, updated by price updater  |
| `card_price_history`       | Daily price snapshots per card for trend tracking             |
| `recently_viewed`          | Per-user card view history (upserted on each modal open)      |
| `collection_value_history` | Daily total collection value snapshots per user               |
| `price_alerts`             | Per-user target prices; marked triggered when price is hit    |

### Notable Design Decisions

- `user_collection.added_at` — timestamps when a card was first added, enabling "Recently Added" sort
- `cards.imported_at` — set on first Scryfall import only, enabling "Newest Import" sort; preserved across re-imports via `IFNULL(imported_at, NOW())`
- `daily_cards` uses a recursive CTE to generate a full date series and backfill any gaps between the earliest record and `CURDATE()`
- `card_prices` is keyed on `card_id` as the primary key — upserted on every price update run, always reflects the latest known price
- `card_price_history` uses a `UNIQUE KEY (card_id, recorded_date)` with `ON DUPLICATE KEY UPDATE` so running the updater multiple times in one day refreshes rather than duplicates today's snapshot
- Both price tables are created automatically on first run of `admin/update_prices.php` — no migration needed for existing installs
- `recently_viewed` uses a `UNIQUE KEY (user_id, card_id)` with `ON DUPLICATE KEY UPDATE viewed_at = NOW()` — one row per user/card pair, always reflects the most recent view time
- `collection_value_history` uses a `UNIQUE KEY (user_id, recorded_date)` with `ON DUPLICATE KEY UPDATE` — snapshot recorded on dashboard visit, refreshed if visited again the same day
- `price_alerts` uses a `UNIQUE KEY (user_id, card_id)` so setting a new target for the same card replaces the old one; `is_active` is set to 0 and `triggered_at` recorded when the condition is first met
- New tables (`recently_viewed`, `collection_value_history`, `price_alerts`) are created automatically via `CREATE TABLE IF NOT EXISTS` on first page load — no manual migration required
- All schema migrations use `information_schema.COLUMNS` checks wrapped in temporary stored procedures for compatibility with MySQL versions that do not support `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`

---

## Repository Structure

```
mtg-manager-repo/               <- GitHub repo root
├── README.md
├── ARCHITECTURE.md
├── LICENSE
├── .gitignore
├── database/
│   └── mtg_schema.sql          <- run this once to set up your database
│
└── mtg-manager/                <- drop this entire folder into htdocs/
    ├── index.php               # login
    ├── portal.php              # register
    ├── dashboard.php           # home: stat cards, COTD, recently viewed, value chart, price alert banner
    ├── search.php              # card search with filters
    ├── collection.php          # personal collection with value banner
    ├── wishlist.php            # wishlist with priority + price alerts
    ├── decks.php               # deck list + favorites
    ├── deck_editor.php         # deck builder with sideboard + missing cards panel
    ├── profile.php             # account settings + export history
    ├── import_deck.php         # import a shared deck via export code (login required)
    ├── public_deck.php         # read-only deck preview via export code (no login required)
    ├── bulk_import.php         # paste MTGO/Arena card list to add cards in bulk
    ├── price_alerts.php        # manage per-card price drop alerts
    ├── life_counter.php        # full-screen two-player life counter (mobile-optimised)
    ├── style.css
    │
    ├── includes/               # shared PHP — not directly browser-accessible
    │   ├── db_config.template.php   <- copy to db_config.php and fill in credentials
    │   ├── db_config.php            <- gitignored, you create this
    │   ├── connect.php
    │   ├── functions.php
    │   ├── header.php
    │   └── footer.php
    │
    ├── ajax/                   # JSON endpoints called via fetch()
    │   ├── card_autocomplete.php       <- card name search (price alerts, etc.)
    │   ├── card_price_history.php      <- price history + sparkline data for a card
    │   ├── bulk_import_collection.php  <- parses and imports a pasted card list
    │   ├── record_view.php             <- upserts recently_viewed on card modal open
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
    │   ├── delete_account.php  <- wipes all user data and destroys session
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

## Key Patterns

| Pattern | Detail |
|---------|--------|
| CSRF | Meta tag `csrf-token` auto-injected into all POST `fetch()` calls via a header.php script interceptor |
| `APP_BASE` | Defined in `db_config.php`; controls all absolute URL redirects and nav links. Set once to match your htdocs subfolder name |
| Admin | User ID 1 is admin. No separate registration — just be the first to register |
| Streaming importer | Custom PHP streaming JSON parser used for Scryfall bulk imports to avoid memory exhaustion |
| AJAX error safety | All AJAX endpoints use `ob_start()` / `ob_end_clean()` to prevent PHP notices leaking into JSON responses |
| Pagination | 52 results per page throughout collection and search |
| Card modals | Details / Prices / Rulings tabs, lazy-loaded via `fetch()` on first open |
