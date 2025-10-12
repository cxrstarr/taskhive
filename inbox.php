<?php
session_start();
require_once 'database.php';
require_once 'flash.php';

if (empty($_SESSION['user_id'])) {
    flash_set('error','Login required.');
    header("Location: login.php"); exit;
}

$db       = new database();
$user_id  = (int)$_SESSION['user_id'];
$user     = $db->getUser($user_id);
$isClient = $user && $user['user_type']==='client';
$isFree   = $user && $user['user_type']==='freelancer';

$convs    = $db->listConversationsWithUnread($user_id, 200, 0);
$unreadTotal = $db->countUnreadMessages($user_id);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Inbox - TaskHive</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { background:#f6f7fb; }
    .toolbar { display:flex; justify-content:space-between; align-items:center; margin:18px 0; }
    .inbox-card {
      background:#fff; border:1px solid #eceef4; border-radius:12px; padding:14px 16px;
      display:flex; gap:12px; align-items:center; text-decoration:none; color:inherit;
      transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
    }
    .inbox-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.08); border-color:#e3e6ef; }
    .inbox-avatar { width:44px; height:44px; border-radius:50%; object-fit:cover; flex:0 0 44px; }
    .inbox-main { flex:1; min-width:0; }
    .inbox-top { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .name { font-weight:600; }
    .snippet { color:#6b7280; font-size: 0.92rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 100%; }
    .meta { color:#8a8f99; font-size: 0.8rem; }
    .badge-unread { background:#dc3545; border-radius:999px; padding:2px 8px; font-weight:600; font-size:.75rem; }
    .type-pill { background:#eef2ff; color:#3949ab; border-radius:999px; padding:2px 8px; font-size:.7rem; margin-left:8px; }
    nav.navbar { background:#1f2328 !important; }
  </style>
</head>
<body>
<nav class="navbar navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand text-warning" href="mainpage.php">TaskHive 🐝</a>
    <div class="d-flex">
      <a href="mainpage.php#feed" class="btn btn-sm btn-outline-light">Back to Feed</a>
    </div>
  </div>
</nav>

<div class="container py-3">
  <div class="toolbar">
    <h3 class="mb-0">Inbox</h3>
    <span id="unreadTotalBadge" class="badge bg-dark">Unread: <?= $unreadTotal; ?></span>
  </div>

  <?php if ($convs): ?>
    <div id="inboxList" class="vstack gap-2">
      <?php foreach($convs as $c):
        $isClientSide = ($c['client_id']==$user_id);
        $otherName = $isClientSide
            ? ($c['free_first'].' '.$c['free_last'])
            : ($c['client_first'].' '.$c['client_last']);
        $otherPic = $isClientSide ? ($c['free_pic'] ?: 'img/client1.webp') : ($c['client_pic'] ?: ['img/client1.webp']);
        $lastBody = $c['last_body'] ? strip_tags($c['last_body']) : '(no messages yet)';
        $lastBody = mb_substr($lastBody,0,120).(strlen($lastBody)>120?'…':'');
        $unread   = (int)$c['unread_count'];
        $typeLabel = $c['conversation_type'] === 'booking' ? 'Booking' : 'General';
      ?>
        <a href="conversation.php?id=<?= (int)$c['conversation_id']; ?>" class="inbox-card" data-cid="<?= (int)$c['conversation_id']; ?>">
          <img src="<?= htmlspecialchars($otherPic); ?>" class="inbox-avatar" alt="avatar">
          <div class="inbox-main">
            <div class="inbox-top">
              <div class="d-flex align-items-center">
                <span class="name"><?= htmlspecialchars($otherName); ?></span>
                <span class="type-pill"><?= htmlspecialchars($typeLabel) ?></span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <?php if ($unread > 0): ?>
                  <span class="badge-unread"><?= $unread; ?></span>
                <?php endif; ?>
                <span class="meta"><?= htmlspecialchars($c['last_message_at'] ?: 'No messages yet'); ?></span>
              </div>
            </div>
            <div class="snippet mt-1"><?= htmlspecialchars($lastBody); ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <div id="inboxEmpty" class="alert alert-light border d-none">No conversations yet.</div>
  <?php else: ?>
    <div id="inboxList" class="vstack gap-2"></div>
    <div id="inboxEmpty" class="alert alert-light border">No conversations yet.</div>
  <?php endif; ?>
</div>

<?= flash_render(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const listRoot   = document.getElementById('inboxList');
  const emptyBox   = document.getElementById('inboxEmpty');
  const unreadBadge= document.getElementById('unreadTotalBadge');

  function esc(s){
    return String(s ?? '')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  function buildCard(c){
    const href = 'conversation.php?id='+encodeURIComponent(c.conversation_id);
    const unread = parseInt(c.unread_count,10) || 0;
    const unreadHtml = unread > 0 ? `<span class="badge-unread">${unread}</span>` : '';
    const typeLabel = esc(c.type_label || (c.type==='booking'?'Booking':'General'));
    const otherPic = esc(c.other_pic || 'img/client1.webp');
    const otherName= esc(c.other_name || 'User');
    const when     = esc(c.last_message_at || 'No messages yet');
    const snippet  = esc(c.last_body || '(no messages yet)');

    return `
      <a href="${href}" class="inbox-card" data-cid="${c.conversation_id}">
        <img src="${otherPic}" class="inbox-avatar" alt="avatar">
        <div class="inbox-main">
          <div class="inbox-top">
            <div class="d-flex align-items-center">
              <span class="name">${otherName}</span>
              <span class="type-pill">${typeLabel}</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              ${unreadHtml}
              <span class="meta">${when}</span>
            </div>
          </div>
          <div class="snippet mt-1">${snippet}</div>
        </div>
      </a>
    `;
  }

  async function refreshInbox(){
    try {
      const res = await fetch('inbox_data.php', {cache:'no-store'});
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      // Update unread total
      if (unreadBadge) unreadBadge.textContent = 'Unread: ' + (data.unread_total ?? 0);

      const items = Array.isArray(data.conversations) ? data.conversations : [];
      if (items.length === 0) {
        if (listRoot) listRoot.innerHTML = '';
        if (emptyBox) emptyBox.classList.remove('d-none');
        return;
      }
      if (emptyBox) emptyBox.classList.add('d-none');

      // Rebuild list (simple + reliable)
      const html = items.map(buildCard).join('');
      if (listRoot) listRoot.innerHTML = html;
    } catch (e) {
      // silent
    }
  }

  // Initial and interval refresh
  refreshInbox();
  setInterval(refreshInbox, 4000);
})();
</script>
</body>
</html>