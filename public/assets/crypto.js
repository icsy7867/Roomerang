/* Versioned binary envelope and transport. */
window.ChatCrypto = (() => {
  const encoder = new TextEncoder(), decoder = new TextDecoder('utf-8', {fatal:true});
  const MAX = 8 * 1024 * 1024;
  function available() {
    if (!globalThis.crypto?.subtle) throw new Error('Encrypted chat requires HTTPS (or localhost).');
  }
  const encodeKey = bytes => btoa(String.fromCharCode(...bytes)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
  function newKey() { available(); return encodeKey(crypto.getRandomValues(new Uint8Array(32))); }
  async function importKey(fragment) {
    available();
    if (!/^[A-Za-z0-9_-]{43}$/.test(fragment)) throw new Error('The link is missing its encryption key or has an invalid key. Ask for the complete link, including # and everything after it.');
    const bytes=Uint8Array.from(atob(fragment.replace(/-/g,'+').replace(/_/g,'/')+'='),c=>c.charCodeAt(0));
    return crypto.subtle.importKey('raw',bytes,'AES-GCM',false,['encrypt','decrypt']);
  }
  function envelope(data, metadata) {
    if(data.byteLength>MAX) throw new Error('Files must be no larger than 8 MB.');
    const header=encoder.encode(JSON.stringify({v:1,...metadata}));
    if(header.length>4096) throw new Error('File metadata is too long.');
    const bytes=new Uint8Array(4+header.length+data.byteLength);
    new DataView(bytes.buffer).setUint32(0,header.length);
    bytes.set(header,4); bytes.set(new Uint8Array(data),4+header.length);
    return bytes.buffer;
  }
  function unpack(bytes) {
    if(bytes.byteLength<4) throw new Error('Invalid encrypted content.');
    const length=new DataView(bytes).getUint32(0);
    if(length>4096 || length+4>bytes.byteLength) throw new Error('Invalid content metadata.');
    const metadata=JSON.parse(decoder.decode(bytes.slice(4,4+length)));
    const supported = (metadata.v === 1 && ['text','file'].includes(metadata.kind)) || (metadata.v === 2 && metadata.kind === 'attachment');
    if(!supported || typeof metadata.mime!=='string' || typeof metadata.name!=='string') throw new Error('Unsupported content version.');
    const data=bytes.slice(4+length);
    if(data.byteLength>MAX) throw new Error('Content exceeds 8 MB.');
    if (metadata.kind === 'attachment' && (!Number.isInteger(metadata.text_bytes) || metadata.text_bytes < 0 || metadata.text_bytes > data.byteLength)) throw new Error('Invalid attachment content.');
    return {...metadata,data};
  }
  async function encryptContent(key, room, data) {
    const nonce=crypto.getRandomValues(new Uint8Array(12));
    const ciphertext=await crypto.subtle.encrypt({name:'AES-GCM',iv:nonce,additionalData:encoder.encode('paste-chat:v1:'+room)},key,data);
    const wire=new Uint8Array(12+ciphertext.byteLength); wire.set(nonce); wire.set(new Uint8Array(ciphertext),12); return wire;
  }
  async function decryptContent(key,room,wire) {
    if(wire.byteLength<32 || wire.byteLength>MAX+4132) throw new Error('Invalid encrypted payload.');
    return crypto.subtle.decrypt({name:'AES-GCM',iv:wire.slice(0,12),additionalData:encoder.encode('paste-chat:v1:'+room)},key,wire.slice(12));
  }
  async function responseData(response) {
    const text=await response.text();
    if (!text) return {};
    try { return JSON.parse(text); } catch (_) { return {}; }
  }
  async function send(key,room,data,metadata) {
    const wire=await encryptContent(key,room,envelope(data,metadata));
    const response=await fetch(`/api/chats/${room}/messages`,{method:'POST',headers:{'Content-Type':'application/octet-stream'},body:wire});
    const result=await responseData(response);
    if (!response.ok) {
      if (response.status===413) throw new Error('This upload is larger than the server or reverse proxy allows. Choose a smaller file or increase the proxy request-size limit.');
      if (response.status===403) throw new Error(result.error || 'You cannot post in this chat. It may be read-only, or your session no longer has access.');
      throw new Error(result.error || `Upload failed (HTTP ${response.status}).`);
    }
    return result.message;
  }
  async function receive(key,room,id) {
    let wire;
    for (let attempt = 0; attempt < 3; attempt++) {
      let status = 0;
      try {
        const response = await fetch(`/api/chats/${room}/messages/${id}`, {cache:'no-store'});
        status = response.status;
        if (!response.ok) throw new Error('Download failed.');
        wire = await response.arrayBuffer();
        break;
      } catch (_) {
        const temporary = status === 0 || status === 408 || status === 429 || status >= 500;
        if (temporary && attempt < 2) {
          await new Promise(resolve => setTimeout(resolve, 400 * (attempt + 1)));
          continue;
        }
        const error = new Error(temporary ? 'This message could not be downloaded. Please retry.' : 'This message is unavailable or you no longer have access.');
        error.name = 'MessageDownloadError';
        throw error;
      }
    }
    return unpack(await decryptContent(key,room,wire));
  }
  async function receiveShared(key, room, token) {
    const response = await fetch(`/api/shares/${token}/content`, {cache:'no-store'});
    if (!response.ok) throw new Error('This shared message is unavailable or has expired.');
    return unpack(await decryptContent(key, room, await response.arrayBuffer()));
  }
  return {newKey,importKey,encryptContent,decryptContent,envelope,unpack,send,receive,receiveShared};
})();
