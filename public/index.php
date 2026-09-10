<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ChatService;
use App\Database;
use App\Http;
use App\EncryptedChatService;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; frame-src blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

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
            Http::json(['room' => $service->roomView($room), 'member' => $service->memberView($room,$member), 'messages' => $service->messages($room, $member)]);
        }
        $member = $service->member($room, $session);
        if (!$member) Http::json(['error' => 'Join this chat before viewing messages.', 'join_required' => true], 403);
        $member = $service->touch($room,$member);
        if (isset($matches[3])) {
            if ($method !== 'GET' || $action !== 'messages') Http::json(['error'=>'Not found.'],404);
            $bytes=$service->content($room,$member,(int)$matches[3]);
            header('Content-Type: application/octet-stream');
            header('Cache-Control: no-store');
            echo $bytes;
            exit;
        }
        if ($action === 'messages' && $method === 'GET') {
            Http::json([
                'member' => $service->memberView($room,$member),
                'room' => $service->roomView($room),
                'messages' => $service->messages($room, $member, (int)($_GET['after'] ?? 0)),
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
$defaultDuration = array_key_exists('1h', $durationChoices) ? '1h' : array_key_last($durationChoices);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111827">
  <title>paste-chat</title>
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/messages.css">
  <link rel="stylesheet" href="/assets/unavailable.css">
</head>
<body>
  <main id="app" data-room-token="<?= htmlspecialchars(basename($path), ENT_QUOTES) ?>">
    <section id="landing" class="panel">
      <div class="brand"><span class="mark">&gt;_</span><h1>paste-chat</h1></div>
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
      <div class="brand"><span class="mark">&gt;_</span><h1>paste-chat</h1></div>
      <h2>Chat unavailable</h2>
      <p class="intro">This chat no longer exists or has expired.</p>
      <a class="primary button-link" href="/">Create a new chat</a>
    </section>

    <section id="chat" hidden>
      <header class="chat-header">
        <a href="/" class="brand"><span class="mark">&gt;_</span> paste-chat</a>
        <div id="room-meta"></div><div id="user-meta"></div>
        <button id="chat-menu-toggle" type="button" aria-label="Chat options" aria-controls="chat-actions" aria-expanded="false"><span aria-hidden="true">☰</span></button>
        <div id="chat-actions">
          <div id="mode-controls"><button id="readonly-toggle" class="quiet" type="button" disabled></button><div id="master-controls" hidden><button id="users-button" class="quiet" type="button">Active users</button></div></div>
          <button id="copy-link" class="quiet" type="button">Copy link</button>
        </div>
      </header>
      <div id="messages" aria-live="polite"></div>
      <form id="message-form" class="composer">
        <textarea id="message-body" maxlength="12000" placeholder="Write Markdown or paste code…&#10;Paste or drop an image, or use ```language for code blocks." required></textarea>
        <div><span id="char-count">0 / 12000</span><label>Attach file (8 MB)<input id="file-input" type="file"></label><button class="primary" type="submit">Send</button></div>
      </form>
    </section>
  </main>

  <dialog id="join-dialog"><form id="join-form" method="dialog"><h2>Join this chat</h2><p>Choose a name for this chat. You can keep the suggested one.</p><label>Your name<input id="join-name" maxlength="40" required></label><button class="primary" type="submit">Join chat</button><p id="join-error" class="error"></p></form></dialog>
  <dialog id="users-dialog"><form method="dialog"><h2>Active users</h2><div id="active-users"></div><p id="moderation-error" class="error"></p><button class="quiet" value="cancel">Close</button></form></dialog>
  <dialog id="file-preview"><form method="dialog" class="preview-dialog"><header><strong id="preview-title">File preview</strong><button class="quiet" value="cancel">Close</button></header><div id="preview-content"></div></form></dialog>
  <template id="message-template"><article class="message"><header><strong class="author"></strong><time></time><button class="copy-message quiet">Copy message</button></header><div class="message-body"></div></article></template>
  <script src="/assets/crypto.js" defer></script>
  <script src="/assets/app.js" defer></script>
</body>
</html>
