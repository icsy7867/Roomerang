(() => {
  const view = document.querySelector('#shared-view');
  const status = document.querySelector('#shared-status');
  const messageElement = document.querySelector('#shared-message');
  const shareToken = view.dataset.shareToken;
  const app = document.querySelector('#app');
  const escape = value => value.replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
  const highlight = (code, language) => {
    let html = escape(code);
    if (/^(js|javascript|ts|typescript|php|python|py|java|c|cpp|css|json|bash|sh|sql|html|xml)$/i.test(language)) {
      html = html.replace(/(&quot;(?:\\.|[^&])*?&quot;|'(?:\\.|[^'])*?')/g, '<span class="str">$1</span>');
      html = html.replace(/\b(\d+(?:\.\d+)?)\b/g, '<span class="num">$1</span>');
      html = html.replace(/\b(function|return|const|let|var|class|new|if|else|for|while|public|private|static|async|await|echo|SELECT|FROM|WHERE|INSERT|CREATE|def|import|from|true|false|null|NULL)\b/gi, '<span class="kw">$1</span>');
    }
    return html;
  };
  const inline = source => {
    const links = [];
    const text = source.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (match, label, href) => {
      try {
        const url = new URL(href);
        if (!['http:', 'https:'].includes(url.protocol)) return match;
        links.push(`<a href="${escape(url.href)}" target="_blank" rel="noopener noreferrer">${escape(label)}</a>`);
        return `\u0000LINK${links.length - 1}\u0000`;
      } catch (_) { return match; }
    });
    return escape(text).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\*([^*]+)\*/g, '<em>$1</em>').replace(/\u0000LINK(\d+)\u0000/g, (_, index) => links[Number(index)]);
  };
  const renderMarkdown = source => {
    const blocks = source.split(/```([^\n]*)\n([\s\S]*?)```/g);
    return blocks.map((block, index) => {
      if (index % 3 === 1) return '';
      if (index % 3 === 2) return `<pre><button class="code-copy quiet" data-code="${encodeURIComponent(block)}">Copy</button><code>${highlight(block, blocks[index - 1].trim())}</code></pre>`;
      return block.split(/\n{2,}/).filter(Boolean).map(part => {
        if (/^#{1,3}\s/.test(part)) { const match = part.match(/^(#{1,3})\s+([\s\S]*)$/); return `<h${match[1].length}>${inline(match[2])}</h${match[1].length}>`; }
        return `<p>${inline(part).replace(/\n/g, '<br>')}</p>`;
      }).join('');
    }).join('');
  };
  const unavailable = message => { status.textContent = message; };
  const request = async url => {
    const response = await fetch(url, {cache:'no-store'});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'This shared message is unavailable or has expired.');
    return data;
  };
  const show = async () => {
    app.querySelector('#landing').hidden = true;
    app.querySelector('#unavailable').hidden = true;
    app.querySelector('#chat').hidden = true;
    view.hidden = false;
    try {
      const key = await ChatCrypto.importKey(location.hash.slice(1));
      const metadata = await request(`/api/shares/${shareToken}`);
      const shared = metadata.message;
      const content = await ChatCrypto.receiveShared(key, shared.room_token, shareToken);
      let text = '';
      let fileData = content.data;
      if (content.kind === 'text') text = new TextDecoder().decode(content.data);
      if (content.kind === 'attachment') {
        text = new TextDecoder().decode(content.data.slice(0, content.text_bytes));
        fileData = content.data.slice(content.text_bytes);
      }
      messageElement.querySelector('.author').textContent = shared.display_name;
      messageElement.querySelector('time').textContent = new Date(shared.created_at * 1000).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
      const body = messageElement.querySelector('.message-body');
      if (text) body.innerHTML = renderMarkdown(text);
      if (content.kind === 'file' || content.kind === 'attachment') {
        const blob = new Blob([fileData], {type:content.mime || 'application/octet-stream'});
        const url = URL.createObjectURL(blob);
        window.addEventListener('pagehide', () => URL.revokeObjectURL(url), {once:true});
        if (['image/png','image/jpeg','image/gif','image/webp'].includes(content.mime)) {
          const image = document.createElement('img');
          image.className = 'shared-image'; image.src = url; image.alt = content.name || 'Shared image';
          body.append(image);
        }
        const download = document.createElement('a');
        download.href = url; download.download = content.name || 'download'; download.textContent = `Download ${content.name || 'attachment'}`;
        body.append(download);
      }
      body.querySelectorAll('.code-copy').forEach(button => button.addEventListener('click', async () => {
        await navigator.clipboard.writeText(decodeURIComponent(button.dataset.code));
        button.textContent = 'Copied';
      }));
      status.hidden = true;
      messageElement.hidden = false;
    } catch (error) { unavailable(error.message); }
  };
  show();
})();
