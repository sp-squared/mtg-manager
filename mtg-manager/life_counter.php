<?php include __DIR__ . '/includes/header.php'; ?>

<style>
/* ── Life Counter — full viewport, no scroll ── */
html, body { overflow: hidden; }

.lc-wrap {
    display: flex;
    flex-direction: column;
    height: calc(100dvh - 57px); /* 57px = navbar */
}

/* ── Player zones ── */
.player-zone {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
    user-select: none;
    touch-action: manipulation;
    transition: background 0.3s;
}

.player-zone.p1 { background: linear-gradient(160deg, #7f1d1d 0%, #b91c1c 100%); }
.player-zone.p2 {
    background: linear-gradient(160deg, #1e3a5f 0%, #1d4ed8 100%);
    transform: rotate(180deg);
}

/* ── Life total ── */
.life-total {
    font-size: clamp(4rem, 16vw, 8rem);
    font-weight: 900;
    color: #ffffff;
    line-height: 1;
    text-shadow: 0 3px 12px rgba(0,0,0,0.5);
    letter-spacing: -2px;
}
.life-total.low  { color: #fbbf24; }
.life-total.dead { color: #6b7280; text-decoration: line-through; }

.player-label {
    font-size: clamp(0.7rem, 2.5vw, 0.95rem);
    color: rgba(255,255,255,0.5);
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 0.4rem;
}

/* ── +/- buttons ── */
.btn-row {
    display: flex;
    gap: clamp(0.75rem, 4vw, 2rem);
    margin-top: clamp(0.75rem, 3vw, 1.5rem);
}

.life-btn {
    width: clamp(56px, 14vw, 72px);
    height: clamp(56px, 14vw, 72px);
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.35);
    background: rgba(255,255,255,0.12);
    color: #ffffff;
    font-size: clamp(1.4rem, 5vw, 2rem);
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.1s, transform 0.05s;
}
.life-btn:active {
    background: rgba(255,255,255,0.3);
    transform: scale(0.94);
}

/* ── ±5 small buttons ── */
.btn-sm-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.6rem;
}
.life-btn-sm {
    padding: 4px 14px;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.25);
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.75);
    font-size: 0.8rem;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.1s;
}
.life-btn-sm:active { background: rgba(255,255,255,0.22); }

/* ── Middle divider / reset bar ── */
.lc-divider {
    height: 44px;
    background: linear-gradient(90deg, #0d0d1a 0%, #1c1c3a 50%, #0d0d1a 100%);
    border-top: 2px solid #c9a227;
    border-bottom: 2px solid #c9a227;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    flex-shrink: 0;
    z-index: 10;
}

.reset-btn {
    background: rgba(201,162,39,0.85);
    border: none;
    border-radius: 14px;
    padding: 4px 16px;
    font-size: 0.78rem;
    font-weight: 700;
    color: #0d0d1a;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.1s;
}
.reset-btn:active { background: #c9a227; }
.reset-btn.cmd { background: rgba(201,162,39,0.45); color: #e8e8e8; }
.reset-btn.cmd:active { background: rgba(201,162,39,0.7); }
</style>

<div class="lc-wrap">

    <!-- Player 2 (top — rotated so the opposing player can read it) -->
    <div class="player-zone p2" id="zone-p2">
        <div class="player-label">Player 2</div>
        <div class="life-total" id="life-p2">20</div>
        <div class="btn-row">
            <button class="life-btn" ontouchstart="" onclick="changeLife('p2',-1)">−</button>
            <button class="life-btn" ontouchstart="" onclick="changeLife('p2',+1)">+</button>
        </div>
        <div class="btn-sm-row">
            <button class="life-btn-sm" ontouchstart="" onclick="changeLife('p2',-5)">−5</button>
            <button class="life-btn-sm" ontouchstart="" onclick="changeLife('p2',+5)">+5</button>
        </div>
    </div>

    <!-- Divider / reset controls -->
    <div class="lc-divider">
        <button class="reset-btn cmd" onclick="resetAll(40)" title="Commander — start at 40">40 ↺</button>
        <button class="reset-btn" onclick="resetAll(20)" title="Standard — start at 20">20 ↺ Reset</button>
    </div>

    <!-- Player 1 (bottom — normal orientation) -->
    <div class="player-zone p1" id="zone-p1">
        <div class="player-label">Player 1</div>
        <div class="life-total" id="life-p1">20</div>
        <div class="btn-row">
            <button class="life-btn" ontouchstart="" onclick="changeLife('p1',-1)">−</button>
            <button class="life-btn" ontouchstart="" onclick="changeLife('p1',+1)">+</button>
        </div>
        <div class="btn-sm-row">
            <button class="life-btn-sm" ontouchstart="" onclick="changeLife('p1',-5)">−5</button>
            <button class="life-btn-sm" ontouchstart="" onclick="changeLife('p1',+5)">+5</button>
        </div>
    </div>

</div>

<script>
const lives = { p1: 20, p2: 20 };

function changeLife(player, delta) {
    lives[player] = Math.max(0, lives[player] + delta);
    const el = document.getElementById('life-' + player);
    el.textContent = lives[player];
    el.className = 'life-total' +
        (lives[player] === 0 ? ' dead' : lives[player] <= 5 ? ' low' : '');
}

function resetAll(start = 20) {
    ['p1','p2'].forEach(p => {
        lives[p] = start;
        const el = document.getElementById('life-' + p);
        el.textContent = start;
        el.className = 'life-total';
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
