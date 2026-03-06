<?php
// Returns both panel bodies as JSON HTML strings — called by deck_editor AJAX
session_start();
include 'connect.php';
include 'functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['error' => 'Not logged in']); exit(); }
requireCsrf();


$user_id = getUserId();
$deck_id = (int)($_POST['deck_id'] ?? 0);

// Verify ownership
$s = $dbc->prepare("SELECT id FROM decks WHERE id = ? AND user_id = ?");
$s->bind_param("ii", $deck_id, $user_id);
$s->execute();
$s->store_result();
if ($s->num_rows == 0) { echo json_encode(['error' => 'Invalid deck']); exit(); }
$s->close();

function truncate($string, $length = 16, $append = '…') {
    return strlen($string) > $length ? substr($string, 0, $length) . $append : $string;
}

// ── Collection panel ─────────────────────────────────────────────────────────
$col_q = "SELECT c.id, c.name, c.mana_cost, c.type_line, uc.quantity,
                 COALESCE(dc.quantity, 0) as in_deck
          FROM user_collection uc
          JOIN cards c ON uc.card_id = c.id
          LEFT JOIN deck_cards dc ON dc.card_id = c.id AND dc.deck_id = ?
          WHERE uc.user_id = ?
          ORDER BY c.name";
$col_s = $dbc->prepare($col_q);
$col_s->bind_param("ii", $deck_id, $user_id);
$col_s->execute();
$col_result = $col_s->get_result();

ob_start();
if ($col_result->num_rows == 0): ?>
    <p style="color:#8899aa;">Your collection is empty. <a href="search.php">Add cards first.</a></p>
<?php else: ?>
    <table class="table table-sm table-hover" style="color:#e8e8e8;">
        <thead>
            <tr style="color:#c9a227;">
                <th>Card</th><th>Mana</th><th>Type</th><th>Owned</th><th>Add</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($card = $col_result->fetch_assoc()):
            $owned     = (int)$card['quantity'];
            $in_deck   = (int)$card['in_deck'];
            $remaining = $owned - $in_deck;
            $at_limit  = $remaining <= 0;
        ?>
            <tr>
                <td title="<?= htmlspecialchars($card['name']) ?>"><?= htmlspecialchars(truncate($card['name'])) ?></td>
                <td><?= htmlspecialchars($card['mana_cost'] ?? '—') ?></td>
                <td title="<?= htmlspecialchars($card['type_line']) ?>"><?= htmlspecialchars(truncate($card['type_line'], 12)) ?></td>
                <td>
                    <?= $owned ?>
                    <?php if ($in_deck > 0): ?>
                        <span style="color:#8899aa;font-size:0.8rem;" title="<?= $in_deck ?> already in deck">(<?= $in_deck ?> in deck)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($at_limit): ?>
                        <div class="d-flex gap-1 align-items-center">
                            <input type="number" class="form-control form-control-sm" style="width:60px;" value="0" disabled>
                            <button class="btn btn-sm btn-secondary" style="min-width:130px;opacity:0.45;" disabled>Add</button>
                            <span title="All copies are in the deck" style="font-size:1.3rem;line-height:1;">✅</span>
                        </div>
                    <?php else: ?>
                        <div class="d-flex gap-1">
                            <input type="number" class="deck-qty-input form-control form-control-sm"
                                   value="1" min="1" max="<?= $remaining ?>" style="width:60px;">
                            <button class="btn btn-sm btn-success add-to-deck-btn" style="min-width:130px;"
                                    data-deck-id="<?= $deck_id ?>"
                                    data-card-id="<?= htmlspecialchars($card['id']) ?>">Add</button>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
<?php endif;
$collection_html = ob_get_clean();
$col_s->close();

// ── Deck contents panel ───────────────────────────────────────────────────────
$deck_q = "SELECT c.id, c.name, c.mana_cost, c.type_line,
                  dc.quantity, dc.is_sideboard,
                  uc.quantity as owned
           FROM deck_cards dc
           JOIN cards c ON dc.card_id = c.id
           LEFT JOIN user_collection uc ON uc.card_id = c.id AND uc.user_id = ?
           WHERE dc.deck_id = ?
           ORDER BY dc.is_sideboard, c.name";
$deck_s = $dbc->prepare($deck_q);
$deck_s->bind_param("ii", $user_id, $deck_id);
$deck_s->execute();
$deck_result = $deck_s->get_result();

// Total card count for header badge
$tot_s = $dbc->prepare("SELECT SUM(quantity) as t FROM deck_cards WHERE deck_id = ?");
$tot_s->bind_param("i", $deck_id);
$tot_s->execute();
$total_cards = (int)($tot_s->get_result()->fetch_assoc()['t'] ?? 0);
$tot_s->close();

ob_start();
if ($deck_result->num_rows == 0): ?>
    <p style="color:#8899aa;">This deck is empty. Add cards from your collection on the left.</p>
<?php else: ?>
    <table class="table table-sm table-hover" style="color:#e8e8e8;">
        <thead>
            <tr style="color:#c9a227;">
                <th>Card</th><th>Mana</th><th>Type</th><th>Qty</th><th>Side?</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($card = $deck_result->fetch_assoc()):
            $owned = (int)($card['owned'] ?? 0);
        ?>
            <tr>
                <td title="<?= htmlspecialchars($card['name']) ?>"><?= htmlspecialchars(truncate($card['name'])) ?></td>
                <td><?= htmlspecialchars($card['mana_cost'] ?? '—') ?></td>
                <td title="<?= htmlspecialchars($card['type_line']) ?>"><?= htmlspecialchars(truncate($card['type_line'], 12)) ?></td>
                <td>
                    <?= $card['quantity'] ?>
                    <?php if ($owned > 0): ?>
                        <span style="color:#8899aa;font-size:0.78rem;" title="You own <?= $owned ?>">/ <?= $owned ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $card['is_sideboard'] ? 'Yes' : 'Main' ?></td>
                <td>
                    <div class="d-flex flex-column gap-1 align-items-end">
                        <button class="btn btn-sm btn-danger remove-from-deck-btn" style="min-width:130px;"
                                data-deck-id="<?= $deck_id ?>"
                                data-card-id="<?= htmlspecialchars($card['id']) ?>">Remove</button>
                        <div class="d-flex gap-1">
                            <input type="number" class="deck-update-qty form-control form-control-sm"
                                   value="<?= $card['quantity'] ?>" min="0"
                                   max="<?= $owned ?: '' ?>" style="width:60px;"
                                   title="Max <?= $owned ?> owned">
                            <button class="btn btn-sm btn-success update-deck-btn" style="min-width:130px;"
                                    data-deck-id="<?= $deck_id ?>"
                                    data-card-id="<?= htmlspecialchars($card['id']) ?>">Update</button>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
<?php endif;
$deck_html = ob_get_clean();
$deck_s->close();
$dbc->close();

echo json_encode([
    'collection_html' => $collection_html,
    'deck_html'       => $deck_html,
    'total_cards'     => $total_cards,
]);
?>
