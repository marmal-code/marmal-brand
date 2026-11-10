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
  /* ---------- Drobnosti bez inline skriptů (kvůli Content-Security-Policy) ---------- */
  document.addEventListener('click', function (ev) {
    var c = ev.target.closest('[data-confirm-click]');
    if (c && !window.confirm(c.getAttribute('data-confirm-click'))) {
      ev.preventDefault();
      return;
    }
    if (ev.target.closest('[data-print]')) {
      window.print();
    }
  });
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-autosubmit]') && ev.target.form) {
      ev.target.form.submit();
    }
  });

  /* ---------- QR kód pro dvoufázové ověření (vzniká v prohlížeči, klíč nikam neodchází) ---------- */
  var qrEls = document.querySelectorAll('[data-qr]');
  if (qrEls.length) {
    var qs = document.createElement('script');
    qs.src = '/assets/vendor/qrcode.js';
    qs.onload = function () {
      qrEls.forEach(function (el) {
        var qr = window.qrcode(0, 'M');
        qr.addData(el.getAttribute('data-qr'));
        qr.make();
        el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
      });
    };
    document.head.appendChild(qs);
  }

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

  /* ---------- WebP: SVG bez PNG vykreslí prohlížeč, převod do WebP udělá server ---------- */
  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Server vrátil chybu ' + r.status + '.' }; });
    }).then(function (j) {
      if (!j.ok) throw new Error(j.error || 'Požadavek selhal.');
      return j;
    });
  }

  // SVG → PNG na plátně. Delší strana = maxSide px, poměr podle viewBoxu.
  function svgToPng(url, maxSide) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('SVG se nepodařilo načíst.');
      return r.text();
    }).then(function (txt) {
      var doc = new DOMParser().parseFromString(txt, 'image/svg+xml');
      var el = doc.documentElement;
      if (!el || el.nodeName.toLowerCase() !== 'svg') throw new Error('Soubor není platné SVG.');
      var vb = (el.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(parseFloat);
      var w = parseFloat(el.getAttribute('width')) || 0;
      var h = parseFloat(el.getAttribute('height')) || 0;
      if (vb.length === 4 && vb[2] > 0 && vb[3] > 0) {
        w = vb[2]; h = vb[3];
      } else if (w > 0 && h > 0) {
        el.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
      } else {
        w = h = maxSide;
      }
      var scale = maxSide / Math.max(w, h);
      var W = Math.max(1, Math.round(w * scale));
      var H = Math.max(1, Math.round(h * scale));
      el.setAttribute('width', String(W));
      el.setAttribute('height', String(H));
      var src = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(el)], { type: 'image/svg+xml' }));
      return new Promise(function (resolve, reject) {
        var img = new Image();
        img.onload = function () {
          var c = document.createElement('canvas');
          c.width = W;
          c.height = H;
          c.getContext('2d').drawImage(img, 0, 0, W, H);
          URL.revokeObjectURL(src);
          c.toBlob(function (b) { b ? resolve(b) : reject(new Error('Obrázek se nepodařilo vytvořit.')); }, 'image/png');
        };
        img.onerror = function () { URL.revokeObjectURL(src); reject(new Error('SVG se nepodařilo vykreslit.')); };
        img.src = src;
      });
    });
  }

  function webpFromSvg(task) {
    return svgToPng(task.url, task.size).then(function (png) {
      return uploadFile(png, function () {});
    }).then(function (uploadId) {
      return postJson('/admin/items/' + task.item + '/webp', { upload_id: uploadId, svg_file: task.file });
    });
  }

  // Postupně projde úkoly; chyby sbírá, ať jedna vadná pozice nezastaví ostatní.
  function runWebpTasks(tasks, onStep) {
    var errors = [];
    var added = 0;
    var steps = [];
    tasks.forEach(function (t) {
      if (t.raster) steps.push({ label: t.title, run: function () { return postJson('/admin/items/' + t.item + '/webp', {}); } });
      (t.svg || []).forEach(function (s) {
        steps.push({ label: (t.title ? t.title + ' – ' : '') + s.name, run: function () { return webpFromSvg(s); } });
      });
    });
    var chain = Promise.resolve();
    steps.forEach(function (step, i) {
      chain = chain.then(function () {
        if (onStep) onStep(i, steps.length, step.label);
        return step.run().then(function (j) {
          added += j.added || 0;
          (j.skipped || []).forEach(function (m) { errors.push(m); });
        }).catch(function (err) { errors.push(step.label + ': ' + err.message); });
      });
    });
    return chain.then(function () {
      if (onStep) onStep(steps.length, steps.length, '');
      return { added: added, errors: errors };
    });
  }

  var runner = document.querySelector('[data-webp-runner]');
  if (runner) {
    var runBtn = runner.querySelector('[data-webp-start]');
    var bar = runner.querySelector('[data-webp-bar]');
    var stat = runner.querySelector('[data-webp-status]');
    var log = runner.querySelector('[data-webp-log]');
    runBtn.addEventListener('click', function () {
      var tasks = [];
      try { tasks = JSON.parse(runner.getAttribute('data-tasks') || '[]'); } catch (e) {}
      runBtn.disabled = true;
      window.onbeforeunload = function () { return 'Převod do WebP ještě běží.'; };
      runWebpTasks(tasks, function (i, n, label) {
        bar.style.width = Math.round(i / Math.max(1, n) * 100) + '%';
        stat.textContent = i < n ? 'Převádím ' + (i + 1) + ' / ' + n + ': ' + label : 'Hotovo.';
      }).then(function (r) {
        window.onbeforeunload = null;
        stat.textContent = 'Hotovo. Vytvořil jsem ' + r.added + ' WebP.' + (r.errors.length ? ' Některé soubory se převést nepodařilo:' : '');
        r.errors.forEach(function (m) {
          var li = document.createElement('li');
          li.className = 'alert alert-error';
          li.textContent = m;
          log.appendChild(li);
        });
        if (!r.errors.length) setTimeout(function () { window.location.reload(); }, 1200);
        else runBtn.disabled = false;
      });
    });
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
      var webpEl = box.querySelector('[data-webp]');
      var webp = !!(webpEl && webpEl.checked);
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
          body: JSON.stringify({ mode: mode, note: note, files: done, webp: webp })
        }).then(function (r) { return r.json(); });
      }).then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Uložení selhalo.');
        if (j.webp_svg && j.webp_svg.length) {
          statusEl.textContent = 'Vytvářím WebP ze SVG…';
          return runWebpTasks([{ item: itemId, title: '', raster: 0, svg: j.webp_svg }]).then(function (r) {
            if (r.errors.length) window.alert('Soubory jsou nahrané, ale WebP se nepodařilo vytvořit:\n' + r.errors.join('\n'));
            return j;
          });
        }
        return j;
      }).then(function (j) {
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

  /* ---------- Odkaz „Smazat“ z přehledu klientů: kurzor rovnou do pole pro opsání adresy ---------- */
  if (location.hash === '#smazat') {
    var zone = document.getElementById('smazat');
    var inp = zone && zone.querySelector('input[name="confirm"]');
    if (inp) {
      zone.scrollIntoView({ block: 'center' });
      inp.focus({ preventScroll: true });
    }
  }
})();
