<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/connect.php';
$user_id   = isLoggedIn() ? getUserId() : null;

$formats = ['Standard','Pioneer','Modern','Legacy','Vintage','Commander','Pauper','Historic','Explorer','Alchemy'];

$search = trim($_GET['search'] ?? '');
$filter_format = trim($_GET['format'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = '(t.name LIKE ? OR p.username LIKE ?)';
    $like     = '%' . $dbc->real_escape_string($search) . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if ($filter_format !== '') {
    $where[]  = 't.format = ?';
    $params[] = $filter_format;
    $types   .= 's';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT t.id, t.share_code, t.name, t.description, t.format,
               t.total_cards, t.fork_count, t.created_at,
               p.username AS creator_name,
               ud.deck_id AS user_fork_deck_id
        FROM deck_templates t
        JOIN player p ON p.id = t.creator_user_id
        LEFT JOIN user_decks ud ON ud.template_id = t.id AND ud.user_id = ?
        $where_sql
        ORDER BY t.fork_count DESC, t.created_at DESC
        LIMIT 100";

array_unshift($params, $user_id ?? 0);
$types = 'i' . $types;

$stmt = $dbc->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="container my-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="mb-0"><i class="bi bi-journal-bookmark-fill me-2" style="color:#c9a227;"></i>Deck Templates</h1>
        <?php if (isLoggedIn()): ?>
            <a href="decks.php" class="btn btn-sm btn-outline-secondary">← My Decks</a>
        <?php endif; ?>
    </div>

    <!-- Search / filter bar -->
    <form method="get" action="templates.php" class="row g-2 mb-4">
        <div class="col-sm-6">
            <input type="text" name="search" class="form-control"
                   placeholder="Search by name or creator…"
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-sm-3">
            <select name="format" class="form-select">
                <option value="">All formats</option>
                <?php foreach ($formats as $f): ?>
                    <option value="<?= $f ?>" <?= $filter_format === $f ? 'selected' : '' ?>><?= $f ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
            <?php if ($search || $filter_format): ?>
                <a href="templates.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (empty($templates)): ?>
        <div class="alert alert-info">
            No templates found<?= ($search || $filter_format) ? ' matching your filters' : '' ?>.
            <?php if (isLoggedIn()): ?>
                Publish one from the <a href="decks.php" style="color:#c9a227;">deck editor</a>.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-4">
        <?php foreach ($templates as $tpl): ?>
            <div class="col">
                <div class="card h-100 shadow-sm" style="border-top:3px solid #c9a227;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <h5 class="card-title mb-1" style="color:#e8e8e8;">
                                <?= htmlspecialchars($tpl['name']) ?>
                            </h5>
                            <?php if ($tpl['format']): ?>
                                <span class="badge flex-shrink-0"
                                      style="background:rgba(201,162,39,0.18);color:#c9a227;border:1px solid rgba(201,162,39,0.35);">
                                    <?= htmlspecialchars($tpl['format']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="small mb-2" style="color:#8899aa;">
                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($tpl['creator_name']) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-stack me-1"></i><?= $tpl['total_cards'] ?> cards
                            &nbsp;·&nbsp;
                            <i class="bi bi-diagram-2 me-1"></i><?= $tpl['fork_count'] ?> forks
                        </p>
                        <?php if ($tpl['description']): ?>
                            <p class="card-text small" style="color:#b0b8c8;">
                                <?php $d = $tpl['description']; echo htmlspecialchars(strlen($d) > 100 ? substr($d, 0, 100) . '…' : $d); ?>
                            </p>
                        <?php endif; ?>
                        <p class="small mb-0" style="color:#556677;">
                            <code style="font-size:0.78rem;color:#8899aa;"><?= htmlspecialchars($tpl['share_code']) ?></code>
                            &nbsp;·&nbsp;<?= date('M j, Y', strtotime($tpl['created_at'])) ?>
                        </p>
                    </div>
                    <div class="card-footer bg-transparent">
                        <?php if ($tpl['user_fork_deck_id']): ?>
                            <a href="deck_editor.php?deck_id=<?= $tpl['user_fork_deck_id'] ?>"
                               class="btn btn-sm btn-outline-success w-100">
                                <i class="bi bi-check-circle me-1"></i>Already forked — Edit my copy
                            </a>
                        <?php elseif (isLoggedIn()): ?>
                            <button class="btn btn-sm btn-warning w-100 fork-btn"
                                    data-template-id="<?= $tpl['id'] ?>"
                                    data-template-name="<?= htmlspecialchars($tpl['name']) ?>">
                                <i class="bi bi-diagram-2 me-1"></i>Fork to My Decks
                            </button>
                        <?php else: ?>
                            <a href="index.php" class="btn btn-sm btn-outline-secondary w-100">
                                Log in to fork
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.fork-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
        const templateId   = this.dataset.templateId;
        const templateName = this.dataset.templateName;
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Forking…';

        const fd = new FormData();
        fd.append('template_id', templateId);

        try {
            const res  = await fetch('ajax/fork_template.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.already_forked) {
                window.location.href = 'deck_editor.php?deck_id=' + data.deck_id;
            } else if (data.success) {
                window.location.href = 'deck_editor.php?deck_id=' + data.deck_id + '&msg=forked';
            } else {
                alert(data.error || 'Fork failed');
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-diagram-2 me-1"></i>Fork to My Decks';
            }
        } catch (_) {
            alert('Network error — please try again.');
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-diagram-2 me-1"></i>Fork to My Decks';
        }
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
