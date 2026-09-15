(() => {
  const app = document.querySelector('#app');
  const token = app.dataset.roomToken;
  const landing = document.querySelector('#landing');
  const unavailable = document.querySelector('#unavailable');
  const chat = document.querySelector('#chat');
  const messages = document.querySelector('#messages');
  const joinDialog = document.querySelector('#join-dialog');
  const setChatViewport = active => document.documentElement.classList.toggle('chat-active', active);
  let lastId = 0;
  let joining = false;
  let roomKey;
  let currentRoom;
  let currentMember;
  const downloads = [];
  let activeDownloads = 0;
  let oldestId = 0;
  let moreHistory = false;
  let loadingHistory = false;
  let pollTimer;
  let countdownTimer;
  let activeSessionIds;
  const messageField = document.querySelector('#message-body');
  const composer = document.querySelector('#message-form');
  const markdownEditor = new EasyMDE({
    element: messageField,
    autoDownloadFontAwesome: false,
    autofocus: false,
    forceSync: true,
    hideIcons: ['fullscreen', 'side-by-side', 'guide'],
    indentWithTabs: false,
    lineWrapping: true,
    minHeight: '76px',
    spellChecker: false,
    status: false,
    toolbar: ['bold', 'italic', 'heading', '|', 'code', 'link'],
  });
  // Native mobile selection can only reach lines CodeMirror has rendered. Drafts
  // are capped at 12,000 characters, so keeping the complete draft in the DOM
  // lets Android's Select all include every line without a material cost.
  markdownEditor.codemirror.setOption('viewportMargin', Infinity);
  const usesCoarsePointer = () => window.matchMedia('(hover: none) and (pointer: coarse)').matches;
  const editorValue = () => markdownEditor.value();
  const setEditorValue = value => markdownEditor.value(value);
  const setEditorPlaceholder = value => { messageField.placeholder = value; markdownEditor.codemirror.setOption('placeholder', value); };
  const updateCharacterCount = () => { document.querySelector('#char-count').textContent = `${editorValue().length} / 12000`; };
  let editorHeight;
  let attachmentControlSized = false;
  const resizeEditor = () => {
    requestAnimationFrame(() => {
      const editor = markdownEditor.codemirror;
      const lineHeight = editor.defaultTextHeight();
      const minimumHeight = Math.max(84, lineHeight * 3 + 8);
      const contentHeight = editor.heightAtLine(editor.lineCount(), 'local');
      const maximumHeight = minimumHeight + lineHeight * 9;
      const height = Math.max(minimumHeight, Math.min(maximumHeight, contentHeight));
      if (height !== editorHeight) {
        editor.setSize(null, height);
        editorHeight = height;
      }
      if (!attachmentControlSized) {
        const initialHeight = Math.round(editor.getWrapperElement().parentElement.getBoundingClientRect().height);
        if (initialHeight > 0) {
          composer.style.setProperty('--attachment-control-size', `${initialHeight}px`);
          attachmentControlSized = true;
        }
      }
    });
  };
  window.addEventListener('resize', resizeEditor);
  const nearBottom = () => messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
  const preserveScroll = change => {
    const bottom = nearBottom();
    const anchor = Array.from(messages.children).find(node => node.getBoundingClientRect().bottom > messages.getBoundingClientRect().top);
    const top = anchor?.getBoundingClientRect().top;
    const anchorId = anchor?.dataset.id;
    change();
    if (bottom) scrollToLatest();
    else if (anchorId) {
      const current = messages.querySelector(`[data-id="${anchorId}"]`);
      if (current) messages.scrollTop += current.getBoundingClientRect().top - top;
    }
  };
  const objectUrls = new Set();
  let previewUrl;
  let pendingAttachment;
  const clearPreviewUrl = () => { if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = undefined; } };
  window.addEventListener('pagehide', () => { clearPreviewUrl(); objectUrls.forEach(url => URL.revokeObjectURL(url)); objectUrls.clear(); });

  const request = async (url, options = {}) => {
    const response = await fetch(url, {headers: {'Content-Type': 'application/json'}, ...options});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { const error = new Error(data.error || 'Request failed.'); error.data = data; throw error; }
    return data;
  };
  const randomName = () => {
    const a = ['Amber', 'Brisk', 'Copper', 'Daring', 'Ember', 'Frost', 'Golden', 'Harbor', 'Indigo', 'Jolly', 'Kindred', 'Lunar'];
    const b = ['Badger', 'Comet', 'Falcon', 'Fox', 'Heron', 'Lynx', 'Maple', 'Otter', 'Panda', 'Raven', 'Sparrow', 'Wolf'];
    return `${a[Math.floor(Math.random() * a.length)]} ${b[Math.floor(Math.random() * b.length)]}`;
  };
  const formatCountdown = expiresAt => {
    const remaining = expiresAt - Math.floor(Date.now() / 1000);
    if (remaining <= 0) return 'Expired';
    let minutes = Math.ceil(remaining / 60);
    const units = [['mo', 30 * 24 * 60], ['w', 7 * 24 * 60], ['d', 24 * 60], ['h', 60], ['m', 1]];
    const parts = [];
    for (const [label, size] of units) {
      if (minutes < size) continue;
      const count = Math.floor(minutes / size);
      minutes %= size;
      parts.push(`${count}${label}`);
      if (parts.length === 2) break;
    }
    return parts.join(' ');
  };
  const escape = value => value.replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const formatTimestamp = timestamp => {
    const date = new Date(timestamp * 1000);
    const now = new Date();
    const time = date.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
    if (date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate()) return time;
    return `${date.toLocaleDateString([], {month: 'short', day: 'numeric', year: 'numeric'})} · ${time}`;
  };
  const highlight = (code, language) => {
    let html = escape(code);
    const detected = language.trim() || (/^\s*<\?php\b/.test(code) ? 'php' : /^\s*(?:SELECT|INSERT|UPDATE|DELETE|CREATE)\b/i.test(code) ? 'sql' : /^\s*(?:const|let|var|function|import|export)\b/.test(code) ? 'javascript' : '');
    if (/^(js|javascript|ts|typescript|php|python|py|java|c|cpp|css|json|bash|sh|sql|html|xml)$/i.test(detected)) {
      html = html.replace(/(&quot;(?:\\.|[^&])*?&quot;|'(?:\\.|[^'])*?')/g, '<span class="str">$1</span>');
      html = html.replace(/\b(\d+(?:\.\d+)?)\b/g, '<span class="num">$1</span>');
      html = html.replace(/\b(function|return|const|let|var|class|new|if|else|for|while|public|private|static|async|await|echo|SELECT|FROM|WHERE|INSERT|CREATE|def|import|from|true|false|null|NULL)\b/gi, '<span class="kw">$1</span>');
    }
    return html;
  };
  const inline = text => {
    const links = [];
    text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (match, label, href) => {
      try {
        const url = new URL(href);
        if (!['http:', 'https:'].includes(url.protocol)) return match;
        links.push(`<a href="${escape(url.href)}" target="_blank" rel="noopener noreferrer">${escape(label)}</a>`);
        return `\u0000LINK${links.length - 1}\u0000`;
      } catch (_) { return match; }
    });
    let html = escape(text)
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\*([^*]+)\*/g, '<em>$1</em>');
    return html.replace(/\u0000LINK(\d+)\u0000/g, (_, index) => links[Number(index)]);
  };
  const renderMarkdown = source => {
    const blocks = source.split(/```([^\n]*)\n([\s\S]*?)```/g);
    return blocks.map((block, index) => {
      if (index % 3 === 1) return '';
      if (index % 3 === 2) return `<pre><button class="code-copy quiet" data-code="${encodeURIComponent(block)}">Copy</button><code>${highlight(block, blocks[index - 1].trim())}</code></pre>`;
      return block.split(/\n{2,}/).filter(Boolean).map(part => {
        if (/^#{1,3}\s/.test(part)) { const m = part.match(/^(#{1,3})\s+([\s\S]*)$/); return `<h${m[1].length}>${inline(m[2])}</h${m[1].length}>`; }
        return `<p>${inline(part).replace(/\n/g, '<br>')}</p>`;
      }).join('');
    }).join('');
  };
  const copy = async (text, button) => {
    await navigator.clipboard.writeText(text);
    const previous = button.textContent; button.textContent = 'Copied'; setTimeout(() => { button.textContent = previous; }, 1200);
  };
  const scrollToLatest = () => { messages.scrollTop = messages.scrollHeight; };
  let attachmentThumbnail;
  const clearAttachmentThumbnail = () => { if (attachmentThumbnail) URL.revokeObjectURL(attachmentThumbnail); attachmentThumbnail = undefined; };
  window.addEventListener('pagehide', clearAttachmentThumbnail);
  const pendingAttachmentView = document.querySelector('#attachment-tile');
  const updatePendingAttachment = () => {
    clearAttachmentThumbnail();
    pendingAttachmentView.hidden = !pendingAttachment;
    document.querySelector('#file-input').hidden = Boolean(pendingAttachment);
    document.querySelector('#file-control label').hidden = Boolean(pendingAttachment);
    const name = document.querySelector('#attachment-name');
    const icon = document.querySelector('#attachment-icon');
    icon.replaceChildren();
    name.textContent = pendingAttachment ? `${pendingAttachment.name || 'attachment'} (${Math.ceil(pendingAttachment.size / 1024)} KB)` : '';
    name.title = name.textContent;
    pendingAttachmentView.title = name.textContent;
    if (pendingAttachment) {
      icon.textContent = 'FILE';
      if (['image/png','image/jpeg','image/gif','image/webp'].includes(pendingAttachment.type)) {
        const image = document.createElement('img');
        image.alt = ''; attachmentThumbnail = URL.createObjectURL(pendingAttachment); image.src = attachmentThumbnail;
        image.onerror = () => { if (icon.firstChild === image) { icon.textContent = 'FILE'; clearAttachmentThumbnail(); } };
        icon.replaceChildren(image);
      }
    }
  };
  const stageAttachment = file => {
    if (!file) throw new Error('Select a file.');
    if (file.size > 8 * 1024 * 1024) throw new Error('Attachments must be no larger than 8 MB.');
    pendingAttachment = file;
    updatePendingAttachment();
  };
  updatePendingAttachment();
  const previewFile = (blob, name, mime) => {
    const dialog = document.querySelector('#file-preview');
    const content = document.querySelector('#preview-content');
    clearPreviewUrl();
    document.querySelector('#preview-title').textContent = name || 'File preview';
    content.textContent = 'Loading preview…';
    const reader = new FileReader();
    reader.onerror = () => { content.textContent = 'This file could not be previewed.'; };
    reader.onload = () => {
      if (mime.startsWith('image/')) {
        const image = document.createElement('img');
        image.className = 'file-preview-image'; image.src = reader.result; image.alt = name || 'Shared image';
        content.replaceChildren(image);
      } else if (mime === 'application/pdf') {
        previewUrl = URL.createObjectURL(new Blob([reader.result], {type:'application/pdf'}));
        const frame = document.createElement('iframe');
        frame.className = 'file-preview-pdf'; frame.src = previewUrl;
        frame.title = name || 'PDF preview';
        content.replaceChildren(frame);
      } else if (mime.startsWith('text/')) {
        const text = document.createElement('pre');
        text.className = 'file-preview-text'; text.textContent = reader.result;
        content.replaceChildren(text);
      } else {
        content.textContent = 'Preview is available for images and text files. Use the message download link for this file.';
      }
    };
    if (mime.startsWith('image/')) reader.readAsDataURL(blob);
    else if (mime === 'application/pdf') reader.readAsArrayBuffer(blob);
    else if (mime.startsWith('text/')) reader.readAsText(blob);
    else reader.readAsArrayBuffer(blob);
    dialog.showModal();
  };
  pendingAttachmentView.addEventListener('click', event => {
    if (event.target.closest('#clear-attachment') || !pendingAttachment) return;
    previewFile(pendingAttachment, pendingAttachment.name, pendingAttachment.type || 'application/octet-stream');
  });
  const drainDownloads = () => {
    while (activeDownloads < 4 && downloads.length) {
      const job = downloads.shift();
      activeDownloads++;
      renderMessage(job.message, job.placeholder).catch(console.warn).finally(() => {
        activeDownloads--; job.resolve(); drainDownloads();
      });
    }
  };
  const display = (message, localContent, history = false) => {
    if (messages.querySelector(`[data-id="${message.id}"]`)) return Promise.resolve();
    const placeholder = document.createElement('article');
    placeholder.className = 'message'; placeholder.dataset.id = message.id;
    placeholder.textContent = 'Loading message…';
    const next = Array.from(messages.children).find(node => Number(node.dataset.id) > Number(message.id));
    preserveScroll(() => messages.insertBefore(placeholder, next || null));
    if (localContent) return renderMessage(message, placeholder, localContent);
    return new Promise(resolve => {
      const job = {message, placeholder, resolve};
      if (history) downloads.push(job); else downloads.unshift(job);
      drainDownloads();
    });
  };
  const renderMessage = async (message, placeholder, localContent) => {
    let contentError;
    let downloadFailed = false;
    try {
      const content = localContent || await ChatCrypto.receive(roomKey, token, message.id);
      if (content.kind === 'text') {
        message.body = new TextDecoder().decode(content.data);
      } else {
        let fileData = content.data;
        if (content.kind === 'attachment') {
          message.body = new TextDecoder().decode(content.data.slice(0, content.text_bytes));
          fileData = content.data.slice(content.text_bytes);
        }
        const safeImage = ['image/png','image/jpeg','image/gif','image/webp'].includes(content.mime);
        const blob = new Blob([fileData], {type:content.mime || 'application/octet-stream'});
        const url = URL.createObjectURL(blob);
        objectUrls.add(url);
        message.file_url = url;
        message.filename = content.name || 'download';
        message.preview_blob = blob; message.mime = content.mime;
        if (safeImage) message.image_url = url;
      }
    } catch (error) {
      downloadFailed = error.name === 'MessageDownloadError';
      contentError = downloadFailed ? error.message : 'Unable to decrypt this message. Its encrypted data may be incomplete, or the chat link may contain the wrong key.';
    }
    const element = document.querySelector('#message-template').content.firstElementChild.cloneNode(true);
    element.dataset.id = message.id;
    element.classList.toggle('mine', message.is_mine === true || message.is_mine === 1);
    element.classList.toggle('has-image', Boolean(message.image_url));
    element.querySelector('.author').textContent = message.display_name;
    element.querySelector('time').textContent = formatTimestamp(message.created_at);
    const messageBody = element.querySelector('.message-body');
    if (contentError) {
      messageBody.textContent = contentError;
      if (downloadFailed) {
        const retry = document.createElement('button');
        retry.type = 'button'; retry.className = 'quiet'; retry.textContent = 'Retry download';
        retry.addEventListener('click', () => {
          retry.disabled = true;
          downloads.unshift({message, placeholder:element, resolve:() => {}});
          drainDownloads();
        });
        messageBody.append(document.createTextNode(' '), retry);
      }
    } else if (message.body) {
      messageBody.innerHTML = renderMarkdown(message.body);
    }
    if (!contentError && message.image_url) {
      const image = document.createElement('img');
      image.className = 'shared-image';
      image.src = message.image_url;
      image.alt = 'Shared image';
      image.loading = 'lazy';
      image.tabIndex = 0;
      image.title = 'Click to preview';
      image.addEventListener('click', () => previewFile(message.preview_blob, message.filename, message.mime));
      image.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); previewFile(message.preview_blob, message.filename, message.mime); } });
      messageBody.append(image);
    }
    if (message.file_url) {
      const download = document.createElement('a');
      download.href = message.file_url; download.download = message.filename;
      download.textContent = `Download ${message.filename}`;
      messageBody.append(download);
      if (!message.image_url && (message.mime === 'application/pdf' || message.mime?.startsWith('text/'))) {
        const preview = document.createElement('button');
        preview.type = 'button'; preview.className = 'quiet file-preview-button'; preview.textContent = 'Preview';
        preview.addEventListener('click', () => previewFile(message.preview_blob, message.filename, message.mime));
        messageBody.append(preview);
      }
    }
    const copyButton = element.querySelector('.copy-message');
    if (message.body) copyButton.addEventListener('click', () => copy(message.body, copyButton));
    else copyButton.hidden = true;
    const shareButton = element.querySelector('.share-message');
    shareButton.addEventListener('click', async () => {
      shareButton.disabled = true;
      try {
        const data = await request(`/api/chats/${token}/messages/${message.id}`, {method:'POST'});
        const url = new URL(`/share/${data.share.token}`, location.origin);
        url.hash = location.hash;
        openShareDialog(url.href, 'Throw this message', 'Anyone with this complete link can view this message while the chat remains active.');
      } catch (error) { alert(error.message); }
      finally { shareButton.disabled = false; }
    });
    element.querySelectorAll('.code-copy').forEach(button => button.addEventListener('click', () => copy(decodeURIComponent(button.dataset.code), button)));
    preserveScroll(() => placeholder.replaceWith(element));
  };
  const updateMeta = (room, member = currentMember) => {
    currentRoom = room;
    const date = new Date(room.expires_at * 1000);
    const countdown = formatCountdown(room.expires_at);
    document.querySelectorAll('.room-countdown').forEach(countdownView => {
      countdownView.textContent = `${countdown} left`;
      countdownView.title = `Expires ${date.toLocaleString()}`;
      countdownView.setAttribute('aria-label', `Room expires in ${countdown}`);
    });
    if (!countdownTimer) countdownTimer = setInterval(() => updateMeta(currentRoom), 15000);
    const modeButton = document.querySelector('#readonly-toggle');
    const modeInfo = document.querySelector('#mode-info');
    const history = document.querySelector('#history-meta');
    const historyInfo = document.querySelector('#history-info');
    const master = Boolean(member?.is_master);
    modeButton.querySelector('.mode-label').textContent = room.read_only ? 'Read-Only Mode' : 'Chat Mode';
    modeButton.classList.toggle('chat-mode', !room.read_only);
    modeButton.classList.toggle('read-only-mode', Boolean(room.read_only));
    modeButton.disabled = !master;
    const modeDescription = master ? 'Click the room mode button to switch between Chat Mode and Read-Only Mode.' : 'Chat Mode lets members post. Read-Only Mode prevents non-Master members from posting.';
    const historyDescription = room.history_mode === 'all' ? 'Full history: members can see messages sent before they joined.' : 'From joining: members can see messages sent after they joined.';
    modeInfo.dataset.tooltip = modeDescription;
    modeInfo.setAttribute('aria-label', modeDescription);
    history.querySelector('.history-label').textContent = room.history_mode === 'all' ? 'History: Full' : 'History: From joining';
    historyInfo.dataset.tooltip = historyDescription;
    historyInfo.setAttribute('aria-label', historyDescription);
    const field = messageField;
    const send = document.querySelector('#message-form button[type="submit"]');
    const file = document.querySelector('#file-input');
    const blocked = room.read_only && !master;
    field.disabled = blocked; markdownEditor.codemirror.setOption('readOnly', blocked); send.disabled = blocked; file.disabled = blocked;
    setEditorPlaceholder(blocked ? 'This chat is read-only. Only the Master can post.' : 'Write Markdown or paste code…\nPaste or drop an image, or use ```language for code blocks.');
  };
  const updateUser = member => {
    currentMember = member;
    const badge = document.querySelector('#user-meta');
    badge.textContent = member.display_name;
    badge.title = `${member.display_name}${member.is_master ? ' · Master' : ''}`;
    badge.setAttribute('aria-label', `Your username: ${badge.title}`);
  };
  let activeReturnFlight;
  let returningBoomerang;
  const returnBoomerang = () => {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || activeReturnFlight) return;
    const menuToggle = document.querySelector('#chat-menu-toggle');
    const target = getComputedStyle(menuToggle).display !== 'none' ? menuToggle : document.querySelector('#users-button');
    const targetRect = target.getBoundingClientRect();
    if (!targetRect.width || !targetRect.height) return;

    const width = 27;
    const height = 25;
    const flight = document.createElement('img');
    flight.className = 'returning-boomerang';
    flight.src = '/assets/roomerang.svg?v=1';
    flight.alt = '';
    flight.setAttribute('aria-hidden', 'true');
    document.body.append(flight);
    if (typeof flight.animate !== 'function') { flight.remove(); return; }
    returningBoomerang = flight;

    const start = {x:innerWidth - width * 1.7, y:innerHeight - height * 1.7};
    const end = {x:targetRect.left + targetRect.width / 2, y:targetRect.top + targetRect.height / 2};
    const rise = Math.max(1, start.y - end.y);
    const sweep = Math.min(innerWidth * .35, Math.max(72, Math.abs(start.x - end.x) * .35));
    const controls = [
      {x:start.x - sweep, y:start.y - rise * .22},
      {x:end.x + sweep * .55, y:end.y + rise * .22},
    ];
    const curvePoint = t => {
      const inverse = 1 - t;
      return {
        x:inverse ** 3 * start.x + 3 * inverse ** 2 * t * controls[0].x + 3 * inverse * t ** 2 * controls[1].x + t ** 3 * end.x,
        y:inverse ** 3 * start.y + 3 * inverse ** 2 * t * controls[0].y + 3 * inverse * t ** 2 * controls[1].y + t ** 3 * end.y,
      };
    };
    const points = Array.from({length:49}, (_, index) => curvePoint(index / 48));
    const distances = [0];
    for (let index = 1; index < points.length; index += 1) {
      distances[index] = distances[index - 1] + Math.hypot(points[index].x - points[index - 1].x, points[index].y - points[index - 1].y);
    }
    const distance = distances[distances.length - 1];
    const spinDegrees = usesCoarsePointer() ? 1296 : 2592;
    const keyframes = points.map((point, index) => {
      const progress = distances[index] / distance;
      return {
        offset:progress,
        opacity:Math.min(1, progress / .08),
        transform:`translate3d(${point.x - width / 2}px, ${point.y - height / 2}px, 0) rotate(${-90 - progress * spinDegrees}deg) scale(${1.8 - progress * .8})`,
      };
    });
    const animation = flight.animate(keyframes, {duration:Math.max(700, Math.min(1450, distance / .826)), easing:'linear', fill:'forwards'});
    activeReturnFlight = animation;
    const cleanupFlight = () => {
      flight.remove();
      if (returningBoomerang !== flight) return;
      if (activeReturnFlight === animation) activeReturnFlight = undefined;
      returningBoomerang = undefined;
    };
    animation.addEventListener('finish', cleanupFlight, {once:true});
    animation.addEventListener('cancel', cleanupFlight, {once:true});
  };
  const updateActiveSessions = sessions => {
    if (!Array.isArray(sessions)) return;
    const next = new Set(sessions);
    const memberJoined = activeSessionIds && sessions.some(session => !activeSessionIds.has(session));
    activeSessionIds = next;
    if (memberJoined) returnBoomerang();
  };
  const activate = async data => {
    setChatViewport(true);
    landing.hidden = true; unavailable.hidden = true; chat.hidden = false; updateUser(data.member); updateMeta(data.room, data.member);
    editorHeight = undefined;
    attachmentControlSized = false;
    resizeEditor();
    lastId = data.messages.length ? Number(data.messages.at(-1).id) : 0;
    oldestId = data.messages.length ? Number(data.messages[0].id) : 0;
    moreHistory = data.messages.length === 25;
    updateActiveSessions(data.active_sessions);
    for (const message of [...data.messages].reverse()) display(message);
    scrollToLatest();
    clearTimeout(pollTimer);
    poll();
  };
  messages.addEventListener('scroll', async () => {
    if (messages.scrollTop > 100 || loadingHistory || !moreHistory || !oldestId || joining) return;
    loadingHistory = true;
    try {
      const data = await request(`/api/chats/${token}/messages?before=${oldestId}`);
      moreHistory = data.messages.length === 25;
      if (data.messages.length) oldestId = Number(data.messages[0].id);
      await Promise.all([...data.messages].reverse().map(message => display(message, undefined, true)));
    } catch (error) { console.warn(error.message); }
    finally { loadingHistory = false; }
  });
  const poll = async () => {
    if (!token || joining) return;
    try {
      const data = await request(`/api/chats/${token}/messages?after=${lastId}`);
      updateUser(data.member); updateMeta(data.room, data.member);
      updateActiveSessions(data.active_sessions);
      for (const message of data.messages) display(message);
      if (data.messages.length) lastId = Number(data.messages.at(-1).id);
    }
    catch (error) { if (error.data?.join_required) showJoin(); else if (error.data?.moderation_denied) { showUnavailable(); unavailable.querySelector('.intro').textContent = error.message; } else console.warn(error.message); }
    pollTimer = setTimeout(poll, 2500);
  };
  const showJoin = () => {
    if (joining) return;
    joining = true;
    document.querySelector('#join-name').value = randomName();
    joinDialog.showModal();
  };
  const showUnavailable = () => {
    setChatViewport(false);
    landing.hidden = true;
    chat.hidden = true;
    unavailable.hidden = false;
  };
  const openRoom = async () => {
    try { roomKey = await ChatCrypto.importKey(location.hash.slice(1)); }
    catch (error) { showUnavailable(); unavailable.querySelector('.intro').textContent = error.message; return; }
    try {
      activate(await request(`/api/chats/${token}/messages`));
    } catch (error) {
      if (error.data?.join_required) showJoin();
      else showUnavailable();
    }
  };

  const refreshUsers = async () => {
    const list = document.querySelector('#active-users');
    const error = document.querySelector('#moderation-error');
    error.textContent = '';
    try {
      const data = await request(`/api/chats/${token}/moderation`);
      list.replaceChildren();
      if (!data.members.length) { list.textContent = 'No active users.'; return; }
      data.members.forEach(user => {
        const row = document.createElement('div'); row.className = 'active-user';
        const label = document.createElement('span'); label.textContent = `${user.display_name} · ${user.session_id}${user.is_master ? ' · Master' : ''}`;
        row.append(label);
        if (currentMember?.is_master && !user.is_master) {
          const kick = document.createElement('button'); kick.type='button'; kick.className='quiet'; kick.textContent='Kick'; kick.addEventListener('click', () => moderateUser(user.session_id,'kick'));
          const block = document.createElement('button'); block.type='button'; block.className='quiet danger'; block.textContent='Block'; block.addEventListener('click', () => moderateUser(user.session_id,'block'));
          row.append(kick,block);
        }
        list.append(row);
      });
    } catch (e) { error.textContent = e.message; }
  };
  const moderateUser = async (sessionId, action) => {
    try { await request(`/api/chats/${token}/moderation/members/${sessionId}/${action}`, {method:'POST'}); await refreshUsers(); }
    catch (e) { document.querySelector('#moderation-error').textContent = e.message; }
  };

  document.querySelector('#create-form').addEventListener('submit', async event => {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    try { const key = ChatCrypto.newKey(); const data = await request('/api/chats', {method: 'POST', body: JSON.stringify(Object.fromEntries(form))}); location.assign(`/chat/${data.room.token}#${key}`); }
    catch (error) { alert(error.message); }
  });
  document.querySelector('#join-form').addEventListener('submit', async event => {
    event.preventDefault(); const name = document.querySelector('#join-name').value;
    try { const data = await request(`/api/chats/${token}/join`, {method: 'POST', body: JSON.stringify({name})}); joinDialog.close(); joining = false; activate(data); }
    catch (error) { document.querySelector('#join-error').textContent = error.message; }
  });
  markdownEditor.codemirror.on('change', () => { updateCharacterCount(); resizeEditor(); });
  markdownEditor.codemirror.on('beforeChange', (editor, change) => {
    const removed = editor.indexFromPos(change.to) - editor.indexFromPos(change.from);
    const inserted = change.text.join('\n').length;
    if (editor.getValue().length - removed + inserted > 12000) change.cancel();
  });
  markdownEditor.codemirror.on('keydown', (_, event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !usesCoarsePointer()) {
      event.preventDefault();
      composer.requestSubmit(composer.querySelector('button[type="submit"]'));
    }
  });
  markdownEditor.codemirror.on('paste', (_, event) => {
    const imageItem = Array.from(event.clipboardData?.items || []).find(item => item.type.startsWith('image/'));
    if (!imageItem) return;
    event.preventDefault();
    try { stageAttachment(imageItem.getAsFile()); } catch (error) { alert(error.message); }
  });
  document.querySelector('#file-input').addEventListener('change', event => {
    if (!event.target.files.length) return;
    try { stageAttachment(event.target.files[0]); } catch(error) { alert(error.message); }
    event.target.value = '';
  });
  document.querySelector('#clear-attachment').addEventListener('click', () => { pendingAttachment = undefined; updatePendingAttachment(); });
  composer.addEventListener('dragover', event => { event.preventDefault(); composer.classList.add('drop-target'); });
  composer.addEventListener('dragleave', () => composer.classList.remove('drop-target'));
  composer.addEventListener('drop', event => {
    event.preventDefault(); composer.classList.remove('drop-target');
    const image = Array.from(event.dataTransfer?.files || [])[0];
    if (image) { try { stageAttachment(image); } catch (error) { alert(error.message); } }
    else alert('Drop a file to share it.');
  });
  composer.addEventListener('submit', async event => {
    event.preventDefault(); const button = event.currentTarget.querySelector('button[type="submit"]'); const body = editorValue();
    if (button.disabled || (!body.trim() && !pendingAttachment)) return;
    button.disabled = true;
    try {
      let data, metadata;
      const storesAttachment = Boolean(pendingAttachment);
      if (storesAttachment) {
        const text = new TextEncoder().encode(body);
        const file = new Uint8Array(await pendingAttachment.arrayBuffer());
        data = new Uint8Array(text.byteLength + file.byteLength);
        data.set(text); data.set(file, text.byteLength);
        metadata = {v:2, kind:'attachment', mime:pendingAttachment.type || 'application/octet-stream', name:pendingAttachment.name || 'attachment', text_bytes:text.byteLength};
      } else {
        data = new TextEncoder().encode(body);
        metadata = {kind:'text',mime:'text/markdown',name:''};
      }
      const message = await ChatCrypto.send(roomKey,token,data.buffer,metadata,storesAttachment ? 'attachment' : 'database');
      await display(message, {...metadata, data:data.buffer}); setEditorValue(''); pendingAttachment = undefined; updatePendingAttachment(); updateCharacterCount(); scrollToLatest();
    }
    catch (error) { alert(error.message); } finally { button.disabled = false; }
  });
  const shareDialog = document.querySelector('#share-dialog');
  const shareUrl = document.querySelector('#share-url');
  const shareDialogBoomerang = document.querySelector('#share-dialog-boomerang');
  let shareThrowUsed = false;
  let activeShareFlight;
  let flyingShareBoomerang;
  const resetShareThrow = () => {
    activeShareFlight?.cancel();
    flyingShareBoomerang?.remove();
    activeShareFlight = undefined;
    flyingShareBoomerang = undefined;
    shareThrowUsed = false;
    shareDialog.classList.remove('is-throwing');
    shareDialogBoomerang.classList.remove('is-thrown');
  };
  const throwShareBoomerang = () => {
    if (shareThrowUsed) return;
    shareThrowUsed = true;
    const rect = shareDialogBoomerang.getBoundingClientRect();
    shareDialogBoomerang.classList.add('is-thrown');
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const flight = shareDialogBoomerang.cloneNode();
    flight.removeAttribute('id');
    flight.className = 'share-dialog-boomerang-flight';
    flight.setAttribute('aria-hidden', 'true');
    flight.style.width = `${rect.width}px`;
    flight.style.height = `${rect.height}px`;
    shareDialog.classList.add('is-throwing');
    shareDialog.append(flight);
    if (typeof flight.animate !== 'function') { shareDialog.classList.remove('is-throwing'); flight.remove(); return; }
    flyingShareBoomerang = flight;

    const start = {x:rect.left + rect.width / 2, y:rect.top + rect.height / 2};
    const endX = innerWidth + rect.width * 2.2;
    const travel = endX - start.x;
    const originalArcHeight = Math.min(110, Math.max(35, travel * .1), Math.max(0, start.y - rect.height * 1.4));
    const arcHeight = originalArcHeight * .8;
    const end = {x:endX, y:Math.min(innerHeight - rect.height * 2, start.y + 12 + originalArcHeight * .2)};
    const controls = [
      {x:start.x + travel * .32, y:start.y - arcHeight},
      {x:start.x + travel * .73, y:start.y - arcHeight},
    ];
    const curvePoint = t => {
      const inverse = 1 - t;
      return {
        x:inverse ** 3 * start.x + 3 * inverse ** 2 * t * controls[0].x + 3 * inverse * t ** 2 * controls[1].x + t ** 3 * end.x,
        y:inverse ** 3 * start.y + 3 * inverse ** 2 * t * controls[0].y + 3 * inverse * t ** 2 * controls[1].y + t ** 3 * end.y,
      };
    };
    const points = Array.from({length:49}, (_, index) => curvePoint(index / 48));
    const distances = [0];
    for (let index = 1; index < points.length; index += 1) {
      distances[index] = distances[index - 1] + Math.hypot(points[index].x - points[index - 1].x, points[index].y - points[index - 1].y);
    }
    const distance = distances[distances.length - 1];
    const spinDegrees = usesCoarsePointer() ? 1296 : 2592;
    const keyframes = points.map((point, index) => {
      const progress = distances[index] / distance;
      return {
        offset:progress,
        opacity:progress < .88 ? 1 : Math.max(0, (1 - progress) / .12),
        transform:`translate3d(${point.x - rect.width / 2}px, ${point.y - rect.height / 2}px, 0) rotate(${-90 - progress * spinDegrees}deg) scale(${1 + progress * 1.2})`,
      };
    });
    const animation = flight.animate(keyframes, {duration:distance / .826, easing:'linear', fill:'forwards'});
    activeShareFlight = animation;
    const cleanupFlight = () => {
      flight.remove();
      if (flyingShareBoomerang !== flight) return;
      shareDialog.classList.remove('is-throwing');
      if (activeShareFlight === animation) activeShareFlight = undefined;
      flyingShareBoomerang = undefined;
    };
    animation.addEventListener('finish', cleanupFlight, {once:true});
    animation.addEventListener('cancel', cleanupFlight, {once:true});
  };
  const openShareDialog = (url, title, description) => {
    document.querySelector('#share-title').textContent = title;
    document.querySelector('#share-description').textContent = description;
    shareUrl.value = url;
    resetShareThrow();
    shareDialog.showModal();
    requestAnimationFrame(() => shareUrl.select());
  };
  document.querySelector('#copy-link').addEventListener('click', () => {
    openShareDialog(location.href, 'Throw this room', 'Anyone with this complete link can join this room.');
  });
  document.querySelector('#share-copy').addEventListener('click', event => {
    throwShareBoomerang();
    copy(shareUrl.value, event.currentTarget);
  });
  shareDialog.addEventListener('cancel', resetShareThrow);
  shareDialog.addEventListener('close', resetShareThrow);
  shareDialog.querySelector('button[value="cancel"]').addEventListener('click', resetShareThrow);
  const menuToggle = document.querySelector('#chat-menu-toggle');
  const menuActions = document.querySelector('#chat-actions');
  const closeMenu = () => { menuToggle.setAttribute('aria-expanded', 'false'); menuActions.classList.remove('is-open'); };
  menuToggle.addEventListener('click', () => {
    const open = menuToggle.getAttribute('aria-expanded') !== 'true';
    menuToggle.setAttribute('aria-expanded', String(open)); menuActions.classList.toggle('is-open', open);
  });
  document.addEventListener('click', event => { if (!menuActions.contains(event.target) && !menuToggle.contains(event.target)) closeMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') { closeMenu(); menuToggle.focus(); } });
  menuActions.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (button) closeMenu();
  });
  const filePreview = document.querySelector('#file-preview');
  filePreview.addEventListener('click', event => { if (event.target === filePreview) filePreview.close(); });
  filePreview.addEventListener('close', clearPreviewUrl);
  document.querySelector('#readonly-toggle').addEventListener('click', async () => {
    try {
      const data = await request(`/api/chats/${token}/moderation/read-only`, {method:'POST', body:JSON.stringify({enabled:!currentRoom.read_only})});
      updateMeta(data.room, currentMember);
    } catch (e) { alert(e.message); }
  });
  document.querySelector('#users-button').addEventListener('click', async () => { document.querySelector('#users-dialog').showModal(); await refreshUsers(); });
  if (token) {
    if (/^[A-Za-z0-9_-]{43}$/.test(token)) openRoom();
    else showUnavailable();
  }
})();
