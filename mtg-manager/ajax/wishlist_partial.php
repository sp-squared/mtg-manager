<?php
ini_set('display_errors', 0);
ob_start();
session_start();
include __DIR__ . '/../includes/connect.php';
include __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { ob_end_clean(); echo json_encode(['error' => 'Not logged in']); exit(); }
requireCsrf();

$user_id = getUserId();

$results_per_page = 52;
$page   = isset($_POST['page']) && is_numeric($_POST['page']) ? (int)$_POST['page'] : 1;
$offset = ($page - 1) * $results_per_page;

$count_stmt = $dbc->prepare("SELECT COUNT(*) as total FROM wishlist WHERE user_id = ?");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$total_results = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages   = (int)ceil($total_results / $results_per_page);
$count_stmt->close();

$stmt = $dbc->prepare(
    "SELECT w.card_id, w.priority, c.name, c.mana_cost, c.type_line, c.image_uri, c.rarity
     FROM wishlist w
     JOIN cards c ON w.card_id = c.id
     WHERE w.user_id = ?
     ORDER BY w.priority DESC, c.name
     LIMIT ? OFFSET ?"
);
$stmt->bind_param("iii", $user_id, $results_per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();

ob_start();
while ($row = $result->fetch_assoc()):
    $r           = $row['rarity'] ?? 'common';
    $badge_label = ucfirst($r);
    $pri         = (int)$row['priority'];
    $pri_class   = $pri === 3 ? 'high' : ($pri === 2 ? 'medium' : 'low');
    $pri_label   = getPriorityLabel($pri);
?>
<div class="col">
    <div class="card h-100 shadow-sm rarity-<?= htmlspecialchars($r) ?>">
        <?php if ($row['image_uri']): ?>
            <img src="<?= htmlspecialchars($row['image_uri']) ?>" class="card-img-top"
                 alt="<?= htmlspecialchars($row['name']) ?>"
                 style="height:200px;object-fit:contain;background:#0d0d1a;">
        <?php else: ?>
            <div class="card-img-top d-flex align-items-center justify-content-center bg-dark" style="height:200px;">
                <span class="text-muted">No Image</span>
            </div>
        <?php endif; ?>
        <div class="card-body">
            <h5 class="card-title">
                <?= htmlspecialchars($row['name']) ?>
                <span class="badge badge-rarity-<?= $r ?> float-end small"><?= $badge_label ?></span>
            </h5>
            <p class="card-text small">
                <strong>Mana:</strong> <?= htmlspecialchars($row['mana_cost'] ?? '—') ?><br>
                <strong>Type:</strong> <?= htmlspecialchars($row['type_line']) ?><br>
                <strong>Priority:</strong>
                <span class="priority-<?= $pri_class ?>"><?= htmlspecialchars($pri_label) ?></span>
            </p>
        </div>
        <div class="card-footer bg-transparent">
            <form class="priority-form mb-2"
                  data-card-id="<?= $row['card_id'] ?>"
                  data-card-name="<?= htmlspecialchars($row['name']) ?>"
                  data-current-priority="<?= $pri ?>">
                <input type="hidden" name="card_id" value="<?= $row['card_id'] ?>">
                <input type="hidden" name="ajax"    value="1">
                <div class="input-group input-group-sm">
                    <select class="form-select" name="priority">
                        <option value="1" <?= $pri === 1 ? 'selected' : '' ?>>Low</option>
                        <option value="2" <?= $pri === 2 ? 'selected' : '' ?>>Medium</option>
                        <option value="3" <?= $pri === 3 ? 'selected' : '' ?>>High</option>
                    </select>
                    <button class="btn btn-success" type="submit">Update</button>
                </div>
            </form>
            <form class="remove-wishlist-form">
                <input type="hidden" name="card_id" value="<?= $row['card_id'] ?>">
                <button class="btn btn-danger w-100" type="submit">Remove</button>
            </form>
        </div>
    </div>
</div>
<?php endwhile;
$grid_html = ob_get_clean();
$stmt->close();
$dbc->close();

ob_end_clean(); echo json_encode([
    'grid_html'     => $grid_html,
    'total_results' => $total_results,
    'total_pages'   => $total_pages,
    'page'          => $page,
]);
?>
