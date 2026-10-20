/* Brand Hub – drobné interakce bez knihoven */
(function () {
  'use strict';

  // Velikost kusu určuje server podle svých limitů (max. 8 MB)
  var CHUNK = parseInt((document.querySelector('meta[name="upload-chunk"]') || {}).content, 10) || 4 * 1024 * 1024;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  /* ---------- Toast ---------- */
  var toastEl;
  function toast(msg) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
  }

  /* ---------- Kopírování do schránky ---------- */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return Promise.resolve();
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    ev.preventDefault();
    copyText(btn.getAttribute('data-copy')).then(function () {
      toast(btn.getAttribute('data-copy-msg') || 'Zkopírováno: ' + btn.getAttribute('data-copy'));
    });
  });

  /* ---------- Potvrzení před odesláním ---------- */
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
      return;
    }
    var busy = ev.target.querySelector('[data-busy]');
    if (busy) {
      busy.disabled = true;
      busy.textContent = busy.getAttribute('data-busy');
    }
  });

  /* ---------- Barvy: přidání řádku, náhled ---------- */
  var addColor = document.querySelector('[data-add-color]');
  if (addColor) {
    addColor.addEventListener('click', function () {
      var tpl = document.getElementById('color-row-template');
      var body = document.querySelector('[data-colors-body]');
      var idx = Date.now();
      var html = tpl.innerHTML.replace(/__i__/g, 'n' + idx);
      body.insertAdjacentHTML('beforeend', html);
      var last = body.lastElementChild;
      if (last) { var f = last.querySelector('input'); if (f) f.focus(); }
    });
  }
  document.addEventListener('input', function (ev) {
    var t = ev.target;
    if (t.matches('[data-hex]')) {
      var row = t.closest('tr, .color-input');
      var sw = row && row.querySelector('[data-swatch]');
      var picker = row && row.querySelector('input[type=color]');
      var v = t.value.trim();
      if (v && v[0] !== '#') v = '#' + v;
      if (/^#[0-9a-fA-F]{6}$/.test(v)) {
        if (sw) sw.style.background = v;
        if (picker) picker.value = v.toLowerCase();
      }
    }
    if (t.matches('input[type=color][data-pick-for]')) {
      var target = document.getElementById(t.getAttribute('data-pick-for'));
      if (target) {
        target.value = t.value.toUpperCase();
        target.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
  });

  /* ---------- Nahrávání souborů po částech ---------- */
  function uid() {
    var a = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function humanSize(b) {
    var u = ['B', 'kB', 'MB', 'GB'], i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (Math.round(b * (i >= 2 ? 10 : 1)) / (i >= 2 ? 10 : 1)).toString().replace('.', ',') + ' ' + u[i];
  }

  function sendChunk(fd, attempt) {
    return fetch('/admin/upload-chunk', {
      method: 'POST',
      body: fd,
      headers: { 'X-CSRF-Token': csrf },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Server vrátil chybu ' + r.status + '.' }; });
    }).then(function (j) {
      if (!j.ok) throw new Error(j.error || 'Nahrávání selhalo.');
      return j;
    }).catch(function (err) {
      if (attempt < 3) {
        return new Promise(function (res) { setTimeout(res, 1500 * attempt); }).then(function () { return sendChunk(fd, attempt + 1); });
      }
      throw err;
    });
  }

  function uploadFile(file, onProgress) {
    var id = uid();
    var total = Math.max(1, Math.ceil(file.size / CHUNK));
    var index = 0;
    function next() {
      if (index >= total) return Promise.resolve(id);
      var fd = new FormData();
      fd.append('upload_id', id);
      fd.append('index', String(index));
      fd.append('chunk', file.slice(index * CHUNK, Math.min(file.size, (index + 1) * CHUNK)), 'chunk');
      return sendChunk(fd, 1).then(function () {
        index++;
        onProgress(Math.min(1, index / total));
        return next();
      });
    }
    return next();
  }

  document.querySelectorAll('[data-uploader]').forEach(function (box) {
    var itemId = box.getAttribute('data-item');
    var commitUrl = box.getAttribute('data-commit') || ('/admin/items/' + itemId + '/upload');
    var simple = !!box.getAttribute('data-commit'); // import: jeden soubor, bez volby použití
    var input = box.querySelector('input[type=file]');
    var drop = box.querySelector('[data-drop]');
    var queueEl = box.querySelector('[data-queue]');
    var startBtn = box.querySelector('[data-start]');
    var statusEl = box.querySelector('[data-status]');
    var queue = [];

    function render() {
      queueEl.innerHTML = '';
      queue.forEach(function (q, i) {
        var row = document.createElement('div');
        row.className = 'queue-row';
        row.innerHTML =
          '<span class="qname"></span><span class="muted tiny"></span>' +
          (simple ? '' :
          '<label class="sr-only" for="role-' + itemId + '-' + i + '">Použití souboru</label>' +
          '<select class="select input-sm" style="width:auto" id="role-' + itemId + '-' + i + '">' +
          '<option value="download">Ke stažení</option><option value="preview">Jen náhled</option></select>') +
          '<button type="button" class="btn btn-ghost btn-sm" aria-label="Odebrat soubor">Odebrat</button>' +
          '<div class="qbar"><span></span></div>';
        row.querySelector('.qname').textContent = q.file.name;
        row.querySelector('.muted').textContent = humanSize(q.file.size);
        var sel = row.querySelector('select');
        if (sel) {
          sel.value = q.role;
          sel.addEventListener('change', function () { q.role = sel.value; });
        }
        row.querySelector('button').addEventListener('click', function () { queue.splice(i, 1); render(); });
        q.bar = row.querySelector('.qbar span');
        queueEl.appendChild(row);
      });
      startBtn.disabled = queue.length === 0;
    }
    function add(files) {
      if (simple) queue = [];
      Array.prototype.forEach.call(simple ? Array.prototype.slice.call(files, 0, 1) : files, function (f) {
        queue.push({ file: f, role: 'download' });
      });
      render();
    }

    input.addEventListener('change', function () { add(input.files); input.value = ''; });
    drop.addEventListener('click', function (e) { if (!e.target.closest('button')) input.click(); });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    ['dragenter', 'dragover'].forEach(function (n) {
      drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (n) {
      drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) add(e.dataTransfer.files); });

    startBtn.addEventListener('click', function () {
      if (!queue.length) return;
      var modeEl = box.querySelector('input[name="mode-' + itemId + '"]:checked');
      var mode = modeEl ? modeEl.value : 'new';
      var note = (box.querySelector('[data-note]') || {}).value || '';
      startBtn.disabled = true;
      box.querySelectorAll('button, select, input').forEach(function (el) { el.disabled = true; });
      statusEl.className = 'small';
      statusEl.textContent = 'Nahrávám… stránku prosím nezavírejte.';
      window.onbeforeunload = function () { return 'Nahrávání ještě běží.'; };

      var done = [];
      var chain = Promise.resolve();
      queue.forEach(function (q) {
        chain = chain.then(function () {
          return uploadFile(q.file, function (p) { q.bar.style.width = Math.round(p * 100) + '%'; })
            .then(function (id) { done.push({ upload_id: id, name: q.file.name, role: q.role }); });
        });
      });
      chain.then(function () {
        statusEl.textContent = simple ? 'Importuji… může to chvíli trvat.' : 'Ukládám…';
        return fetch(commitUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          credentials: 'same-origin',
          body: JSON.stringify({ mode: mode, note: note, files: done })
        }).then(function (r) { return r.json(); });
      }).then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Uložení selhalo.');
        window.onbeforeunload = null;
        if (j.redirect) { window.location.href = j.redirect; } else { window.location.reload(); }
      }).catch(function (err) {
        window.onbeforeunload = null;
        statusEl.className = 'small alert alert-error';
        statusEl.textContent = err.message + ' Zkuste to prosím znovu.';
        box.querySelectorAll('button, select, input').forEach(function (el) { el.disabled = false; });
      });
    });

    render();
  });

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-toggle]');
    if (!t) return;
    var el = document.getElementById(t.getAttribute('data-toggle'));
    if (!el) return;
    var open = el.classList.toggle('open');
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var d = el.querySelector('[data-drop]'); if (d) d.focus(); }
  });
})();
