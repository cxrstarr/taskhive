<?php
session_start();

// Guard: require login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__.'/database.php';
require_once __DIR__.'/flash.php';
require_once __DIR__ . '/includes/csrf.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$db  = new database();
$pdo = $db->opencon();
$userId = (int)$_SESSION['user_id'];

// Handle AJAX actions (mark read, mark all read)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (!csrf_validate()) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
    header('Content-Type: application/json');

    if ($_POST['action'] === 'mark_read' && isset($_POST['conversation_id'])) {
        try {
            $cid = (int)$_POST['conversation_id'];
            $ok  = $db->markConversationMessagesRead($cid, $userId);
            echo json_encode(['success' => (bool)$ok]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    if ($_POST['action'] === 'mark_all_read') {
        try {
            $rows = $db->listConversationsWithUnread($userId, 500, 0);
            foreach ($rows as $c) {
                $db->markConversationMessagesRead((int)$c['conversation_id'], $userId);
            }
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit();
}

// Build sidebar conversation list
try {
    $rows = $db->listConversationsWithUnread($userId, 200, 0);
    $conversations = [];
    foreach ($rows as $c) {
        $isClientSide = ((int)$c['client_id'] === $userId);
        $otherName = $isClientSide
            ? trim(($c['free_first'] ?? '').' '.($c['free_last'] ?? ''))
            : trim(($c['client_first'] ?? '').' '.($c['client_last'] ?? ''));
        $lastBody = $c['last_body'] ? strip_tags($c['last_body']) : '(no messages yet)';
        if (mb_strlen($lastBody) > 160) { $lastBody = mb_substr($lastBody,0,160).'…'; }
        $statusLabel = ($c['conversation_type'] === 'booking') ? 'Booking' : 'General';
        $conversations[] = [
            'id'           => (int)$c['conversation_id'],
            'sender_name'  => $otherName ?: 'User',
            'sender_avatar'=> $isClientSide ? ($c['free_pic'] ?? null) : ($c['client_pic'] ?? null),
            'message'      => $lastBody,
            'created_at'   => $c['last_message_at'] ?: date('Y-m-d H:i:s'),
            'status'       => $statusLabel,
            'is_read'      => ((int)$c['unread_count'] > 0) ? 0 : 1,
            '_raw'         => $c,
        ];
    }
} catch (Throwable $e) {
    $conversations = [];
}

// Selected conversation (if any)
$selectedId = (int)($_GET['id'] ?? 0);
$selectedConv = null;
$chatMessages = [];
$otherName = 'User';
$otherPic  = 'img/client1.webp';

if ($selectedId > 0) {
    // Load conversation row
    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE conversation_id = :id LIMIT 1");
    $stmt->execute([':id'=>$selectedId]);
    $selectedConv = $stmt->fetch();

    if (!$selectedConv) {
        $selectedId = 0;
    } else {
        // Access check
        if ($selectedConv['client_id'] != $userId && $selectedConv['freelancer_id'] != $userId) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }

        // Derive other participant from sidebar preload (if available)
        foreach ($conversations as $cv) {
            if ($cv['id'] === $selectedId) {
                $otherName = $cv['sender_name'] ?: $otherName;
                $otherPic  = $cv['sender_avatar'] ?: $otherPic;
                break;
            }
        }

        // Fallback: try to fetch other participant details if not found
        if ($otherName === 'User' || !$otherPic) {
            $otherId = ((int)$selectedConv['client_id'] === $userId) ? (int)$selectedConv['freelancer_id'] : (int)$selectedConv['client_id'];
            $u = $db->getUser($otherId);
            if ($u) {
                $otherName = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: $otherName;
                $otherPic  = $u['profile_picture'] ?: $otherPic;
            }
        }

        // Load messages (ascending for chat)
        try {
      $msgs = $db->getConversationMessages($selectedId, 40, 0);
            // Ensure ascending order
            if (!empty($msgs)) {
                // Try to infer order: if first created_at > last, reverse
                $first = strtotime($msgs[0]['created_at'] ?? 'now');
                $last  = strtotime($msgs[count($msgs)-1]['created_at'] ?? 'now');
                if ($first > $last) $msgs = array_reverse($msgs);
            }
            $chatMessages = $msgs;
        } catch (Throwable $e) {
            $chatMessages = [];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/bee.jpg">
  <meta charset="UTF-8">
  <title>Conversation - TaskHive</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <!-- Chat overrides -->
  <link rel="stylesheet" href="public/css/chat_overrides.css">
  <style <?= function_exists('csp_style_nonce_attr') ? csp_style_nonce_attr() : '' ?> >
    html, body { height: 100%; }
    body { background:#FFFBEB; }

    /* NEW: lock the page scroll, keep scrolling inside panes only */
    html, body { overflow: hidden; }

    /* NEW: remove container vertical padding to avoid forcing page scroll */
    .container.py-4 { padding-top: 0 !important; padding-bottom: 0 !important; }

    .messages-card {
      border: 1px solid #F3F4F6; border-radius: 0.75rem; background: white;
      /* Fill the viewport and keep the outer page fixed */
      height: 100vh;              /* NEW: full viewport height */
      overflow: hidden;           /* prevent page-level scrollbars from nested content */
    }
    .inbox-grid {
      display: grid;
      grid-template-columns: 300px 1fr; /* narrower sidebar -> wider chat/input */
      gap: 0;
      height: 100%; /* fill parent fixed height */
    }

    .messages-sidebar {
      border-right: 1px solid #F3F4F6;
      height: 100%;
      overflow: auto; /* independent scroll for contacts list */
      padding: 1rem; /* match feed spacing */
      background: #fff;
      border-top-left-radius: .75rem;
      border-bottom-left-radius: .75rem;
    }
    .chat-pane {
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 0; /* allow children to size with flex */
      border-top-right-radius: .75rem;
      border-bottom-right-radius: .75rem;
    }
    .chat-header {
      padding: 1rem 1.25rem; /* match feed spacing */
      border-bottom: 1px solid #F3F4F6;
      display: flex; align-items: center; gap: .75rem;
      background: #fff;
      border-top-right-radius: .75rem;
      flex: 0 0 auto;
    }
    .chat-scroll {
      flex: 1 1 auto;           /* take remaining vertical space */
      min-height: 0;
      overflow: auto;            /* independent scroll for messages */
      padding: 1.25rem;          /* a bit more breathing room like feed */
      background: #FFFBEB;
    }

    .composer {
      padding: 1rem 1.125rem;    /* match feed spacing */
      border-top: 1px solid #F3F4F6;
      background: white;
      display: flex;
      gap: 0.75rem;              /* comfortable spacing */
      align-items: center;
      border-bottom-right-radius: .75rem;
      flex: 0 0 auto;
      position: sticky;
      bottom: 0;
      z-index: 2;
    }

    /* NEW: maximize message input width within the row */
    #messageBody {
      flex: 1 1 auto;
      min-width: 0;              /* allow flex shrinking so it uses all available space */
      width: 100%;               /* ensure it stretches end-to-end in its track */
      border:2px solid #E5E7EB; border-radius:0.75rem; padding:1rem 1.125rem;
      font-size: 1.0625rem; line-height: 1.5;
      min-height: 96px;          /* bigger default height */
      max-height: 280px;         /* keep it sensible */
      resize: none;              /* handled via JS autosize */
      background: #fff;
    }
    .composer .btn.btn-primary { white-space: nowrap; }
    .composer > label { flex: 0 0 auto; }
    .composer > button[type="submit"] { flex: 0 0 auto; }

    .avatar, .inbox-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border:2px solid #FDE68A; }
    .message-item {
      padding: 0.875rem;         /* slightly larger to match feed */
      border: 1px solid #F3F4F6;
      border-radius: 0.75rem;
      background: #fff;
      margin-bottom: 0.5rem;
      cursor: pointer;
    }
    .message-item.selected {
      background: #FFFBEB;       /* subtle active feel, matches feed palette */
      border-color: #FDE68A;
    }
    .message-item .sender-name { font-weight: 600; color: #374151; }
    .message-item .message-snippet { font-size: .9rem; color: #6B7280; }
    .message-item .timestamp { font-size: .80rem; color: #6B7280; }
    .unread-dot { display:inline-block; width:8px; height:8px; background:#EF4444; border-radius:999px; margin-left:6px; }

    /* Message bubbles */
    .bubble {
      max-width: 72%;
      padding: .5rem .75rem;
      border-radius: .75rem;
      border: 1px solid #F3F4F6;
      background: #fff;
      color: #374151;
      box-shadow: 0 1px 0 rgba(0,0,0,0.03);
    }
    .row-me   { display:flex; justify-content:flex-end; margin:.5rem 0; }
    .row-oth  { display:flex; justify-content:flex-start; margin:.5rem 0; }
    .bubble-me {
      background: #F59E0B; color:white; border-color:#FDE68A;
    }

    /* Attachments grid inside bubbles */
    .att-grid { margin-top:0.5rem; display:flex; gap:0.5rem; flex-wrap:wrap; }
    .att-grid a img { max-width:160px; max-height:160px; border-radius:0.5rem; border:1px solid #F3F4F6; display:block; }

    /* Composer preview (IDs used by JS) */
    .preview-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:6px; }
    .preview-meta { font-size:12px; color:#6b7280; }
  </style>
</head>
<body>
  <div class="container py-4">
    <div class="messages-card">
      <div class="inbox-grid">
        <!-- Sidebar -->
        <aside class="messages-sidebar">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h5 class="mb-0">Inbox</h5>
            <form id="markAllForm" method="post" onsubmit="return false;">
              <?= csrf_input(); ?>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnMarkAll">Mark all read</button>
            </form>
          </div>

          <?php if (!empty($conversations)): ?>
            <?php foreach ($conversations as $conv):
              $selected = ($conv['id'] === $selectedId);
              $pic = $conv['sender_avatar'] ?: 'img/profile_icon.webp';
            ?>
              <a class="message-item d-block text-decoration-none <?php echo $selected ? 'selected' : ''; ?>"
                 href="conversation.php?id=<?php echo (int)$conv['id']; ?>"
                 data-id="<?php echo (int)$conv['id']; ?>"
                 data-sender="<?php echo h($conv['sender_name']); ?>"
                 data-message="<?php echo h($conv['message']); ?>"
                 data-status="<?php echo h($conv['status']); ?>"
                 data-read="<?php echo (int)$conv['is_read']; ?>">
                <div class="d-flex gap-2">
                  <img src="<?php echo h($pic); ?>" alt="avatar" class="avatar">
                  <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="sender-name">
                        <?php echo h($conv['sender_name']); ?>
                        <?php if (!(int)$conv['is_read']): ?><span class="unread-dot"></span><?php endif; ?>
                      </div>
                      <span class="timestamp">
                        <time data-epoch="<?php echo (int)strtotime($conv['created_at']); ?>"></time>
                      </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="message-snippet text-truncate" style="max-width: 70%;">
                        <?php echo h($conv['message']); ?>
                      </div>
                      <span class="badge rounded-pill text-bg-light"><?php echo h($conv['status']); ?></span>
                    </div>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-center text-muted py-4">No conversations yet.</div>
          <?php endif; ?>
        </aside>

        <!-- Chat pane -->
        <section class="chat-pane">
          <?php if ($selectedId <= 0 || !$selectedConv): ?>
            <div class="d-flex flex-column align-items-center justify-content-center text-muted" style="flex:1; padding: 3rem;">
              <i class="fa-regular fa-message fa-3x mb-3"></i>
              <div>Select a conversation to start chatting.</div>
            </div>
          <?php else: ?>
            <!-- Header -->
            <div class="chat-header">
              <img src="<?php echo h($otherPic ?: 'img/client1.webp'); ?>" alt="avatar" class="avatar">
              <div class="flex-grow-1">
                <div style="font-weight:600; color:#374151;"><?php echo h($otherName ?: 'User'); ?></div>
                <div style="font-size:12px; color:#6B7280;">Conversation #<?php echo (int)$selectedId; ?></div>
              </div>
            </div>

            <!-- Messages -->
            <div id="chat-scroll" class="chat-scroll">
                <div id="load-older-wrap" class="text-center mb-2" style="display: <?php echo empty($chatMessages) ? 'none' : 'block'; ?>;">
                  <button id="btnLoadOlder" class="btn btn-sm btn-outline-secondary">Load older</button>
                </div>
              <?php if (empty($chatMessages)): ?>
                <div class="text-center text-muted py-5">
                  <i class="fas fa-comment-dots"></i>
                  <p class="mb-0 mt-2">No messages yet. Say hello!</p>
                </div>
              <?php else: ?>
                <?php foreach ($chatMessages as $m): ?>
                  <?php
                    $mine = ((int)$m['sender_id'] === $userId);
                    $rowCls = $mine ? 'row-me' : 'row-oth';
                    $bubbleCls = $mine ? 'bubble bubble-me' : 'bubble';
                    $atts = [];
                    if (!empty($m['attachments'])) {
                        $dec = json_decode($m['attachments'], true);
                        if (is_array($dec)) $atts = $dec;
                    }
                    $createdEpoch = (int)strtotime($m['created_at'] ?? '');
                    $readAt = $m['read_at'] ?? null;
                  ?>
                    <div class="<?php echo $rowCls; ?>" id="msg-<?php echo (int)$m['message_id']; ?>" data-mid="<?php echo (int)$m['message_id']; ?>">
                    <div class="<?php echo $bubbleCls; ?>">
                      <?php if (!empty($m['body'])): ?>
                        <div style="white-space:pre-wrap; word-break:break-word;"><?php echo h($m['body']); ?></div>
                      <?php endif; ?>

                      <?php if (!empty($atts)): ?>
                        <div class="att-grid">
                          <?php foreach ($atts as $a): if (($a['type'] ?? '') === 'image' && !empty($a['url'])): ?>
                            <a href="<?php echo h($a['url']); ?>" target="_blank" rel="noopener">
                              <img src="<?php echo h($a['url']); ?>" alt="<?php echo h($a['name'] ?? 'image'); ?>">
                            </a>
                          <?php endif; endforeach; ?>
                        </div>
                      <?php endif; ?>

                      <div style="margin-top:0.25rem; font-size:12px; opacity:0.8; text-align:right;">
                        <time class="msg-time" data-epoch="<?php echo $createdEpoch; ?>"></time>
                      </div>

                      <?php if ($mine): ?>
                        <div class="msg-status" id="msg-status-<?php echo (int)$m['message_id']; ?>"
                             data-mid="<?php echo (int)$m['message_id']; ?>"
                             data-seen="<?php echo $readAt ? '1' : '0'; ?>">
                          <span class="<?php echo $readAt ? 'seen' : 'delivered'; ?>">
                            <?php echo $readAt ? 'Seen' : 'Delivered'; ?>
                          </span>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>

              <div id="bottom"></div>
            </div>

            <!-- Composer -->
            <form action="send_message.php" method="POST" enctype="multipart/form-data" class="composer" id="composerForm">
              <?php require_once __DIR__ . '/includes/csrf.php'; echo csrf_input(); ?>
              <input type="hidden" name="conversation_id" value="<?php echo (int)$selectedId; ?>">
              <label style="cursor:pointer;" title="Attach images">
                <input id="attachInput" type="file" name="images[]" accept="image/*" multiple style="display:none;">
                <i class="fas fa-paperclip" style="color:#6B7280;"></i>
              </label>
              <textarea id="messageBody" name="body" placeholder="Type a message..." rows="2"></textarea>
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send
              </button>

              <!-- Preview bar -->
              <div style="flex-basis:100%;"></div>
              <div id="previewBar" class="preview-bar"></div>
              <div id="previewMeta" class="preview-meta"></div>
            </form>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
    // Scroll chat to bottom on load (messages area has its own scrollbar)
    (function(){
      const sc = document.getElementById('chat-scroll');
      if (sc) sc.scrollTop = sc.scrollHeight;
    })();

    // Mark all as read button
    (function(){
      const btn = document.getElementById('btnMarkAll');
      if (!btn) return;
      btn.addEventListener('click', async ()=>{
        const form = document.getElementById('markAllForm');
        const fd = new FormData(form);
        fd.append('action','mark_all_read');
        try {
          await fetch('conversation.php', { method:'POST', body: fd });
          // best-effort UI update: remove unread dots
          document.querySelectorAll('.message-item [data-read="0"], .message-item .unread-dot').forEach(el=>{
            if (el.classList && el.classList.contains('unread-dot')) el.remove();
          });
        } catch(e){}
      });
    })();
  </script>
  <!-- Global Review Iframe Modal for conversation page -->
  <div id="review-iframe-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1050; align-items:center; justify-content:center;">
    <div style="width:100%; max-width:820px; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.25);">
      <div style="display:flex; align-items:center; gap:12px; border-bottom:1px solid #f0f0f0; padding:12px 16px;">
        <div style="width:40px; height:40px; background:#fff7d6; color:#f59e0b; display:grid; place-items:center; border-radius:50%;">
          <i class="fa-solid fa-star"></i>
        </div>
        <div style="flex:1;">
          <div style="font-weight:600;">Write a Review</div>
          <div style="color:#666; font-size:.9rem;">Share your experience for this booking</div>
        </div>
        <button type="button" id="review-iframe-close" class="btn btn-sm btn-light"><i class="fas fa-times"></i></button>
      </div>
      <iframe id="review-iframe" src="about:blank" style="display:block; width:100%; height:560px; border:0;" title="Leave a review"></iframe>
    </div>
  </div>
  <script src="public/js/chat_attach_preview.js"></script>
  <script src="public/js/chat_time.js"></script>
  <?php if ($selectedId > 0): ?>
  <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
    (function(){
      const cid = <?php echo (int)$selectedId; ?>;
      const myId = <?php echo (int)$userId; ?>;
      if (!cid) return;

      function markRead(){
        const fd = new FormData();
        fd.append('action','mark_read');
        fd.append('conversation_id', String(cid));
        fetch('conversation.php', { method:'POST', body: fd }).catch(()=>{});
      }

      // mark as read now and on tab focus
      markRead();
      document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) markRead(); });

      async function refreshSeen(){
        try{
          const r = await fetch('conversation_messages.php?id='+encodeURIComponent(cid));
          const j = await r.json();
          if (!j || !j.ok || !Array.isArray(j.messages)) return;
          j.messages.forEach(m=>{
            if (parseInt(m.sender_id,10) !== myId) return;
            const el = document.getElementById('msg-status-'+m.message_id);
            if (!el) return;
            const nowSeen = !!m.read_at;
            if (el.dataset.seen === '0' && nowSeen) {
              el.dataset.seen = '1';
              const span = el.querySelector('span');
              if (span) {
                span.textContent = 'Seen';
                span.classList.remove('delivered');
                span.classList.add('seen');
              } else {
                el.textContent = 'Seen';
              }
            }
          });
          if (window.renderLocalTimes) window.renderLocalTimes();
        } catch(e){}
      }
        // Helper to validate same-origin URLs
        function sameOrigin(u){
          try { const x = new URL(u, window.location.origin); return x.origin === window.location.origin; } catch(_) { return false; }
        }

        // Intercept clicks on Leave a review links inside messages, open modal iframe
        document.addEventListener('click', function(e){
          const a = e.target.closest && e.target.closest('a.leave-review-link');
          if (!a) return;
          const href = a.getAttribute('href') || '';
          if (!href) return;
          e.preventDefault();
          const url = new URL(href, window.location.origin);
          url.searchParams.set('modal','1');
          // Prefer navigating back to the previous page after cancel
          const prev = document.referrer && sameOrigin(document.referrer) ? document.referrer : '';
          window.__reviewReturnUrl = prev || window.location.href;
          url.searchParams.set('redirect', window.__reviewReturnUrl);
          const iframe = document.getElementById('review-iframe');
          const modal  = document.getElementById('review-iframe-modal');
          if (iframe && modal) {
            iframe.src = url.toString();
            modal.style.display = 'flex';
          }
        }, { passive: false });

        // Close button for iframe modal
        (function(){
          const btn = document.getElementById('review-iframe-close');
          const modal = document.getElementById('review-iframe-modal');
          const iframe = document.getElementById('review-iframe');
          if (btn && modal && iframe) {
            btn.addEventListener('click', function(){ modal.style.display='none'; iframe.src='about:blank'; });
          }
        })();

        // Listen for postMessage from leave_review.php
        window.addEventListener('message', function(ev){
          const d = ev && ev.data ? ev.data : null;
          if (!d || typeof d !== 'object') return;
          if (d.type === 'review_submitted' || d.type === 'review_cancel') {
            const modal = document.getElementById('review-iframe-modal');
            const iframe = document.getElementById('review-iframe');
            if (modal && iframe) { modal.style.display='none'; iframe.src='about:blank'; }
            if (d.type === 'review_submitted') {
              // Optionally append a local thank-you system message to the chat
              try {
                const container = document.getElementById('chat-scroll');
                // We already render system messages server-side; keep UI simple here.
              } catch(_){}
              // Reload the conversation to reflect new system message state
              try { window.location.reload(); } catch(_) {}
            } else if (d.type === 'review_cancel') {
              // On cancel, go back to where the user came from if possible
              try {
                const back = (typeof window.__reviewReturnUrl === 'string' && sameOrigin(window.__reviewReturnUrl)) ? window.__reviewReturnUrl : (document.referrer && sameOrigin(document.referrer) ? document.referrer : '');
                if (back) { window.location.href = back; }
                else if (history.length > 1) { history.back(); }
                else { window.location.href = 'feed.php'; }
              } catch(_) {}
            }
          }
        });


      // Poll every 6s to flip Delivered -> Seen for my messages
      setInterval(refreshSeen, 6000);

      // Faster initial load and lazy-load older messages
      const chatScroll = document.getElementById('chat-scroll');
      const loadWrap = document.getElementById('load-older-wrap');
      const loadBtn = document.getElementById('btnLoadOlder');
      let oldestId = (function(){
        const firstMsg = chatScroll ? chatScroll.querySelector('[data-mid]') : null;
        return firstMsg ? parseInt(firstMsg.getAttribute('data-mid'), 10) : 0;
      })();
      let loadingOlder = false;

      async function loadOlder(){
        if (loadingOlder || !oldestId) return;
        loadingOlder = true;
        const prevScrollHeight = chatScroll.scrollHeight;
        try {
          const url = 'conversation_messages.php?id='+encodeURIComponent(cid)+'&before_id='+encodeURIComponent(oldestId)+'&limit=50';
          const r = await fetch(url, { cache: 'no-store' });
          const j = await r.json();
          if (!j || !j.ok || !Array.isArray(j.messages)) return;
          if (j.messages.length === 0) {
            if (loadWrap) loadWrap.style.display = 'none';
            return;
          }
          // Render in ascending order at the top
          const frag = document.createDocumentFragment();
          j.messages.forEach(m => {
            const mine = parseInt(m.sender_id,10) === myId;
            const row = document.createElement('div');
            row.className = mine ? 'row-me' : 'row-oth';
            row.id = 'msg-'+m.message_id;
            row.setAttribute('data-mid', String(m.message_id));
            const bubble = document.createElement('div');
            bubble.className = mine ? 'bubble bubble-me' : 'bubble';
            if (m.body) {
              const body = document.createElement('div');
              body.style.whiteSpace = 'pre-wrap';
              body.style.wordBreak = 'break-word';
              body.textContent = m.body;
              bubble.appendChild(body);
            }
            // attachments (images only)
            try {
              const atts = m.attachments ? JSON.parse(m.attachments) : null;
              if (Array.isArray(atts) && atts.length) {
                const grid = document.createElement('div');
                grid.className = 'att-grid';
                atts.forEach(a => {
                  if (a && a.type === 'image' && a.url) {
                    const aEl = document.createElement('a');
                    aEl.href = a.url; aEl.target = '_blank'; aEl.rel = 'noopener';
                    const img = document.createElement('img');
                    img.src = a.url; img.alt = a.name || 'image';
                    aEl.appendChild(img);
                    grid.appendChild(aEl);
                  }
                });
                if (grid.childNodes.length) bubble.appendChild(grid);
              }
            } catch(_){ }
            const meta = document.createElement('div');
            meta.style.marginTop = '0.25rem';
            meta.style.fontSize = '12px';
            meta.style.opacity = '0.8';
            meta.style.textAlign = 'right';
            const time = document.createElement('time');
            time.className = 'msg-time';
            time.setAttribute('data-epoch', String(Date.parse(m.created_at)/1000));
            meta.appendChild(time);
            bubble.appendChild(meta);

            if (mine) {
              const status = document.createElement('div');
              status.className = 'msg-status';
              status.id = 'msg-status-'+m.message_id;
              status.setAttribute('data-mid', String(m.message_id));
              status.setAttribute('data-seen', m.read_at ? '1' : '0');
              const span = document.createElement('span');
              span.textContent = m.read_at ? 'Seen' : 'Delivered';
              span.className = m.read_at ? 'seen' : 'delivered';
              status.appendChild(span);
              bubble.appendChild(status);
            }
            row.appendChild(bubble);
            frag.appendChild(row);
          });
          if (loadWrap && loadWrap.parentNode) {
            loadWrap.parentNode.insertBefore(frag, loadWrap.nextSibling);
          } else if (chatScroll) {
            chatScroll.insertBefore(frag, chatScroll.firstChild);
          }
          // Maintain scroll position after prepending
          const newHeight = chatScroll.scrollHeight;
          chatScroll.scrollTop = newHeight - prevScrollHeight;
          // Update oldestId to the smallest message_id we have
          const first = chatScroll.querySelector('[data-mid]');
          oldestId = first ? parseInt(first.getAttribute('data-mid'), 10) : oldestId;
          if (typeof window.renderLocalTimes === 'function') window.renderLocalTimes();
        } catch(e) {
          // ignore
        } finally {
          loadingOlder = false;
        }
      }

      if (loadBtn) {
        loadBtn.addEventListener('click', loadOlder);
      }

      // Notify parent (feed.php iframe) of unread changes after markRead
      try {
        const notifyUnread = async () => {
          const r = await fetch('api/unread_count.php', { cache: 'no-store' });
          const j = await r.json();
          if (j && j.ok && typeof j.unreadCount !== 'undefined' && window.parent) {
            window.parent.postMessage({ type: 'inbox-unread-updated', count: j.unreadCount }, '*');
          }
        };
        notifyUnread();
        setInterval(notifyUnread, 7000);
      } catch (_) {}
    })();
  </script>
  <?php endif; ?>
  <script <?= function_exists('csp_script_nonce_attr') ? csp_script_nonce_attr() : '' ?> >
    // Autosize textarea and submit on Enter (without Shift) to retain prior behavior
    (function(){
      const ta = document.getElementById('messageBody');
      const form = document.getElementById('composerForm');
      if (!ta || !form) return;
      function autoResize(){
        ta.style.height = 'auto';
        const h = Math.min(ta.scrollHeight, 200);
        ta.style.height = h + 'px';
      }
      ta.addEventListener('input', autoResize);
      window.addEventListener('load', autoResize);
      // Enter-to-send
      ta.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          // Avoid sending empty message
          if (ta.value.trim().length === 0) return;
          form.requestSubmit ? form.requestSubmit() : form.submit();
        }
      });
    })();
  </script>
</body>
</html>