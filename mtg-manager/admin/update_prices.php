<?php
/**
 * update_prices.php
 * Streams the Scryfall default_cards bulk file and updates card prices.
 * Creates card_prices and card_price_history tables on first run.
 *
 * card_prices        — latest price per card (upserted)
 * card_price_history — one row per (card_id, date), for trend tracking
 */

ini_set('memory_limit', '256M');
set_time_limit(0);
ini_set('output_buffering', 'off');

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/connect.php';

// ── Auth guard — admin only ───────────────────────────────────────────────────
if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: ../index.php?error=" . $msg);
    exit();
}
if (!isAdmin()) {
    header("Location: ../dashboard.php?error=Access+denied.");
    exit();
}

// ── SSL certificate resolution (same logic as import_scryfall) ────────────────
function resolveCacertPrices(): array {
    foreach (['curl.cainfo', 'openssl.cafile'] as $ini) {
        $p = ini_get($ini);
        if ($p && file_exists($p)) {
            return ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $p];
        }
    }
    $candidates = [
        'C:/Apache24/conf/cacert.pem',
        'C:/php/extras/ssl/cacert.pem',
        'C:/xampp/php/extras/ssl/cacert.pem',
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        PHP_BINARY ? dirname(PHP_BINARY) . '/extras/ssl/cacert.pem' : '',
        PHP_BINARY ? dirname(PHP_BINARY) . '/../Apache24/conf/cacert.pem' : '',
    ];
    foreach ($candidates as $p) {
        if ($p && file_exists($p)) {
            return ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $p];
        }
    }
    error_log('update_prices: cacert.pem not found; SSL peer verification disabled.');
    return ['verify_peer' => false, 'verify_peer_name' => false];
}
$ssl_ctx = resolveCacertPrices();

// ── Helpers ───────────────────────────────────────────────────────────────────
function pricesGet(string $url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: MTGCollectionManager/1.0\r\nAccept: application/json\r\n",
            'timeout' => 30,
        ],
        'ssl' => $GLOBALS['ssl_ctx'],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

function flush_prices(string $msg): void {
    echo $msg;
    if (ob_get_level()) ob_flush();
    flush();
}

function prog(string $msg, string $color = '#e8e8e8'): void {
    flush_prices("<div style='color:{$color};font-family:monospace;font-size:0.85rem;margin:1px 0;'>{$msg}</div>\n");
}

function recordCollectionUpdateAlerts(mysqli $dbc, string $source): void {
    $dbc->query("CREATE TABLE IF NOT EXISTS collection_value_update_alerts (
        id             BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id        INT NOT NULL,
        source         VARCHAR(40) NOT NULL,
        previous_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        current_value  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        trend          ENUM('up','down','unchanged') NOT NULL,
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_read        TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_user_unread (user_id, is_read, created_at),
        INDEX idx_user_latest (user_id, id)
    )");

    $sql = "INSERT INTO collection_value_update_alerts (user_id, source, previous_value, current_value, trend)
            SELECT p.id,
                   ?,
                   IFNULL(prev.current_value, 0.00) AS previous_value,
                   IFNULL(cv.total_value, 0.00) AS current_value,
                   CASE
                       WHEN IFNULL(cv.total_value, 0.00) > IFNULL(prev.current_value, 0.00) THEN 'up'
                       WHEN IFNULL(cv.total_value, 0.00) < IFNULL(prev.current_value, 0.00) THEN 'down'
                       ELSE 'unchanged'
                   END AS trend
            FROM player p
            LEFT JOIN (
                SELECT uc.user_id, ROUND(SUM(cp.price_usd * uc.quantity), 2) AS total_value
                FROM user_collection uc
                JOIN card_prices cp ON cp.card_id = uc.card_id
                GROUP BY uc.user_id
            ) cv ON cv.user_id = p.id
            LEFT JOIN (
                SELECT a.user_id, a.current_value
                FROM collection_value_update_alerts a
                JOIN (
                    SELECT user_id, MAX(id) AS latest_id
                    FROM collection_value_update_alerts
                    GROUP BY user_id
                ) last_alert ON last_alert.latest_id = a.id
            ) prev ON prev.user_id = p.id";

    $stmt = $dbc->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $source);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Minimal streaming JSON parser — same approach as import_scryfall.
 * Yields one top-level object at a time from a JSON array stream.
 */
class PriceStreamParser {
    private $fp;
    private string $buffer = '';
    private int $braceDepth = 0;
    private bool $inString = false;
    private bool $escaped = false;
    private ?int $objectStart = null;

    public function __construct($resource) {
        $this->fp = $resource;
        while (!feof($this->fp)) {
            $ch = fgetc($this->fp);
            if ($ch === '[') break;
            if (!ctype_space($ch)) {
                throw new RuntimeException('Expected JSON array, found "' . $ch . '"');
            }
        }
    }

    public function getNext(): ?array {
        while (!feof($this->fp)) {
            $ch = fgetc($this->fp);
            if ($ch === false) break;

            $this->buffer .= $ch;

            if ($ch === '"' && !$this->escaped) {
                $this->inString = !$this->inString;
            }
            if ($ch === '\\' && !$this->escaped) {
                $this->escaped = true;
            } else {
                $this->escaped = false;
            }

            if (!$this->inString) {
                if ($ch === '{') {
                    if ($this->braceDepth === 0) {
                        $this->objectStart = strlen($this->buffer) - 1;
                    }
                    $this->braceDepth++;
                } elseif ($ch === '}') {
                    $this->braceDepth--;
                    if ($this->braceDepth === 0) {
                        $objectJson = substr($this->buffer, $this->objectStart);
                        $this->buffer = '';
                        $this->objectStart = null;
                        $obj = json_decode($objectJson, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new RuntimeException('JSON decode error: ' . json_last_error_msg());
                        }
                        return $obj;
                    }
                }
            }
        }
        return null;
    }

    public function close(): void { fclose($this->fp); }
}

// ── Only run on POST ──────────────────────────────────────────────────────────
$running = ($_SERVER['REQUEST_METHOD'] === 'POST');
?>

<div class="container my-4" style="max-width:760px;">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="../dashboard.php" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
        <h1 class="mb-0" style="color:#c9a227;">
            <i class="bi bi-currency-dollar me-2"></i>Update Card Prices
        </h1>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p style="color:#e8e8e8;">
                Downloads the <strong>Scryfall default_cards</strong> bulk dataset and updates
                USD, EUR, and MTGO Tix prices for every card in the database.
            </p>
            <ul class="small mb-3" style="color:#8899aa;">
                <li>Current prices are updated in <code>card_prices</code>.</li>
                <li>A daily snapshot is saved in <code>card_price_history</code> for trend tracking.</li>
                <li>Running this more than once per day refreshes today's snapshot.</li>
                <li>Cards with no price data retain a <code>NULL</code> price.</li>
            </ul>
            <?php if (!$running): ?>
            <form method="post">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-cloud-arrow-down me-2"></i>Start Price Update
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

<?php if ($running):
// ── PRICE UPDATE ──────────────────────────────────────────────────────────────
?>
    <div class="card shadow-sm">
        <div class="card-header" style="background:rgba(201,162,39,0.08);color:#c9a227;">
            <i class="bi bi-terminal me-2"></i>Update Log
        </div>
        <div class="card-body p-3" style="background:#0d0d1a;max-height:500px;overflow-y:auto;" id="log-box">
<?php
flush_prices('');

// ── Step 1: Ensure tables exist ───────────────────────────────────────────────
prog('Ensuring price tables exist…', '#c9a227');

$dbc->query("
    CREATE TABLE IF NOT EXISTS card_prices (
        card_id        VARCHAR(36) NOT NULL PRIMARY KEY,
        price_usd      DECIMAL(10,2) NULL,
        price_usd_foil DECIMAL(10,2) NULL,
        price_eur      DECIMAL(10,2) NULL,
        price_eur_foil DECIMAL(10,2) NULL,
        price_tix      DECIMAL(10,2) NULL,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");
if ($dbc->errno) {
    prog('ERROR creating card_prices: ' . $dbc->error, '#f87171');
    goto done_prices;
}

$dbc->query("
    CREATE TABLE IF NOT EXISTS card_price_history (
        id             BIGINT AUTO_INCREMENT PRIMARY KEY,
        card_id        VARCHAR(36) NOT NULL,
        price_usd      DECIMAL(10,2) NULL,
        price_usd_foil DECIMAL(10,2) NULL,
        price_eur      DECIMAL(10,2) NULL,
        price_eur_foil DECIMAL(10,2) NULL,
        price_tix      DECIMAL(10,2) NULL,
        recorded_date  DATE NOT NULL,
        UNIQUE KEY uq_card_date (card_id, recorded_date),
        INDEX idx_card   (card_id),
        INDEX idx_date   (recorded_date)
    )
");
if ($dbc->errno) {
    prog('ERROR creating card_price_history: ' . $dbc->error, '#f87171');
    goto done_prices;
}
prog('Tables ready.', '#75b798');

// ── Step 2: Fetch manifest ────────────────────────────────────────────────────
prog('Fetching Scryfall bulk data manifest…', '#c9a227');
$manifest = pricesGet('https://api.scryfall.com/bulk-data');
if (!$manifest || empty($manifest['data'])) {
    prog('ERROR: Could not reach Scryfall API. Check server outbound HTTP.', '#f87171');
    goto done_prices;
}

$download_uri = null;
$file_size    = 0;
foreach ($manifest['data'] as $entry) {
    if ($entry['type'] === 'default_cards') {
        $download_uri = $entry['download_uri'];
        $file_size    = $entry['size'] ?? 0;
        break;
    }
}
if (!$download_uri) {
    prog('ERROR: default_cards entry not found in manifest.', '#f87171');
    goto done_prices;
}

$mb = $file_size ? round($file_size / 1048576) . ' MB' : 'unknown size';
prog("Found bulk file ({$mb})", '#75b798');

// ── Step 3: Open stream ───────────────────────────────────────────────────────
prog('Downloading and parsing bulk data (streaming)…', '#c9a227');

$dl_ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: MTGCollectionManager/1.0\r\n",
        'timeout' => 300,
    ],
    'ssl' => $GLOBALS['ssl_ctx'],
]);

$fp = @fopen($download_uri, 'r', false, $dl_ctx);
if (!$fp) {
    prog('ERROR: Failed to open download stream.', '#f87171');
    goto done_prices;
}

$parser = new PriceStreamParser($fp);

// ── Step 4: Prepare statements ────────────────────────────────────────────────
$dbc->query("SET autocommit = 0");

$upsert_stmt = $dbc->prepare("
    INSERT INTO card_prices (card_id, price_usd, price_usd_foil, price_eur, price_eur_foil, price_tix, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        price_usd      = VALUES(price_usd),
        price_usd_foil = VALUES(price_usd_foil),
        price_eur      = VALUES(price_eur),
        price_eur_foil = VALUES(price_eur_foil),
        price_tix      = VALUES(price_tix),
        updated_at     = NOW()
");

$history_stmt = $dbc->prepare("
    INSERT INTO card_price_history (card_id, price_usd, price_usd_foil, price_eur, price_eur_foil, price_tix, recorded_date)
    VALUES (?, ?, ?, ?, ?, ?, CURDATE())
    ON DUPLICATE KEY UPDATE
        price_usd      = VALUES(price_usd),
        price_usd_foil = VALUES(price_usd_foil),
        price_eur      = VALUES(price_eur),
        price_eur_foil = VALUES(price_eur_foil),
        price_tix      = VALUES(price_tix)
");

$skip_layouts = ['token','art_series','emblem','double_faced_token','vanguard',
                 'scheme','conspiracy','planar'];

$updated      = 0;
$skipped      = 0;
$no_price     = 0;
$errors       = 0;
$commit_every = 500;

// ── Step 5: Stream and upsert ─────────────────────────────────────────────────
while ($card = $parser->getNext()) {
    if (!isset($card['id'])) { $errors++; continue; }
    if (in_array($card['layout'] ?? '', $skip_layouts)) { $skipped++; continue; }

    $prices = $card['prices'] ?? [];

    // Convert string prices to float or null
    $usd      = isset($prices['usd'])      && $prices['usd']      !== null ? (float)$prices['usd']      : null;
    $usd_foil = isset($prices['usd_foil']) && $prices['usd_foil'] !== null ? (float)$prices['usd_foil'] : null;
    $eur      = isset($prices['eur'])      && $prices['eur']      !== null ? (float)$prices['eur']      : null;
    $eur_foil = isset($prices['eur_foil']) && $prices['eur_foil'] !== null ? (float)$prices['eur_foil'] : null;
    $tix      = isset($prices['tix'])      && $prices['tix']      !== null ? (float)$prices['tix']      : null;

    // Skip cards with zero price data (e.g. tokens, reprints without market data)
    if ($usd === null && $usd_foil === null && $eur === null && $tix === null) {
        $no_price++;
        continue;
    }

    $card_id = $card['id'];

    $upsert_stmt->bind_param("sddddd", $card_id, $usd, $usd_foil, $eur, $eur_foil, $tix);
    if (!$upsert_stmt->execute()) { $errors++; continue; }

    $history_stmt->bind_param("sddddd", $card_id, $usd, $usd_foil, $eur, $eur_foil, $tix);
    $history_stmt->execute(); // ignore duplicate errors — ON DUPLICATE KEY handles them

    $updated++;

    if ($updated % $commit_every === 0) {
        $dbc->commit();
        prog("  ✔ {$updated} cards updated…", '#8899aa');
    }
}

$parser->close();
$dbc->commit();
$dbc->query("SET autocommit = 1");

$upsert_stmt->close();
$history_stmt->close();

prog('', '#e8e8e8');
prog('✅ Price update complete!', '#75b798');
prog("   Cards updated   : " . number_format($updated),   '#75b798');
prog("   No price data   : " . number_format($no_price),  '#8899aa');
prog("   Skipped (tokens): " . number_format($skipped),   '#8899aa');
prog("   Errors          : " . number_format($errors),    $errors ? '#f87171' : '#8899aa');

if ($errors === 0) {
    recordCollectionUpdateAlerts($dbc, 'price_update');
    prog('📣 Collection value alerts generated for all users.', '#75b798');
}

done_prices:
$dbc->close();
?>
        </div>
        <div class="card-footer bg-transparent">
            <a href="../dashboard.php" class="btn btn-sm btn-outline-primary">← Back to Dashboard</a>
            <a href="update_prices.php" class="btn btn-sm btn-outline-warning ms-2">Run Again</a>
        </div>
    </div>

<script>
const box = document.getElementById('log-box');
const observer = new MutationObserver(() => box.scrollTop = box.scrollHeight);
observer.observe(box, { childList: true, subtree: true });
</script>

<?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
