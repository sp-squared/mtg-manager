-- =============================================================================
-- MTG Collection Manager — Master Schema
-- Run this once against a fresh database.
-- Includes all tables, columns, and constraints added across all migrations.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. DATABASE & USER
-- -----------------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'mtg_collection'@'localhost' IDENTIFIED BY 'change_this_password';
CREATE DATABASE IF NOT EXISTS mtg_database
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON mtg_database.* TO 'mtg_collection'@'localhost';
FLUSH PRIVILEGES;

USE mtg_database;

-- -----------------------------------------------------------------------------
-- 2. REFERENCE TABLES (no foreign-key dependencies)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sets (
    id          VARCHAR(20)  PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    released_at DATE,
    set_type    VARCHAR(50)
);

CREATE TABLE IF NOT EXISTS colors (
    id   CHAR(1)     PRIMARY KEY,   -- W, U, B, R, G
    name VARCHAR(20)
);

INSERT IGNORE INTO colors (id, name) VALUES
    ('W', 'White'),
    ('U', 'Blue'),
    ('B', 'Black'),
    ('R', 'Red'),
    ('G', 'Green');

CREATE TABLE IF NOT EXISTS formats (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL
);

-- -----------------------------------------------------------------------------
-- 3. PLAYER
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS player (
    id       INT          AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50)  UNIQUE NOT NULL,
    password      VARCHAR(255) NOT NULL,             -- bcrypt hash
    email         VARCHAR(255) NOT NULL UNIQUE,       -- enforced NOT NULL (1NF)
    session_token VARCHAR(64)  NULL DEFAULT NULL        -- single-session enforcement
);

-- -----------------------------------------------------------------------------
-- 4. CARDS
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cards (
    id               VARCHAR(50)      PRIMARY KEY,   -- Scryfall UUID
    name             VARCHAR(255)     NOT NULL,
    set_id           VARCHAR(20),
    collector_number VARCHAR(20),
    rarity           VARCHAR(20),
    mana_cost        VARCHAR(50),
    cmc              DECIMAL(10,1)    DEFAULT 0,      -- uncapped for high-cmc cards
    type_line        TEXT,
    oracle_text      TEXT,
    power            VARCHAR(10),
    toughness        VARCHAR(10),
    loyalty          VARCHAR(10),
    image_uri        VARCHAR(500),
    flavor_text      TEXT,
    keywords         JSON,
    imported_at      TIMESTAMP    NULL DEFAULT NULL,  -- set on first import, never updated
    FOREIGN KEY (set_id) REFERENCES sets(id)
);

-- -----------------------------------------------------------------------------
-- 5. JUNCTION TABLES FOR CARDS
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS card_colors (
    card_id  VARCHAR(50) NOT NULL,
    color_id CHAR(1)     NOT NULL,
    PRIMARY KEY (card_id, color_id),
    FOREIGN KEY (card_id)  REFERENCES cards(id)  ON DELETE CASCADE,
    FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS format_legalities (
    card_id   VARCHAR(50) NOT NULL,
    format_id INT         NOT NULL,
    legality  VARCHAR(20),                -- 'legal', 'banned', 'restricted'
    PRIMARY KEY (card_id, format_id),
    FOREIGN KEY (card_id)   REFERENCES cards(id)   ON DELETE CASCADE,
    FOREIGN KEY (format_id) REFERENCES formats(id) ON DELETE CASCADE
);

-- -----------------------------------------------------------------------------
-- 6. USER DATA TABLES
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_collection (
    user_id      INT         NOT NULL,
    card_id      VARCHAR(50) NOT NULL,
    quantity     INT         DEFAULT 1,
    foil_quantity INT        DEFAULT 0,
    added_at     TIMESTAMP   NULL DEFAULT NULL,  -- when card was first added to collection
    PRIMARY KEY (user_id, card_id),
    FOREIGN KEY (user_id) REFERENCES player(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wishlist (
    user_id  INT         NOT NULL,
    card_id  VARCHAR(50) NOT NULL,
    priority TINYINT     DEFAULT 1,     -- 1 = low, 5 = high
    PRIMARY KEY (user_id, card_id),
    FOREIGN KEY (user_id) REFERENCES player(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(id)  ON DELETE CASCADE
);

-- -----------------------------------------------------------------------------
-- 7. DECKS
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS decks (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    is_favorite TINYINT      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES player(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS deck_cards (
    deck_id      INT         NOT NULL,
    card_id      VARCHAR(50) NOT NULL,
    quantity     INT         DEFAULT 1,
    is_sideboard BOOLEAN     DEFAULT FALSE,
    PRIMARY KEY (deck_id, card_id, is_sideboard),
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
);

-- -----------------------------------------------------------------------------
-- 8. DECK EXPORTS (shareable snapshots with expiry)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deck_exports (
    id           INT          AUTO_INCREMENT PRIMARY KEY,
    export_code  VARCHAR(12)  NOT NULL UNIQUE,
    owner_id     INT          NOT NULL,
    deck_name    VARCHAR(255) NOT NULL,
    description  TEXT,
    card_data    JSON         NOT NULL,     -- immutable snapshot at export time
    created_at   DATETIME     DEFAULT NOW(),
    expires_at   DATETIME     NULL,         -- NULL = never expires
    import_count INT          DEFAULT 0,
    FOREIGN KEY (owner_id) REFERENCES player(id) ON DELETE CASCADE
);

-- -----------------------------------------------------------------------------
-- 9. CARD OF THE DAY
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_cards (
    id           INT         AUTO_INCREMENT PRIMARY KEY,
    card_id      VARCHAR(36) NOT NULL,
    display_date DATE        NOT NULL UNIQUE,
    INDEX idx_date (display_date)
);

-- Stored procedure: fills every missing date between earliest record and today.
-- Called by the dashboard on every page load via PHP; also safe to call manually.
-- Uses a recursive CTE (requires MySQL 8.0+) so no PHP date logic is needed.
DROP PROCEDURE IF EXISTS fill_daily_card_gaps;
DELIMITER $$
CREATE PROCEDURE fill_daily_card_gaps()
BEGIN
    -- Only run if the table has at least one row (CTE needs a start date)
    IF (SELECT COUNT(*) FROM daily_cards) > 0 THEN
        -- Insert one unique random card for every date that has no entry,
        -- from the earliest existing record up to today's MySQL date.
        INSERT IGNORE INTO daily_cards (card_id, display_date)
        SELECT
            (SELECT c.id
             FROM cards c
             WHERE c.type_line NOT LIKE '%Token%'
               AND c.type_line NOT LIKE '%Basic Land%'
               AND c.image_uri IS NOT NULL
               AND c.id NOT IN (SELECT card_id FROM daily_cards)
             ORDER BY RAND()
             LIMIT 1) AS card_id,
            gap_date.d AS display_date
        FROM (
            WITH RECURSIVE date_series AS (
                SELECT MIN(display_date) AS d FROM daily_cards
                UNION ALL
                SELECT DATE_ADD(d, INTERVAL 1 DAY)
                FROM date_series
                WHERE d < CURDATE()
            )
            SELECT d
            FROM date_series
            LEFT JOIN daily_cards ON daily_cards.display_date = d
            WHERE daily_cards.display_date IS NULL
        ) AS gap_date
        WHERE gap_date.d IS NOT NULL;
    END IF;
END$$
DELIMITER ;

-- Scheduled event: runs the gap-fill procedure once per day at midnight.
-- Requires event_scheduler = ON in MySQL (SET GLOBAL event_scheduler = ON).
-- This means gaps are filled even on days nobody logs in.
DROP EVENT IF EXISTS daily_card_gap_fill;
CREATE EVENT daily_card_gap_fill
    ON SCHEDULE EVERY 1 DAY
    STARTS (CURDATE() + INTERVAL 1 DAY)   -- first run: tomorrow midnight
    DO CALL fill_daily_card_gaps();

-- -----------------------------------------------------------------------------
-- 10. LOGIN RATE LIMITING
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT NOW(),
    event_type   VARCHAR(20)  NOT NULL DEFAULT 'failed',  -- 'failed' | 'bypassed'
    INDEX idx_username_time (username, attempted_at)
);

-- =============================================================================
-- UPGRADE MIGRATIONS — existing databases only
-- These ALTER statements are safe to run on a live database.
-- They will error if the column already exists — that is expected and harmless.
-- On a fresh database these are unnecessary (the CREATE TABLEs above include them).
-- =============================================================================

-- =============================================================================
-- UPGRADE MIGRATIONS
-- Safe to run against an existing database. Each block checks
-- information_schema before altering so no errors on existing columns.
-- ADD COLUMN IF NOT EXISTS requires MySQL 8.0.3+; these procedures work on
-- MySQL 5.7+ and 8.0 alike.
-- =============================================================================

-- cards: uncap CMC
ALTER TABLE cards MODIFY cmc DECIMAL(10,1) DEFAULT 0;

-- cards: flavor_text
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cards' AND COLUMN_NAME = 'flavor_text'
  ) THEN
    ALTER TABLE cards ADD COLUMN flavor_text TEXT NULL;
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- cards: keywords
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cards' AND COLUMN_NAME = 'keywords'
  ) THEN
    ALTER TABLE cards ADD COLUMN keywords JSON NULL;
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- decks: is_favorite
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'decks' AND COLUMN_NAME = 'is_favorite'
  ) THEN
    ALTER TABLE decks ADD COLUMN is_favorite TINYINT NOT NULL DEFAULT 0;
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- decks: updated_at
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'decks' AND COLUMN_NAME = 'updated_at'
  ) THEN
    ALTER TABLE decks ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
    UPDATE decks SET updated_at = created_at WHERE id > 0;
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- login_attempts table (idempotent)
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT NOW(),
    event_type   VARCHAR(20)  NOT NULL DEFAULT 'failed',
    INDEX idx_username_time (username, attempted_at)
);

-- login_attempts: event_type column
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'event_type'
  ) THEN
    ALTER TABLE login_attempts ADD COLUMN event_type VARCHAR(20) NOT NULL DEFAULT 'failed';
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- user_collection: added_at (tracks when card was first added for Recently Added sort)
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_collection' AND COLUMN_NAME = 'added_at'
  ) THEN
    ALTER TABLE user_collection ADD COLUMN added_at TIMESTAMP NULL DEFAULT NULL;
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- cards: imported_at (tracks first import time for Newest sort)
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cards' AND COLUMN_NAME = 'imported_at'
  ) THEN
    ALTER TABLE cards ADD COLUMN imported_at TIMESTAMP NULL DEFAULT NULL;
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- player: session_token
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER $$
CREATE PROCEDURE _add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player' AND COLUMN_NAME = 'session_token'
  ) THEN
    ALTER TABLE player ADD COLUMN session_token VARCHAR(64) NULL DEFAULT NULL;
  END IF;
END$$
DELIMITER ;
CALL _add_col();
DROP PROCEDURE IF EXISTS _add_col;

-- daily_cards gap-fill procedure and scheduler
DROP PROCEDURE IF EXISTS fill_daily_card_gaps;
DELIMITER $$
CREATE PROCEDURE fill_daily_card_gaps()
BEGIN
    IF (SELECT COUNT(*) FROM daily_cards) > 0 THEN
        INSERT IGNORE INTO daily_cards (card_id, display_date)
        SELECT
            (SELECT c.id FROM cards c
             WHERE c.type_line NOT LIKE '%Token%'
               AND c.type_line NOT LIKE '%Basic Land%'
               AND c.image_uri IS NOT NULL
               AND c.id NOT IN (SELECT card_id FROM daily_cards)
             ORDER BY RAND() LIMIT 1),
            gap_date.d
        FROM (
            WITH RECURSIVE date_series AS (
                SELECT MIN(display_date) AS d FROM daily_cards
                UNION ALL
                SELECT DATE_ADD(d, INTERVAL 1 DAY) FROM date_series WHERE d < CURDATE()
            )
            SELECT d FROM date_series
            LEFT JOIN daily_cards ON daily_cards.display_date = d
            WHERE daily_cards.display_date IS NULL
        ) AS gap_date
        WHERE gap_date.d IS NOT NULL;
    END IF;
END$$
DELIMITER ;

DROP EVENT IF EXISTS daily_card_gap_fill;
CREATE EVENT daily_card_gap_fill
    ON SCHEDULE EVERY 1 DAY
    STARTS (CURDATE() + INTERVAL 1 DAY)
    DO CALL fill_daily_card_gaps();

-- Enable event scheduler (run once as root if not already set):
SET GLOBAL event_scheduler = ON;

-- =============================================================================
-- END OF SCHEMA
-- =============================================================================