<?php
/**
 * import_scryfall.php
 * Downloads Scryfall "default_cards" bulk data and imports into the database.
 * Run from browser or CLI. Progress streams live to the browser.
 *
 * UPDATED: Uses streaming JSON parser to avoid memory exhaustion.
 */

ini_set('memory_limit', '512M');   // still safe, but streaming uses far less
set_time_limit(0);
ini_set('output_buffering', 'off');

include 'header.php';
include 'connect.php';

// ── Auth guard — admin only ───────────────────────────────────────────────────
if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: index.php?error=" . $msg);
    exit();
}
if (!isAdmin()) {
    header("Location: dashboard.php?error=Access+denied.");
    exit();
}

// ── SSL certificate resolution ────────────────────────────────────────────────
// Tries php.ini settings, then common Windows paths, then disables verification
// with a logged warning as a last resort (never silently).
function resolveCacert(): array {
    // 1. php.ini curl.cainfo or openssl.cafile
    foreach (['curl.cainfo', 'openssl.cafile'] as $ini) {
        $p = ini_get($ini);
        if ($p && file_exists($p)) {
            return ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $p];
        }
    }
    // 2. Common Windows + XAMPP / Apache paths
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
    // 3. No cert bundle found — log and disable (development only)
    error_log('import_scryfall: cacert.pem not found; SSL peer verification disabled.');
    return ['verify_peer' => false, 'verify_peer_name' => false];
}
$ssl_ctx = resolveCacert();

// ── Helpers ───────────────────────────────────────────────────────────────────
function scryfallGet(string $url): ?array {
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

function flush_out(string $msg): void {
    echo $msg;
    if (ob_get_level()) ob_flush();
    flush();
}

function progress(string $msg, string $color = '#e8e8e8'): void {
    flush_out("<div style='color:{$color};font-family:monospace;font-size:0.85rem;margin:1px 0;'>{$msg}</div>\n");
}

/**
 * Simple streaming JSON parser for arrays of objects.
 * Reads from a resource and yields one object at a time.
 */
class JsonStreamParser
{
    private $fp;
    private $buffer = '';
    private $braceDepth = 0;
    private $inString = false;
    private $escaped = false;
    private $objectStart = null;

    public function __construct($resource)
    {
        $this->fp = $resource;
        // consume the opening '['
        while (!feof($this->fp)) {
            $ch = fgetc($this->fp);
            if ($ch === '[') break;
            if (!ctype_space($ch)) {
                throw new RuntimeException('Expected JSON array, found "' . $ch . '"');
            }
        }
    }

    /**
     * Read the next object from the stream.
     * @return array|null next object, or null if end of array
     */
    public function getNext()
    {
        while (!feof($this->fp)) {
            $ch = fgetc($this->fp);
            if ($ch === false) break;

            $this->buffer .= $ch;

            // Handle string start/end
            if ($ch === '"' && !$this->escaped) {
                $this->inString = !$this->inString;
            }
            // Handle escape character
            if ($ch === '\\' && !$this->escaped) {
                $this->escaped = true;
            } else {
                $this->escaped = false;
            }

            // Track braces only when not inside a string
            if (!$this->inString) {
                if ($ch === '{') {
                    if ($this->braceDepth === 0) {
                        $this->objectStart = strlen($this->buffer) - 1; // remember start of object
                    }
                    $this->braceDepth++;
                } elseif ($ch === '}') {
                    $this->braceDepth--;
                    if ($this->braceDepth === 0) {
                        // We have a complete object
                        $objectJson = substr($this->buffer, $this->objectStart);
                        $this->buffer = '';       // reset buffer for next object
                        $this->objectStart = null;

                        // Decode and return the object
                        $obj = json_decode($objectJson, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new RuntimeException('JSON decode error: ' . json_last_error_msg());
                        }
                        return $obj;
                    }
                }
            }
        }
        return null; // end of array
    }

    public function close()
    {
        fclose($this->fp);
    }
}

// ── Only run on POST ──────────────────────────────────────────────────────────
$running = ($_SERVER['REQUEST_METHOD'] === 'POST');
?>

<div class="container my-4" style="max-width:760px;">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
        <h1 class="mb-0" style="color:#c9a227;">
            <i class="bi bi-cloud-download me-2"></i>Import Scryfall Data
        </h1>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p style="color:#e8e8e8;">
                This tool downloads the <strong>Scryfall default_cards</strong> bulk dataset
                (~250 MB) and imports all cards, sets, colors, and legalities into the database.
            </p>
            <ul class="small mb-3" style="color:#8899aa;">
                <li>Existing cards are updated, not duplicated.</li>
                <li>Tokens, art series, and emblems are skipped.</li>
                <li>This may take several minutes — do not close the page.</li>
            </ul>
            <?php if (!$running): ?>
            <form method="post">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-cloud-arrow-down me-2"></i>Start Import
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

<?php if ($running):
// ── IMPORT ────────────────────────────────────────────────────────────────────
?>
    <div class="card shadow-sm">
        <div class="card-header" style="background:rgba(201,162,39,0.08);color:#c9a227;">
            <i class="bi bi-terminal me-2"></i>Import Log
        </div>
        <div class="card-body p-3" style="background:#0d0d1a;max-height:500px;overflow-y:auto;" id="log-box">
<?php
flush_out('');   // push headers

// ── Step 1: fetch bulk data manifest ─────────────────────────────────────────
progress('Fetching Scryfall bulk data manifest…', '#c9a227');
$manifest = scryfallGet('https://api.scryfall.com/bulk-data');
if (!$manifest || empty($manifest['data'])) {
    progress('ERROR: Could not reach Scryfall API. Check server outbound HTTP.', '#f87171');
    goto done;
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
    progress('ERROR: default_cards entry not found in manifest.', '#f87171');
    goto done;
}

$mb = $file_size ? round($file_size / 1048576) . ' MB' : 'unknown size';
progress("Found bulk file ({$mb}): {$download_uri}", '#75b798');

// ── Step 2 & 3: Download and stream-parse ─────────────────────────
progress('Downloading and parsing bulk data (streaming)…', '#c9a227');

// Open the download URI as a stream (no temp file needed)
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
    progress('ERROR: Failed to open download stream.', '#f87171');
    goto done;
}

$parser = new JsonStreamParser($fp);
$total_estimated = $file_size ? round($file_size / 1048576, 1) . ' MB' : 'unknown size';
progress("Streaming {$total_estimated} file. Importing cards one by one…", '#75b798');

// ── Step 4: prepare statements ────────────────────────────────────────────────
$dbc->query("SET foreign_key_checks = 0");
$dbc->query("SET autocommit = 0");

$set_stmt = $dbc->prepare(
    "INSERT INTO sets (id, name, released_at, set_type)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name=VALUES(name), set_type=VALUES(set_type)"
);
$color_stmt = $dbc->prepare(
    "INSERT IGNORE INTO colors (id, name) VALUES (?, ?)"
);
$card_stmt = $dbc->prepare(
    "INSERT INTO cards
        (id, name, set_id, collector_number, rarity, mana_cost, cmc,
         type_line, oracle_text, power, toughness, loyalty, image_uri,
         flavor_text, keywords, imported_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        name=VALUES(name), rarity=VALUES(rarity), mana_cost=VALUES(mana_cost),
        cmc=VALUES(cmc), type_line=VALUES(type_line), oracle_text=VALUES(oracle_text),
        power=VALUES(power), toughness=VALUES(toughness), loyalty=VALUES(loyalty),
        image_uri=VALUES(image_uri), flavor_text=VALUES(flavor_text),
        keywords=VALUES(keywords),
        imported_at = IFNULL(imported_at, NOW())"
);
$card_color_stmt = $dbc->prepare(
    "INSERT IGNORE INTO card_colors (card_id, color_id) VALUES (?, ?)"
);
$legality_stmt = $dbc->prepare(
    "INSERT INTO format_legalities (card_id, format_id, legality)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE legality=VALUES(legality)"
);

// Seed colors
foreach (['W'=>'White','U'=>'Blue','B'=>'Black','R'=>'Red','G'=>'Green'] as $code => $name) {
    $color_stmt->bind_param("ss", $code, $name);
    $color_stmt->execute();
}

// Format ID cache
$format_cache = [];
$format_sel   = $dbc->prepare("SELECT id FROM formats WHERE name = ?");
$format_ins   = $dbc->prepare("INSERT IGNORE INTO formats (name) VALUES (?)");

$skip_layouts = ['token','art_series','emblem','double_faced_token','vanguard',
                 'scheme','conspiracy','planar'];

$imported = 0;
$skipped  = 0;
$errors   = 0;
$commit_every = 500;

// ── Step 5: iterate cards from the stream ─────────────────────────────────────
while ($card = $parser->getNext()) {

    if (!isset($card['id'], $card['name'])) { $errors++; continue; }

    if (in_array($card['layout'] ?? '', $skip_layouts)) { $skipped++; continue; }

    // ── Set ───────────────────────────────────────────────────────────────────
    $set_id   = $card['set']        ?? '';
    $set_name = $card['set_name']   ?? '';
    $released = $card['released_at'] ?? null;
    $set_type = $card['set_type']   ?? '';
    if ($set_id && $set_name) {
        $set_stmt->bind_param("ssss", $set_id, $set_name, $released, $set_type);
        $set_stmt->execute();
    }

    // ── Card ──────────────────────────────────────────────────────────────────
    $card_id    = $card['id'];
    $name       = $card['name'];
    $coll_num   = $card['collector_number'] ?? '';
    $rarity     = $card['rarity']           ?? '';
    $mana_cost  = $card['mana_cost']        ?? null;
    $cmc        = (float)($card['cmc']      ?? 0);
    $type_line  = $card['type_line']        ?? '';
    $oracle     = $card['oracle_text']      ?? ($card['card_faces'][0]['oracle_text'] ?? null);
    $flavor     = $card['flavor_text']      ?? ($card['card_faces'][0]['flavor_text'] ?? null);
    $keywords   = isset($card['keywords']) && count($card['keywords'])
                  ? json_encode($card['keywords'])
                  : null;
    $power      = $card['power']            ?? null;
    $toughness  = $card['toughness']        ?? null;
    $loyalty    = $card['loyalty']          ?? null;
    $image      = $card['image_uris']['normal']
               ?? ($card['card_faces'][0]['image_uris']['normal'] ?? null);

    $card_stmt->bind_param(
        "ssssssdssssssss",
        $card_id, $name, $set_id, $coll_num, $rarity,
        $mana_cost, $cmc, $type_line, $oracle,
        $power, $toughness, $loyalty, $image,
        $flavor, $keywords
    );
    if (!$card_stmt->execute()) { $errors++; continue; }

    // ── Colors ────────────────────────────────────────────────────────────────
    $colors = $card['colors'] ?? ($card['card_faces'][0]['colors'] ?? []);
    foreach ($colors as $col) {
        $card_color_stmt->bind_param("ss", $card_id, $col);
        $card_color_stmt->execute();
    }

    // ── Legalities ────────────────────────────────────────────────────────────
    foreach ($card['legalities'] ?? [] as $fmt => $leg) {
        if (!isset($format_cache[$fmt])) {
            $format_sel->bind_param("s", $fmt);
            $format_sel->execute();
            $row = $format_sel->get_result()->fetch_assoc();
            if ($row) {
                $format_cache[$fmt] = $row['id'];
            } else {
                $format_ins->bind_param("s", $fmt);
                $format_ins->execute();
                $format_cache[$fmt] = $dbc->insert_id;
            }
        }
        $fmt_id = $format_cache[$fmt];
        $legality_stmt->bind_param("sis", $card_id, $fmt_id, $leg);
        $legality_stmt->execute();
    }

    $imported++;

    // Batch commit + progress
    if ($imported % $commit_every === 0) {
        $dbc->commit();
        progress("  ✔ {$imported} cards imported…", '#8899aa');
    }
}

$parser->close();

// Final commit
$dbc->commit();
$dbc->query("SET foreign_key_checks = 1");
$dbc->query("SET autocommit = 1");

// Close statements
foreach ([$set_stmt,$color_stmt,$card_stmt,$card_color_stmt,
          $legality_stmt,$format_sel,$format_ins] as $s) {
    $s->close();
}

progress('', '#e8e8e8');
progress("✅ Import complete!", '#75b798');
progress("   Cards imported/updated : " . number_format($imported), '#75b798');
progress("   Skipped (tokens etc.)  : " . number_format($skipped),  '#8899aa');
progress("   Errors                 : " . number_format($errors),   $errors ? '#f87171' : '#8899aa');

done:
$dbc->close();
?>
        </div>
        <div class="card-footer bg-transparent">
            <a href="dashboard.php" class="btn btn-sm btn-outline-primary">← Back to Dashboard</a>
            <a href="import_scryfall.php" class="btn btn-sm btn-outline-warning ms-2">Run Again</a>
        </div>
    </div>

<script>
// Auto-scroll the log box as output streams in
const box = document.getElementById('log-box');
const observer = new MutationObserver(() => box.scrollTop = box.scrollHeight);
observer.observe(box, { childList: true, subtree: true });
</script>

<?php endif; ?>
</div>

<?php include 'footer.php'; ?>