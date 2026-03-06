<?php
include __DIR__ . '/includes/header.php';
if (!isLoggedIn()) {
    global $_session_kicked;
    $msg = $_session_kicked
        ? "You+were+signed+in+elsewhere.+This+session+has+ended."
        : "Please+log+in+to+continue.";
    header("Location: index.php?error=" . $msg);
    exit();
}
?>

<div class="container my-4" style="max-width:760px;">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="collection.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Collection
        </a>
        <h1 class="mb-0">Bulk Import Cards</h1>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p style="color:#8899aa;" class="mb-2">
                Paste a card list below — one card per line. Supports MTGO, Arena, and plain formats:
            </p>
            <ul class="small mb-3" style="color:#8899aa;">
                <li><code style="color:#c9a227;">4 Lightning Bolt</code></li>
                <li><code style="color:#c9a227;">4x Counterspell</code></li>
                <li><code style="color:#c9a227;">Brainstorm</code> &nbsp;(assumes 1 copy)</li>
                <li>Lines starting with <code style="color:#8899aa;">//</code> or <code style="color:#8899aa;">#</code> are ignored</li>
            </ul>

            <textarea id="import-input" class="form-control mb-3"
                      rows="14" placeholder="4 Lightning Bolt&#10;4x Counterspell&#10;1 Black Lotus&#10;..."
                      style="font-family:monospace;font-size:0.9rem;background:#0d0d1a;color:#e8e8e8;border-color:rgba(201,162,39,0.3);resize:vertical;"></textarea>

            <div class="d-flex gap-2">
                <button type="button" id="import-btn" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-down me-1"></i>Import to Collection
                </button>
                <button type="button" id="clear-btn" class="btn btn-outline-secondary">Clear</button>
            </div>
        </div>
    </div>

    <!-- Results -->
    <div id="import-results" style="display:none;">
        <h5 class="mb-3" style="color:#c9a227;"><i class="bi bi-list-check me-2"></i>Import Results</h5>

        <div id="results-summary" class="d-flex gap-3 mb-3 flex-wrap"></div>

        <div id="results-found" style="display:none;" class="mb-4">
            <h6 class="mb-2" style="color:#4ade80;"><i class="bi bi-check-circle me-1"></i>Added to Collection</h6>
            <div class="table-responsive">
                <table class="table table-sm" style="font-size:0.85rem;">
                    <thead><tr style="color:#8899aa;">
                        <th>Card</th><th class="text-center">Qty</th><th>Type</th>
                    </tr></thead>
                    <tbody id="found-tbody"></tbody>
                </table>
            </div>
        </div>

        <div id="results-not-found" style="display:none;">
            <h6 class="mb-2" style="color:#f87171;"><i class="bi bi-exclamation-circle me-1"></i>Not Found</h6>
            <ul id="not-found-list" class="list-unstyled small" style="color:#f87171;"></ul>
        </div>
    </div>
</div>

<script>
document.getElementById('clear-btn').addEventListener('click', () => {
    document.getElementById('import-input').value = '';
    document.getElementById('import-results').style.display = 'none';
});

document.getElementById('import-btn').addEventListener('click', async function () {
    const text = document.getElementById('import-input').value.trim();
    if (!text) return;

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing…';

    const fd = new FormData();
    fd.append('list', text);

    try {
        const res  = await fetch('ajax/bulk_import_collection.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        // Summary badges
        const sumEl = document.getElementById('results-summary');
        sumEl.innerHTML = `
            <span class="badge px-3 py-2" style="background:rgba(74,222,128,0.15);color:#4ade80;border:1px solid rgba(74,222,128,0.3);font-size:0.9rem;">
                ${data.added} added
            </span>
            ${data.skipped > 0 ? `<span class="badge px-3 py-2" style="background:rgba(248,113,113,0.15);color:#f87171;border:1px solid rgba(248,113,113,0.3);font-size:0.9rem;">${data.skipped} not found</span>` : ''}
            ${data.lines_parsed > 0 ? `<span class="badge px-3 py-2" style="background:rgba(136,153,170,0.1);color:#8899aa;border:1px solid rgba(136,153,170,0.2);font-size:0.9rem;">${data.lines_parsed} lines parsed</span>` : ''}
        `;

        // Found table
        const foundTbody = document.getElementById('found-tbody');
        foundTbody.innerHTML = '';
        if (data.found && data.found.length > 0) {
            document.getElementById('results-found').style.display = '';
            data.found.forEach(card => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
                tr.innerHTML = `
                    <td style="color:#e8e8e8;">${escHtml(card.name)}</td>
                    <td class="text-center" style="color:#c9a227;">${card.quantity}</td>
                    <td style="color:#8899aa;">${escHtml(card.type_line)}</td>
                `;
                foundTbody.appendChild(tr);
            });
        } else {
            document.getElementById('results-found').style.display = 'none';
        }

        // Not found list
        const nfList = document.getElementById('not-found-list');
        nfList.innerHTML = '';
        if (data.not_found && data.not_found.length > 0) {
            document.getElementById('results-not-found').style.display = '';
            data.not_found.forEach(name => {
                const li = document.createElement('li');
                li.textContent = name;
                nfList.appendChild(li);
            });
        } else {
            document.getElementById('results-not-found').style.display = 'none';
        }

        document.getElementById('import-results').style.display = '';

    } catch (_) {
        alert('Network error — please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-box-arrow-in-down me-1"></i>Import to Collection';
    }
});

function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
