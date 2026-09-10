(() => {
  const app = document.querySelector('#app');
  const token = app.dataset.roomToken;
  const landing = document.querySelector('#landing');
  const unavailable = document.querySelector('#unavailable');
  const chat = document.querySelector('#chat');
  const messages = document.querySelector('#messages');
  const joinDialog = document.querySelector('#join-dialog');
  let lastId = 0;
  let joining = false;
  let roomKey;
  let currentRoom;
  let currentMember;
  let renderQueue = Promise.resolve();
  const objectUrls = new Set();
  let previewUrl;
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
  const escape = value => value.replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const highlight = (code, language) => {
    let html = escape(code);
    if (/^(js|javascript|ts|typescript|php|python|py|java|c|cpp|css|json|bash|sh|sql|html|xml)$/i.test(language)) {
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
  const display = message => {
    renderQueue = renderQueue.then(() => renderMessage(message));
    return renderQueue;
  };
  const renderMessage = async message => {
    if (document.querySelector(`[data-id="${message.id}"]`)) return;
    let contentError;
    try {
      const content = await ChatCrypto.receive(roomKey, token, message.id);
      if (content.kind === 'text') {
        message.body = new TextDecoder().decode(content.data);
      } else {
        const safeImage = ['image/png','image/jpeg','image/gif','image/webp'].includes(content.mime);
        const blob = new Blob([content.data], {type:content.mime || 'application/octet-stream'});
        const url = URL.createObjectURL(blob);
        objectUrls.add(url);
        message.file_url = url;
        message.filename = content.name || 'download';
        message.preview_blob = blob; message.mime = content.mime;
        if (safeImage) message.image_url = url;
      }
    } catch (_) { contentError = 'Unable to decrypt this message. Check that you have the correct complete chat link.'; }
    const element = document.querySelector('#message-template').content.firstElementChild.cloneNode(true);
    element.dataset.id = message.id;
    element.classList.toggle('mine', message.is_mine === true || message.is_mine === 1);
    element.classList.toggle('has-image', Boolean(message.image_url));
    element.querySelector('.author').textContent = message.display_name;
    element.querySelector('time').textContent = new Date(message.created_at * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    const messageBody = element.querySelector('.message-body');
    if (contentError) {
      messageBody.textContent = contentError;
    } else if (message.image_url) {
      const image = document.createElement('img');
      image.className = 'shared-image';
      image.src = message.image_url;
      image.alt = 'Shared image';
      image.loading = 'lazy';
      image.tabIndex = 0;
      image.title = 'Click to preview';
      image.addEventListener('click', () => previewFile(message.preview_blob, message.filename, message.mime));
      image.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); previewFile(message.preview_blob, message.filename, message.mime); } });
      messageBody.replaceChildren(image);
    } else if (!message.file_url) {
      messageBody.innerHTML = renderMarkdown(message.body);
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
    element.querySelectorAll('.code-copy').forEach(button => button.addEventListener('click', () => copy(decodeURIComponent(button.dataset.code), button)));
    messages.append(element);
    lastId = Math.max(lastId, Number(message.id));
  };
  const uploadImage = async file => {
    if (!file) throw new Error('Select a file.');
    if (file.size > 8 * 1024 * 1024) throw new Error('Images must be no larger than 8 MB.');
    const message = await ChatCrypto.send(roomKey,token,await file.arrayBuffer(),{kind:'file',mime:file.type || 'application/octet-stream',name:file.name || 'pasted-image'});
    await display(message);
    scrollToLatest();
  };
  const updateMeta = (room, member = currentMember) => {
    currentRoom = room;
    const date = new Date(room.expires_at * 1000);
    document.querySelector('#room-meta').textContent = `Expires ${date.toLocaleString()} · ${room.history_mode === 'all' ? 'full history' : 'from joining'}${room.read_only ? ' · read-only' : ''}`;
    const controls = document.querySelector('#master-controls');
    const modeButton = document.querySelector('#readonly-toggle');
    const master = Boolean(member?.is_master);
    controls.hidden = !master;
    modeButton.textContent = room.read_only ? 'Read-Only Mode' : 'Chat Mode';
    modeButton.classList.toggle('chat-mode', !room.read_only);
    modeButton.classList.toggle('read-only-mode', Boolean(room.read_only));
    modeButton.disabled = !master;
    modeButton.title = master ? 'Click to switch room mode' : 'Room mode (Master only can change this)';
    const field = document.querySelector('#message-body');
    const send = document.querySelector('#message-form button[type="submit"]');
    const file = document.querySelector('#file-input');
    const blocked = room.read_only && !master;
    field.disabled = blocked; send.disabled = blocked; file.disabled = blocked;
    field.placeholder = blocked ? 'This chat is read-only. Only the Master can post.' : 'Write Markdown or paste code…\nPaste or drop an image, or use ```language for code blocks.';
  };
  const updateUser = member => {
    currentMember = member;
    const badge = document.querySelector('#user-meta');
    badge.textContent = member.display_name;
    badge.title = `${member.display_name}${member.is_master ? ' · Master' : ''}`;
    badge.setAttribute('aria-label', `Your username: ${badge.title}`);
  };
  const activate = async data => {
    landing.hidden = true; unavailable.hidden = true; chat.hidden = false; updateUser(data.member); updateMeta(data.room, data.member);
    for (const message of data.messages) await display(message);
    scrollToLatest();
    poll();
  };
  const poll = async () => {
    if (!token || joining) return;
    try {
      const data = await request(`/api/chats/${token}/messages?after=${lastId}`);
      updateUser(data.member); updateMeta(data.room, data.member);
      for (const message of data.messages) await display(message);
      if (data.messages.length) scrollToLatest();
    }
    catch (error) { if (error.data?.join_required) showJoin(); else if (error.data?.moderation_denied) { showUnavailable(); unavailable.querySelector('.intro').textContent = error.message; } else console.warn(error.message); }
    setTimeout(poll, 2500);
  };
  const showJoin = () => {
    if (joining) return;
    joining = true;
    document.querySelector('#join-name').value = randomName();
    joinDialog.showModal();
  };
  const showUnavailable = () => {
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
        if (!user.is_master) {
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
  document.querySelector('#message-body').addEventListener('input', event => document.querySelector('#char-count').textContent = `${event.target.value.length} / 12000`);
  document.querySelector('#message-body').addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      composer.requestSubmit(composer.querySelector('button[type="submit"]'));
    }
  });
  document.querySelector('#message-body').addEventListener('paste', event => {
    const imageItem = Array.from(event.clipboardData?.items || []).find(item => item.type.startsWith('image/'));
    if (!imageItem) return;
    event.preventDefault();
    uploadImage(imageItem.getAsFile()).catch(error => alert(error.message));
  });
  const composer = document.querySelector('#message-form');
  document.querySelector('#file-input').addEventListener('change', async event => {
    try { await uploadImage(event.target.files[0]); } catch(error) { alert(error.message); }
    event.target.value = '';
  });
  composer.addEventListener('dragover', event => { event.preventDefault(); composer.classList.add('drop-target'); });
  composer.addEventListener('dragleave', () => composer.classList.remove('drop-target'));
  composer.addEventListener('drop', event => {
    event.preventDefault(); composer.classList.remove('drop-target');
    const image = Array.from(event.dataTransfer?.files || [])[0];
    if (image) uploadImage(image).catch(error => alert(error.message));
    else alert('Drop a file to share it.');
  });
  composer.addEventListener('submit', async event => {
    event.preventDefault(); const field = document.querySelector('#message-body'); const button = event.currentTarget.querySelector('button');
    if (button.disabled || !field.value.trim()) return;
    button.disabled = true;
    try { const message = await ChatCrypto.send(roomKey,token,new TextEncoder().encode(field.value).buffer,{kind:'text',mime:'text/markdown',name:''}); await display(message); field.value = ''; document.querySelector('#char-count').textContent = '0 / 12000'; scrollToLatest(); }
    catch (error) { alert(error.message); } finally { button.disabled = false; }
  });
  const shareDialog = document.querySelector('#share-dialog');
  const shareUrl = document.querySelector('#share-url');
  document.querySelector('#copy-link').addEventListener('click', () => {
    shareUrl.value = location.href;
    shareDialog.showModal();
    requestAnimationFrame(() => shareUrl.select());
  });
  document.querySelector('#share-copy').addEventListener('click', event => copy(shareUrl.value, event.currentTarget));
  const menuToggle = document.querySelector('#chat-menu-toggle');
  const menuActions = document.querySelector('#chat-actions');
  const closeMenu = () => { menuToggle.setAttribute('aria-expanded', 'false'); menuActions.classList.remove('is-open'); };
  menuToggle.addEventListener('click', () => {
    const open = menuToggle.getAttribute('aria-expanded') !== 'true';
    menuToggle.setAttribute('aria-expanded', String(open)); menuActions.classList.toggle('is-open', open);
  });
  document.addEventListener('click', event => { if (!menuActions.contains(event.target) && !menuToggle.contains(event.target)) closeMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') { closeMenu(); menuToggle.focus(); } });
  menuActions.addEventListener('click', event => { if (event.target.closest('button')) closeMenu(); });
  document.querySelector('#file-preview').addEventListener('close', clearPreviewUrl);
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
