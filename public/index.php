<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ChatService;
use App\AdminService;
use App\Database;
use App\Http;
use App\EncryptedChatService;
use App\SystemState;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; frame-src blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

if (str_starts_with($path, '/admin')) {
    if ($path !== '/admin' && $path !== '/admin/login') {
        http_response_code(404);
        exit;
    }
    if (!AdminService::configured()) {
        http_response_code(404);
        exit;
    }
    header('Cache-Control: no-store');
    $admin = new AdminService(Database::connection());
    $error = '';
    try {
        if ($method === 'POST' && $path === '/admin/login') {
            if (!$admin->login((string)($_POST['password'] ?? ''))) $error = 'Invalid password.';
            else { header('Location: /admin', true, 303); exit; }
        }
        $session = $admin->session();
        if ($method === 'POST' && $path === '/admin') {
            if (!$session) { header('Location: /admin', true, 303); exit; }
            $admin->assertCsrf($session, $_POST['csrf'] ?? null);
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'logout') {
                $admin->logout();
                header('Location: /admin', true, 303);
                exit;
            }
            if ($action === 'maintenance') {
                SystemState::setMaintenanceMode(Database::connection(), ($_POST['enabled'] ?? '') === '1');
                header('Location: /admin?notice=maintenance', true, 303);
                exit;
            }
            if ($action === 'prune') {
                $deleted = $admin->pruneExpired();
                header('Location: /admin?notice=pruned-' . $deleted, true, 303);
                exit;
            }
            if ($action === 'backup') {
                if (!SystemState::maintenanceEnabled(Database::connection())) throw new RuntimeException('Enable maintenance mode before creating a backup.');
                $backup = $admin->backupPath();
                header('Content-Type: application/vnd.sqlite3');
                header('Content-Disposition: attachment; filename="roomerang-backup-' . gmdate('Ymd-His') . '.sqlite"');
                header('Content-Length: ' . (string)filesize($backup));
                readfile($backup);
                @unlink($backup);
                exit;
            }
            throw new RuntimeException('Unknown admin action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $session = $admin->session();
    }

    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    if (!$session) {
        http_response_code($error === '' ? 401 : 403);
        ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Roomerang administration</title><link rel="stylesheet" href="/assets/app.css?v=roomerang-2"><link rel="stylesheet" href="/assets/admin.css?v=roomerang-1"></head><body><main class="panel admin-login"><div class="brand"><img class="brand-logo" src="/assets/roomerang.svg?v=1" alt=""><h1>Roomerang</h1></div><h2>Administration</h2><form method="post" action="/admin/login"><label>Password<input type="password" name="password" autocomplete="current-password" required autofocus></label><button class="primary" type="submit">Sign in</button><?php if ($error !== ''): ?><p class="error"><?= $escape($error) ?></p><?php endif ?></form></main></body></html>
        <?php
        exit;
    }
    $stats = $admin->statistics();
    $notice = (string)($_GET['notice'] ?? '');
    if ($notice === 'maintenance') $notice = 'Maintenance mode updated.';
    elseif (preg_match('/^pruned-([0-9]+)$/', $notice, $match)) $notice = $match[1] . ' expired chat(s) removed.';
    else $notice = '';
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Roomerang administration</title><link rel="stylesheet" href="/assets/app.css?v=roomerang-2"><link rel="stylesheet" href="/assets/admin.css?v=roomerang-1"></head><body><main class="panel admin-panel"><header class="admin-header"><div><div class="brand"><img class="brand-logo" src="/assets/roomerang.svg?v=1" alt=""><h1>Roomerang</h1></div><h2>Administration</h2></div><form method="post"><input type="hidden" name="csrf" value="<?= $escape($session['csrf_token']) ?>"><input type="hidden" name="action" value="logout"><button class="quiet" type="submit">Sign out</button></form></header><?php if ($error !== ''): ?><p class="error"><?= $escape($error) ?></p><?php endif ?><?php if ($notice !== ''): ?><p class="admin-notice"><?= $escape($notice) ?></p><?php endif ?><section><h3>Chat statistics</h3><dl class="admin-stats"><div><dt>Total chats</dt><dd><?= $stats['total_chats'] ?></dd></div><div><dt>Active chats</dt><dd><?= $stats['active_chats'] ?></dd></div><div><dt>Expired chats awaiting prune</dt><dd><?= $stats['expired_chats'] ?></dd></div><div><dt>Chats with no messages</dt><dd><?= $stats['empty_chats'] ?></dd></div><div><dt>Most messages in an active chat</dt><dd><?= $stats['maximum_messages'] ?></dd></div><div><dt>Average messages per active chat</dt><dd><?= $stats['average_messages'] ?></dd></div></dl></section><section class="admin-card"><h3>Maintenance mode</h3><p><?= $stats['maintenance_mode'] ? 'Enabled: chat rooms remain readable to existing members, but room creation, new joins, messages, and room moderation are blocked.' : 'Disabled: normal room operations are available.' ?></p><form method="post"><input type="hidden" name="csrf" value="<?= $escape($session['csrf_token']) ?>"><input type="hidden" name="action" value="maintenance"><input type="hidden" name="enabled" value="<?= $stats['maintenance_mode'] ? '0' : '1' ?>"><button class="<?= $stats['maintenance_mode'] ? 'primary' : 'quiet danger' ?>" type="submit"><?= $stats['maintenance_mode'] ? 'End maintenance' : 'Enable maintenance' ?></button></form></section><section class="admin-card"><h3>Database care</h3><p>Integrity check: <strong><?= $escape($stats['integrity']) ?></strong>. Expired rooms are automatically inaccessible and cascaded data is deleted when pruned.</p><div class="admin-actions"><form method="post"><input type="hidden" name="csrf" value="<?= $escape($session['csrf_token']) ?>"><input type="hidden" name="action" value="prune"><button class="quiet" type="submit">Prune expired chats</button></form><form method="post"><input type="hidden" name="csrf" value="<?= $escape($session['csrf_token']) ?>"><input type="hidden" name="action" value="backup"><button class="primary" type="submit"<?= $stats['maintenance_mode'] ? '' : ' disabled' ?>>Download SQLite backup</button></form></div><p class="admin-help">Enable maintenance before a backup. Restore is intentionally an operator task: stop the web container, replace the database and its <code>-wal</code>/<code>-shm</code> sidecars from a verified backup, then restart it.</p></section></main></body></html>
    <?php
    exit;
}

if ($method === 'GET' && preg_match('#^/media/([A-Za-z0-9_-]{43})$#', $path, $imageMatch)) {
    http_response_code(404);
    exit;
}

if (str_starts_with($path, '/api/')) {
    try {
        $service = new EncryptedChatService(Database::connection());
        if ($method === 'POST' && $path === '/api/chats') {
            $data = Http::input();
            $room = $service->create((string)($data['duration'] ?? ''), (string)($data['history_mode'] ?? ''));
            Http::json(['room' => $room]);
        }
        if (preg_match('#^/api/chats/([A-Za-z0-9_-]{43})/moderation(?:/(read-only|members/([a-f0-9]{12})/(kick|block)))?$#', $path, $moderation)) {
            $room = $service->room($moderation[1]);
            $session = Http::sessionId();
            $member = $service->member($room, $session);
            if (!$member) Http::json(['error'=>'Join this chat before managing it.'],403);
            $member = $service->touch($room,$member);
            if ($method === 'GET' && ($moderation[2] ?? '') === '') {
                Http::json(['room'=>$service->roomView($room),'members'=>$service->activeMembers($room,$member)]);
            }
            if ($method === 'POST' && ($moderation[2] ?? '') === 'read-only') {
                $data=Http::input();
                $room=$service->setReadOnly($room,$member,(bool)($data['enabled'] ?? false));
                Http::json(['room'=>$service->roomView($room)]);
            }
            if ($method === 'POST' && isset($moderation[4])) {
                if ($moderation[4] === 'kick') $service->kick($room,$member,$moderation[3]);
                else $service->block($room,$member,$moderation[3]);
                Http::json(['ok'=>true]);
            }
            Http::json(['error'=>'Not found.'],404);
        }
        if (!preg_match('#^/api/chats/([A-Za-z0-9_-]{43})(?:/(join|messages)(?:/([0-9]+))?)?$#', $path, $matches)) Http::json(['error' => 'Not found.'], 404);
        $room = $service->room($matches[1]);
        $action = $matches[2] ?? '';
        $session = Http::sessionId();
        if ($method === 'POST' && $action === 'join') {
            $data = Http::input();
            $member = $service->join($room, $session, (string)($data['name'] ?? ''));
            $room=$service->room($matches[1]);
            Http::json(['room' => $service->roomView($room), 'member' => $service->memberView($room,$member), 'messages' => $service->messages($room, $member, 0, PHP_INT_MAX)]);
        }
        $member = $service->member($room, $session);
        if (!$member) Http::json(['error' => 'Join this chat before viewing messages.', 'join_required' => true], 403);
        if (isset($matches[3])) {
            if ($method !== 'GET' || $action !== 'messages') Http::json(['error'=>'Not found.'],404);
            $bytes=$service->content($room,$member,(int)$matches[3]);
            header('Content-Type: application/octet-stream');
            header('Cache-Control: no-store');
            echo $bytes;
            exit;
        }
        $member = $service->touch($room,$member);
        if ($action === 'messages' && $method === 'GET') {
            Http::json([
                'member' => $service->memberView($room,$member),
                'room' => $service->roomView($room),
                'messages' => $service->messages($room, $member, (int)($_GET['after'] ?? 0), isset($_GET['before']) ? max(0, (int)$_GET['before']) : (isset($_GET['after']) ? null : PHP_INT_MAX)),
            ]);
        }
        if ($action === 'messages' && $method === 'POST') {
            if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/octet-stream') Http::json(['error'=>'Encrypted binary payload required.'],415);
            $bytes=file_get_contents('php://input',false,null,0,EncryptedChatService::MAX_CIPHERTEXT+13);
            Http::json(['message'=>$service->post($room,$member,substr($bytes,0,12),substr($bytes,12))],201);
        }
        Http::json(['error' => 'Not found.'], 404);
    } catch (App\AccessDeniedException $e) {
        Http::json(['error' => $e->getMessage(), 'moderation_denied' => true], 403);
    } catch (InvalidArgumentException $e) {
        Http::json(['error' => $e->getMessage()], 422);
    } catch (\PDOException $e) {
        error_log($e->getMessage());
        Http::json(['error' => 'The server could not process this request.'], 500);
    } catch (RuntimeException $e) {
        Http::json(['error' => $e->getMessage()], 404);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        Http::json(['error' => 'The server could not process this request.'], 500);
    }
}

if ($path !== '/' && !preg_match('#^/chat/[A-Za-z0-9_-]{43}$#', $path)) {
    http_response_code(404);
}

$durationChoices = ChatService::durationChoices();
$defaultDuration = ChatService::defaultDuration();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111827">
  <title>Roomerang</title>
  <link rel="stylesheet" href="/assets/app.css?v=roomerang-3">
  <link rel="stylesheet" href="/assets/easymde.min.css?v=2.21.0">
  <link rel="stylesheet" href="/assets/messages.css?v=roomerang-15">
  <link rel="stylesheet" href="/assets/unavailable.css?v=roomerang-2">
</head>
<body>
  <main id="app" data-room-token="<?= htmlspecialchars(basename($path), ENT_QUOTES) ?>">
    <section id="landing" class="panel">
      <div class="brand"><img class="brand-logo" src="/assets/roomerang.svg?v=1" alt=""><h1>Roomerang</h1></div>
      <p class="intro">A private, temporary place to paste ideas, Markdown, and code.</p>
      <form id="create-form">
        <label>Chat expires after
          <select name="duration">
            <?php foreach ($durationChoices as $value => $label): ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"<?= $value === $defaultDuration ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <fieldset><legend>New joiners can see</legend>
          <label><input type="radio" name="history_mode" value="all" checked> The whole chat history</label>
          <label><input type="radio" name="history_mode" value="since_join"> Messages sent after they join</label>
        </fieldset>
        <button class="primary" type="submit">Create chat</button>
      </form>
    </section>

    <section id="unavailable" class="panel" hidden>
      <div class="brand"><img class="brand-logo" src="/assets/roomerang.svg?v=1" alt=""><h1>Roomerang</h1></div>
      <h2>Chat unavailable</h2>
      <p class="intro">This chat no longer exists or has expired.</p>
      <a class="primary button-link" href="/">Create a new chat</a>
    </section>

    <section id="chat" hidden>
      <header class="chat-header">
        <a href="/" class="brand"><img class="brand-logo" src="/assets/roomerang.svg?v=1" alt=""><span class="brand-name">Roomerang</span></a>
        <div id="room-meta"></div><div id="user-meta"></div>
        <button id="chat-menu-toggle" type="button" aria-label="Chat options" aria-controls="chat-actions" aria-expanded="false"><span aria-hidden="true">☰</span></button>
        <div id="chat-actions">
          <div id="mode-controls"><button id="readonly-toggle" class="quiet" type="button" disabled></button><div id="master-controls" hidden><button id="users-button" class="quiet" type="button">Active users</button></div></div>
          <button id="copy-link" class="quiet" type="button" title="Throw this room elsewhere." aria-label="Throw this room elsewhere."><img class="share-boomerang" src="/assets/roomerang.svg?v=1" alt=""></button>
        </div>
      </header>
      <div id="messages" aria-live="polite"></div>
      <form id="message-form" class="composer">
        <textarea id="message-body" maxlength="12000" placeholder="Write Markdown or paste code…&#10;Drop a file or paste an image, or use ```language for code blocks."></textarea>
        <span id="char-count">0 / 12000</span>
        <div id="composer-actions">
          <section id="file-control"><label for="file-input">Attach file (8 MB)</label><input id="file-input" type="file"><section id="attachment-tile" hidden aria-live="polite"><span id="attachment-icon" aria-hidden="true"></span><span id="attachment-name"></span><button id="clear-attachment" class="quiet" type="button" aria-label="Remove attachment" title="Remove attachment">×</button></section></section>
          <button id="send-message" class="primary" type="submit">Send</button>
        </div>
      </form>
    </section>
  </main>

  <dialog id="join-dialog"><form id="join-form" method="dialog"><h2>Join this chat</h2><p>Choose a name for this chat. You can keep the suggested one.</p><label>Your name<input id="join-name" maxlength="40" required></label><button class="primary" type="submit">Join chat</button><p id="join-error" class="error"></p></form></dialog>
  <dialog id="users-dialog"><form method="dialog"><h2>Active users</h2><div id="active-users"></div><p id="moderation-error" class="error"></p><button class="quiet" value="cancel">Close</button></form></dialog>
  <dialog id="share-dialog"><form method="dialog" class="share-dialog"><header><strong>Share this room</strong><button class="quiet" value="cancel">Close</button></header><p>Anyone with this complete link can join this room.</p><label>Room link<input id="share-url" type="text" readonly spellcheck="false"></label><button id="share-copy" class="primary" type="button">Copy link</button></form></dialog>
  <dialog id="file-preview"><form method="dialog" class="preview-dialog"><header><strong id="preview-title">File preview</strong><button class="quiet" value="cancel">Close</button></header><div id="preview-content"></div></form></dialog>
  <template id="message-template"><article class="message"><header><strong class="author"></strong><time></time><button class="copy-message quiet">Copy message</button></header><div class="message-body"></div></article></template>
  <script src="/assets/crypto.js?v=roomerang-3" defer></script>
  <script src="/assets/easymde.min.js?v=2.21.0" defer></script>
  <script src="/assets/app.js?v=roomerang-20" defer></script>
</body>
</html>
