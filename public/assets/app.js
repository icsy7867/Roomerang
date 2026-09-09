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
  const display = message => {
    if (document.querySelector(`[data-id="${message.id}"]`)) return;
    const element = document.querySelector('#message-template').content.firstElementChild.cloneNode(true);
    element.dataset.id = message.id;
    element.classList.toggle('mine', message.is_mine === true || message.is_mine === 1);
    element.classList.toggle('has-image', Boolean(message.image_url));
    element.querySelector('.author').textContent = message.display_name;
    element.querySelector('time').textContent = new Date(message.created_at * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    const messageBody = element.querySelector('.message-body');
    if (message.image_url) {
      const image = document.createElement('img');
      image.className = 'shared-image';
      image.src = message.image_url;
      image.alt = 'Shared image';
      image.loading = 'lazy';
      messageBody.replaceChildren(image);
    } else {
      messageBody.innerHTML = renderMarkdown(message.body);
    }
    const copyButton = element.querySelector('.copy-message');
    if (message.body) copyButton.addEventListener('click', () => copy(message.body, copyButton));
    else copyButton.hidden = true;
    element.querySelectorAll('.code-copy').forEach(button => button.addEventListener('click', () => copy(decodeURIComponent(button.dataset.code), button)));
    messages.append(element);
    lastId = Math.max(lastId, Number(message.id));
  };
  const uploadImage = async file => {
    if (!file || !file.type.startsWith('image/')) throw new Error('Drop or paste an image file.');
    if (file.size > 8 * 1024 * 1024) throw new Error('Images must be no larger than 8 MB.');
    const form = new FormData();
    form.append('image', file, file.name || 'pasted-image');
    const response = await fetch(`/api/chats/${token}/images`, {method: 'POST', body: form});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'Image upload failed.');
    display(data.message);
    scrollToLatest();
  };
  const updateMeta = room => {
    const date = new Date(room.expires_at * 1000);
    document.querySelector('#room-meta').textContent = `Expires ${date.toLocaleString()} · ${room.history_mode === 'all' ? 'full history' : 'from joining'}`;
  };
  const updateUser = member => {
    document.querySelector('#user-meta').textContent = `You: ${member.display_name}`;
  };
  const activate = data => {
    landing.hidden = true; unavailable.hidden = true; chat.hidden = false; updateMeta(data.room); updateUser(data.member);
    data.messages.forEach(display);
    scrollToLatest();
    poll();
  };
  const poll = async () => {
    if (!token || joining) return;
    try { const data = await request(`/api/chats/${token}/messages?after=${lastId}`); data.messages.forEach(display); }
    catch (error) { if (error.data?.join_required) showJoin(); else console.warn(error.message); }
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
    try {
      activate(await request(`/api/chats/${token}/messages`));
    } catch (error) {
      if (error.data?.join_required) showJoin();
      else showUnavailable();
    }
  };

  document.querySelector('#create-form').addEventListener('submit', async event => {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    try { const data = await request('/api/chats', {method: 'POST', body: JSON.stringify(Object.fromEntries(form))}); location.assign(`/chat/${data.room.token}`); }
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
      composer.requestSubmit();
    }
  });
  document.querySelector('#message-body').addEventListener('paste', event => {
    const imageItem = Array.from(event.clipboardData?.items || []).find(item => item.type.startsWith('image/'));
    if (!imageItem) return;
    event.preventDefault();
    uploadImage(imageItem.getAsFile()).catch(error => alert(error.message));
  });
  const composer = document.querySelector('#message-form');
  composer.addEventListener('dragover', event => { event.preventDefault(); composer.classList.add('drop-target'); });
  composer.addEventListener('dragleave', () => composer.classList.remove('drop-target'));
  composer.addEventListener('drop', event => {
    event.preventDefault(); composer.classList.remove('drop-target');
    const image = Array.from(event.dataTransfer?.files || []).find(file => file.type.startsWith('image/'));
    if (image) uploadImage(image).catch(error => alert(error.message));
    else alert('Drop an image file to share it.');
  });
  composer.addEventListener('submit', async event => {
    event.preventDefault(); const field = document.querySelector('#message-body'); const button = event.currentTarget.querySelector('button');
    button.disabled = true;
    try { const data = await request(`/api/chats/${token}/messages`, {method: 'POST', body: JSON.stringify({body: field.value})}); display(data.message); field.value = ''; document.querySelector('#char-count').textContent = '0 / 12000'; scrollToLatest(); }
    catch (error) { alert(error.message); } finally { button.disabled = false; }
  });
  document.querySelector('#copy-link').addEventListener('click', event => copy(location.href, event.currentTarget));
  if (token) {
    if (/^[A-Za-z0-9_-]{43}$/.test(token)) openRoom();
    else showUnavailable();
  }
})();
