// Mockup kit for the redesigned "Fișiere de semnat" card. Each page sets
// window.PAGE before loading this file; the kit renders a responsive shell
// (sidebar hidden under 1024px), the tab chrome, the status panel and the card.
// Static mockup: no application logic, only the toggles a real page would have.
(function () {
  const style = document.createElement('style');
  style.textContent = `
    body { font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; }
    .tnum { font-variant-numeric: tabular-nums; }
    :focus-visible { outline: 2px solid #2B4F7C; outline-offset: 2px; border-radius: 8px; }
    label.filebtn:has(input:focus-visible) { outline: 2px solid #2B4F7C; outline-offset: 2px; }
    .tt { position: relative; display: inline-flex; }
    .tt-pop { display: none; position: absolute; z-index: 40; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
      width: max-content; max-width: 18rem; background: #0f172a; color: #f8fafc; font-size: 12.5px; line-height: 1.45;
      padding: 8px 10px; border-radius: 8px; box-shadow: 0 8px 24px -6px rgba(15,23,42,.35); font-weight: 400; text-align: left; white-space: normal; }
    .tt-pop::after { content: ''; position: absolute; top: 100%; left: 50%; margin-left: -5px; border: 5px solid transparent; border-top-color: #0f172a; }
    @media (max-width: 639px) { .tt-pop { left: -8px; transform: none; max-width: calc(100vw - 64px); } .tt-pop::after { left: 16px; } }
    .tt:hover .tt-pop, .tt:focus-within .tt-pop, .tt[data-open] .tt-pop { display: block; }
    .menu-pop[hidden] { display: none; }
    .rot { transition: transform .15s ease; }
    [aria-expanded="true"] .rot { transform: rotate(180deg); }
    @keyframes spin { to { transform: rotate(360deg); } }
    .spin { animation: spin 1s linear infinite; }
    @media (prefers-reduced-motion: reduce) { .rot { transition: none; } .spin { animation: none; } }
  `;
  document.head.appendChild(style);
  tailwind.config = {
    theme: { extend: {
      colors: { 'lex-navy': '#1E3A5F', 'lex-navy-dark': '#162A45', 'lex-navy-light': '#2B4F7C' },
      boxShadow: { soft: '0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 12px -2px rgba(15, 23, 42, 0.05)' },
    } },
  };

  // Icons (24px grid, stroke).
  const P = {
    check: '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>',
    warn: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01M10.3 3.9L2.4 17.6A2 2 0 004.1 20.6h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
    upload: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>',
    download: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>',
    chevron: '<path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/>',
    info: '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5m0-8.5h.01"/>',
    x: '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M9 9l6 6m0-6l-6 6"/>',
    doc: '<path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v13H7zM14 3v5h5"/>',
    refresh: '<path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M20 20v-6h-6M5.5 15a7 7 0 0011.9 2.5L20 14M18.5 9A7 7 0 006.6 6.5L4 10"/>',
    textplus: '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6h10M4 12h7M4 18h6M17 13v6m-3-3h6"/>',
    hash: '<path stroke-linecap="round" d="M9 4L7 20M17 4l-2 16M4 9h16M3 15h16"/>',
    pageplus: '<path stroke-linecap="round" stroke-linejoin="round" d="M6 3h8l4 4v6M6 3v18h7M14 3v4h4M18 16v6m-3-3h6"/>',
    badge: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path stroke-linecap="round" d="M5.8 16a3.4 3.4 0 016.4 0M14 10h4M14 13h3"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/>',
    tag: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
    shield: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/>',
    layers: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l9 5-9 5-9-5zM3 13l9 5 9-5"/>',
    menu: '<path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/>',
    scale: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 7h14M5 7l-3 7a4 4 0 006 0zM19 7l-3 7a4 4 0 006 0zM8 21h8"/>',
    pen: '<path stroke-linecap="round" stroke-linejoin="round" d="M15 4l5 5L9 20H4v-5z"/>',
    send: '<path stroke-linecap="round" stroke-linejoin="round" d="M4 12l16-8-6 16-2.5-6.5z"/>',
  };
  const svg = (k, cls = 'size-4', sw = 1.8) => `<svg class="${cls}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="${sw}" aria-hidden="true">${P[k]}</svg>`;
  let uid = 0;
  const id = (p) => `${p}-${++uid}`;

  // Short labels for the one-line summary; the full sentences stay in the disclosure.
  const OBS = {
    cn_differs: { icon: 'badge', short: 'nume diferit în certificat', full: 'Numele din certificat diferă de numele contului. Este recomandată verificarea certificatului folosit la semnare.' },
    cert_not_valid: { icon: 'clock', short: 'certificat în afara valabilității', full: 'Certificatul nu era în perioada de valabilitate la data indicată în fișier.' },
    timestamp_appended: { icon: 'clock', short: 'marcă temporală adăugată', full: 'După semnătură a fost adăugată o marcă temporală a documentului.' },
    trailing_update: { icon: 'shield', short: 'date de validare adăugate', full: 'După semnătură au fost adăugate date de validare (de exemplu, de instrumentul de semnare).' },
    textless_pages: { icon: 'doc', short: 'pagini fără text', full: 'Unele pagini nu au text, deci au fost comparate doar ca număr.' },
    legacy_sub_filter: { icon: 'shield', short: 'format de semnătură depășit', full: 'Semnătura folosește formatul adbe.pkcs7.sha1, depășit. Validitatea semnăturii o verifică avocatul.' },
    text_added: { icon: 'textplus', short: (p) => `text adăugat în pagina ${p.page}`, full: (p) => p.words === 1 ? `Text suplimentar în pagina ${p.page} (un cuvânt în plus față de documentul generat).` : `Text suplimentar în pagina ${p.page} (${p.words} cuvinte în plus față de documentul generat).` },
    text_added_with_digits: { icon: 'hash', strong: true, short: (p) => `text cu cifre în pagina ${p.page}`, full: (p) => `Textul suplimentar din pagina ${p.page} conține cifre. Sumele și datele trebuie verificate înainte de confirmare.` },
    pages_appended: { icon: 'pageplus', short: (p) => p.count === 1 ? 'o pagină adăugată la final' : `${p.count} pagini adăugate la final`, full: (p) => p.count === 1 ? 'O pagină adăugată după ultima pagină generată. Conținutul ei ajunge la instanță.' : `${p.count} pagini adăugate după ultima pagină generată. Conținutul lor ajunge la instanță.` },
    part_id_missing: { icon: 'tag', short: 'identificator intern lipsă', full: 'Identificatorul intern al documentului lipsește din fișier; textul paginilor generate se regăsește.' },
  };
  const obsText = (o, k) => { const d = OBS[o.key][k]; return typeof d === 'function' ? d(o) : d; };

  const BTN = 'h-9 inline-flex items-center justify-center gap-1.5 rounded-lg px-3 text-sm whitespace-nowrap transition';
  const btn = {
    primary: `${BTN} bg-lex-navy text-white font-semibold hover:bg-lex-navy-dark shadow-sm`,
    outline: `${BTN} border border-lex-navy/70 text-lex-navy font-semibold bg-white hover:bg-blue-50`,
    neutral: `${BTN} border border-slate-200 bg-white text-slate-700 font-medium hover:bg-slate-50`,
    ghost: `${BTN} text-slate-600 font-medium hover:bg-slate-100`,
    amber: `${BTN} border border-amber-300 bg-white text-amber-900 font-semibold hover:bg-amber-100`,
  };

  function tooltip(text, trigger, label) {
    const tid = id('tt');
    return `<span class="tt" data-tt><button type="button" class="inline-flex items-center rounded-md" aria-describedby="${tid}" ${label ? `aria-label="${label}"` : ''}>${trigger}</button><span role="tooltip" id="${tid}" class="tt-pop">${text}</span></span>`;
  }

  function downloadControl(r) {
    if (!r.signed) {
      return `<a href="#" class="${btn.neutral} w-full sm:w-[8.25rem]" aria-label="Descarcă fișierul nesemnat: ${r.title}">${svg('download')}Descarcă</a>`;
    }
    const mid = id('menu');
    return `
      <div class="relative w-full sm:w-[8.25rem]" data-menu ${r.menuOpen ? 'data-force-open' : ''}>
        <button type="button" class="${btn.neutral} w-full" aria-haspopup="menu" aria-expanded="${r.menuOpen ? 'true' : 'false'}" aria-controls="${mid}">
          ${svg('download')}Descarcă${svg('chevron', 'size-3.5 rot text-slate-400', 2)}
        </button>
        <div id="${mid}" role="menu" class="menu-pop absolute right-0 top-full mt-1.5 z-30 w-64 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg" ${r.menuOpen ? '' : 'hidden'}>
          <a role="menuitem" href="#" class="flex items-start gap-2.5 rounded-lg px-2.5 py-2 hover:bg-slate-50 focus:bg-slate-50">
            ${svg('check', 'size-4 mt-0.5 text-emerald-600', 2.2)}
            <span><span class="block text-sm font-medium text-slate-900">Fișierul semnat</span><span class="block text-xs text-slate-500">încărcat ${r.signedAt || 'la 25.10.2026, 10:42'}</span></span>
          </a>
          <a role="menuitem" href="#" class="flex items-start gap-2.5 rounded-lg px-2.5 py-2 hover:bg-slate-50 focus:bg-slate-50">
            ${svg('doc', 'size-4 mt-0.5 text-slate-400')}
            <span><span class="block text-sm font-medium text-slate-900">Fișierul nesemnat</span><span class="block text-xs text-slate-500">generat de platformă</span></span>
          </a>
        </div>
      </div>`;
  }

  function primaryControl(r, ctx) {
    const W = 'w-full sm:w-[11rem]';
    if (!ctx.uploadsOpen) return '';
    switch (r.state) {
      case 'missing':
      case 'dragover':
        return `<label class="filebtn ${btn.outline} ${W} cursor-pointer"><input type="file" accept="application/pdf" class="sr-only" aria-label="Încarcă fișierul semnat: ${r.title}">${svg('upload')}Încarcă semnat</label>`;
      case 'refused':
        if (r.refusal.kind === 'over_budget') return '';
        return `<label class="filebtn ${btn.outline} ${W} cursor-pointer"><input type="file" accept="application/pdf" class="sr-only" aria-label="Încarcă din nou fișierul semnat: ${r.title}">${svg('upload')}Încarcă din nou</label>`;
      case 'uploading':
        return `<button type="button" class="${btn.neutral} ${W} opacity-70 cursor-wait" disabled>${svg('refresh', 'size-4 spin')}Se verifică</button>`;
      case 'awaiting':
        return `<button type="button" class="${r.open ? btn.outline : btn.primary} ${W}" aria-expanded="${r.open ? 'true' : 'false'}" aria-controls="panel-${r.id}" data-toggle="panel-${r.id}">Verifică și confirmă${svg('chevron', 'size-3.5 rot', 2)}</button>`;
      default:
        return `<label class="filebtn ${btn.ghost} ${W} cursor-pointer"><input type="file" accept="application/pdf" class="sr-only" aria-label="Înlocuiește fișierul semnat: ${r.title}">${svg('refresh')}Înlocuiește</label>`;
    }
  }

  function statusIcon(r) {
    const base = 'shrink-0 inline-flex items-center justify-center size-9 rounded-lg';
    switch (r.state) {
      case 'matched': case 'confirmed': return `<span class="${base} bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">${svg('check', 'size-4', 2.4)}</span>`;
      case 'awaiting': return `<span class="${base} bg-amber-100 text-amber-800 ring-1 ring-amber-200">${svg('warn', 'size-[18px]', 2)}</span>`;
      case 'refused': return `<span class="${base} bg-red-50 text-red-700 ring-1 ring-red-100">${svg('x', 'size-[18px]', 2)}</span>`;
      case 'uploading': return `<span class="${base} bg-blue-50 text-blue-700">${svg('refresh', 'size-4 spin', 2)}</span>`;
      default: return `<span class="${base} bg-slate-100 text-slate-500">${svg('doc', 'size-[18px]')}</span>`;
    }
  }

  const pill = (tone, text) => {
    const t = { slate: 'bg-slate-100 text-slate-700', green: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200', amber: 'bg-amber-100 text-amber-900 ring-1 ring-amber-200', red: 'bg-red-50 text-red-800 ring-1 ring-red-200', blue: 'bg-blue-50 text-blue-800' }[tone];
    return `<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${t}">${text}</span>`;
  };

  function obsList(r) {
    const all = [...(r.notes || []), ...(r.warnings || [])];
    return `<ul class="space-y-2">${all.map((o) => {
      const d = OBS[o.key];
      return `<li class="flex gap-2.5 text-sm ${d.strong ? 'font-semibold text-amber-950' : 'text-slate-700'}">
        <span class="shrink-0 mt-0.5 inline-flex items-center justify-center size-6 rounded-md ${d.strong ? 'bg-amber-200 text-amber-900' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200'}">${svg(d.icon, 'size-3.5', 2)}</span>
        <span class="min-w-0 break-words pt-0.5">${obsText(o, 'full')}</span></li>`;
    }).join('')}</ul>`;
  }

  function statusLine(r) {
    const all = [...(r.notes || []), ...(r.warnings || [])];
    const n = all.length;
    const cert = r.cert ? `<span class="text-slate-500">Certificat: <span class="text-slate-700">${r.cert}</span></span>` : '';
    const obsToggle = n ? `<button type="button" class="inline-flex items-center gap-1 rounded-md text-sm font-medium text-amber-800 hover:text-amber-950 underline decoration-amber-300 underline-offset-4" aria-expanded="${r.open ? 'true' : 'false'}" aria-controls="panel-${r.id}" data-toggle="panel-${r.id}">${svg('warn', 'size-3.5', 2)}${n === 1 ? '1 observație' : `${n} observații`}${svg('chevron', 'size-3.5 rot', 2)}</button>` : '';
    switch (r.state) {
      case 'missing':
      case 'dragover':
        return `<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">${pill('slate', 'Nesemnat')}</div>`;
      case 'uploading':
        return `<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">${pill('blue', 'Se încarcă și se verifică semnătura')}<span class="text-sm text-slate-500 font-mono truncate">${r.fileName}</span></div>`;
      case 'matched':
        return `<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">${pill('green', 'Semnătură detectată')}${cert}${obsToggle}</div>`;
      case 'confirmed':
        return `<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">${pill('green', 'Semnătură detectată, document confirmat')}${cert}${obsToggle}</div>`;
      case 'refused':
        return `<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">${pill('red', 'Încărcare refuzată')}<span class="text-sm text-slate-500">${r.prevNote || 'Rândul rămâne nesemnat.'}</span></div>`;
      case 'awaiting': {
        const summary = all.map((o) => obsText(o, 'short')).join(', ');
        return `<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            ${pill('amber', 'Confirmarea documentului este necesară')}
            ${tooltip('Programul de semnare a rescris fișierul, deci documentul a fost recunoscut după text, nu după amprenta fișierului generat.', svg('info', 'size-4 text-slate-400 hover:text-slate-600'), 'De ce este necesară confirmarea')}
          </div>
          <p class="mt-1.5 text-sm text-amber-950"><span class="font-medium">${n} ${n === 1 ? 'observație' : 'observații'}:</span> ${summary}.
            <button type="button" class="ms-1 inline-flex items-center gap-0.5 rounded-md font-semibold text-lex-navy underline underline-offset-4" aria-expanded="${r.open ? 'true' : 'false'}" aria-controls="panel-${r.id}" data-toggle="panel-${r.id}">Detalii${svg('chevron', 'size-3.5 rot', 2)}</button></p>`;
      }
    }
    return '';
  }

  function panel(r, ctx) {
    const all = [...(r.notes || []), ...(r.warnings || [])];
    if (r.state === 'refused') return refusal(r);
    if (!all.length) return '';
    const hidden = r.open ? '' : 'hidden';
    if (r.state === 'awaiting') {
      return `<div id="panel-${r.id}" ${hidden} class="mt-3 rounded-xl border border-amber-200 bg-white">
        <div class="px-4 pt-3.5 pb-3 border-b border-amber-100">
          <p class="text-sm font-semibold text-slate-900">Ce s-a constatat la comparare</p>
          <p class="mt-0.5 text-sm text-slate-600">Textul paginilor generate se regăsește în fișierul semnat. Diferențele de mai jos ajung la instanță așa cum sunt.</p>
        </div>
        <div class="px-4 py-3.5">${obsList(r)}</div>
        <dl class="px-4 py-3 border-t border-amber-100 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt class="text-slate-500">Numele din certificat</dt><dd class="text-slate-900 font-medium">${r.cert}</dd>
          <dt class="text-slate-500">Fișier încărcat</dt><dd class="text-slate-700 font-mono text-[13px] break-all">${r.fileName}</dd>
        </dl>
        ${ctx.uploadsOpen ? `<div class="px-4 py-3.5 border-t border-amber-100 bg-amber-50/60 rounded-b-xl flex flex-col sm:flex-row sm:items-center gap-3">
          <button type="button" class="${btn.primary} !h-auto min-h-9 py-2 !whitespace-normal text-left w-full sm:w-auto">${svg('check', 'size-4 shrink-0', 2.2)}Confirmă că fișierul semnat este documentul generat</button>
          <span class="text-sm text-slate-600">sau se încarcă alt fișier semnat, cu „Înlocuiește”.</span>
        </div>` : ''}
      </div>`;
    }
    return `<div id="panel-${r.id}" ${hidden} class="mt-3 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3.5">${obsList(r)}</div>`;
  }

  function refusal(r) {
    const f = r.refusal;
    if (f.kind === 'over_budget') {
      return `<div role="alert" class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3.5">
        <p class="text-sm font-semibold text-amber-950">Fișierul semnat nu încape în e-mailul lui</p>
        <p class="mt-1 text-sm text-amber-950">Depășește cu aproximativ ${f.excess} MB spațiul rămas și nu a fost păstrat. Anexele se pot reîmpărți în volume mai mici; volumele noi se semnează din nou, iar cererea și împuternicirea își păstrează semnăturile.</p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button type="button" class="${btn.amber}">${svg('refresh')}Reîmparte anexele</button>
          <span class="text-xs text-amber-900/80">Fișier refuzat: <span class="font-mono">${f.fileName}</span></span>
        </div>
      </div>`;
    }
    return `<div role="alert" class="mt-3 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3.5">
      <p class="text-sm font-semibold text-red-900">${f.title}</p>
      <p class="mt-1 text-sm text-red-900">${f.text}</p>
      ${f.fix ? `<p class="mt-2 text-sm text-slate-800"><span class="font-semibold">Cum se rezolvă:</span> ${f.fix}</p>` : ''}
      <p class="mt-2 text-xs text-slate-500">Fișier refuzat: <span class="font-mono">${f.fileName}</span>, ${f.at}</p>
    </div>`;
  }

  function row(r, ctx) {
    const tone = r.state === 'awaiting' ? 'bg-amber-50/50' : r.state === 'refused' ? 'bg-red-50/30' : '';
    const drag = r.state === 'dragover';
    const metaBits = [r.meta];
    if (r.annex) metaBits.push(r.annex);
    const comp = r.compressed ? tooltip('Anexele au fost comprimate local de platformă, ca să încapă în limita e-mailului. Nu se folosesc servicii externe de compresie.', `<span class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600">${svg('layers', 'size-3')}Copie comprimată</span>`) : '';
    return `
    <li id="row-${r.id}" class="relative px-4 sm:px-6 ${r.compact ? 'py-3' : 'py-4'} ${tone}">
      ${drag ? `<div class="pointer-events-none absolute inset-1.5 z-10 rounded-xl border-2 border-dashed border-lex-navy-light bg-blue-50/90 flex items-center justify-center gap-2 text-sm font-semibold text-lex-navy">${svg('upload', 'size-5')}Eliberează fișierul pentru a-l încărca la „${r.title}”</div>` : ''}
      <div class="grid grid-cols-[2.25rem_minmax(0,1fr)] sm:grid-cols-[2.25rem_minmax(0,1fr)_auto] gap-x-4">
        <div class="row-span-3 sm:row-span-3">${statusIcon(r)}</div>
        <div class="min-w-0 self-center">
          <p class="font-medium text-slate-900 leading-snug">${r.title}</p>
          ${r.compact ? '' : `<p class="mt-0.5 text-sm text-slate-500 flex flex-wrap items-center gap-x-2 gap-y-1"><span class="tnum">${metaBits.map((b) => `<span class="whitespace-nowrap">${b}</span>`).join(', ')}</span>${comp}</p>`}
        </div>
        <div class="col-start-2 sm:col-span-2 ${r.compact ? 'mt-1' : 'mt-2'}">${statusLine(r)}</div>
        <div class="col-span-2 sm:col-span-1 sm:col-start-3 sm:row-start-1 mt-3 sm:mt-0 grid grid-cols-2 sm:flex sm:items-start sm:justify-end gap-2">
          ${primaryControl(r, ctx) || '<span class="hidden sm:block sm:w-[11rem]"></span>'}
          ${downloadControl(r)}
        </div>
        <div class="col-span-2 sm:col-start-2 sm:col-span-2">${panel(r, ctx)}</div>
      </div>
    </li>`;
  }

  function card(c) {
    const ctx = { uploadsOpen: c.uploadsOpen !== false };
    const anySig = c.rows.some((r) => r.signed);
    const allDone = c.ready === c.total;
    const countPill = allDone ? pill('green', `${c.ready} din ${c.total} gata`) : `<span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700 tnum">${c.ready} din ${c.total} gata</span>`;
    const rows = c.rows.map((r) => (r.group ? groupRow(r) : row(r, ctx))).join('');
    const footer = [];
    if (anySig) footer.push(`<p class="flex gap-2 text-slate-600">${svg('info', 'size-4 shrink-0 mt-0.5 text-slate-400')}<span>Prezența unei semnături nu garantează validitatea calificată. Verificarea revine avocatului și instanței.</span></p>`);
    if (c.skipped) footer.push(`<p class="flex gap-2 text-amber-900">${svg('warn', 'size-4 shrink-0 mt-0.5', 2)}<span>${c.skipped}</span></p>`);
    const progress = `<div class="mt-3 h-1.5 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true"><div class="h-full rounded-full ${allDone ? 'bg-emerald-500' : 'bg-lex-navy-light'}" style="width:${Math.round((c.ready / c.total) * 100)}%"></div></div>`;

    if (c.collapsed !== undefined) {
      const open = c.collapsed === false;
      return `
      <section id="filing-parts" class="bg-white rounded-2xl border border-slate-200 shadow-soft">
        <div class="px-4 sm:px-6 py-5 flex flex-col sm:flex-row sm:items-center gap-4">
          <span class="shrink-0 inline-flex items-center justify-center size-10 rounded-xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">${svg('check', 'size-5', 2.4)}</span>
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2"><h3 class="text-base font-semibold text-slate-900">Fișiere de semnat</h3>${countPill}</div>
            <p class="mt-0.5 text-sm text-slate-600">Semnături detectate pe toate fișierele. Restul cerințelor se verifică înainte de trimitere.</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="${btn.neutral}" aria-expanded="${open}" aria-controls="parts-list" data-toggle="parts-list">Afișează fișierele (${c.total})${svg('chevron', 'size-3.5 rot', 2)}</button>
            <a href="#" class="${btn.primary}">${svg('send', 'size-4')}Verifică și trimite</a>
          </div>
        </div>
        <div id="parts-list" ${open ? '' : 'hidden'}>
          <div class="px-4 sm:px-6 py-2.5 border-t border-slate-100 flex items-center justify-between gap-3 bg-slate-50/60">
            <span class="text-xs font-medium text-slate-500">Fișierele semnate, gata de trimis</span>
            <a href="#" class="text-sm font-medium text-lex-navy inline-flex items-center gap-1.5 underline underline-offset-4">${svg('download')}Descarcă toate (.zip)</a>
          </div>
          <ul class="divide-y divide-slate-100 border-t border-slate-100">${rows}</ul>
        </div>
        <footer class="px-4 sm:px-6 py-3 border-t border-slate-100 space-y-1.5 text-sm">${footer.join('')}</footer>
      </section>`;
    }

    return `
    <section id="filing-parts" class="bg-white rounded-2xl border border-slate-200 shadow-soft">
      <header class="px-4 sm:px-6 pt-5 pb-4 border-b border-slate-100">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2"><h3 class="text-base font-semibold text-slate-900">Fișiere de semnat</h3>${countPill}</div>
            <p class="mt-1 text-sm text-slate-500 max-w-[34rem]">Se descarcă, se semnează local cu semnătura calificată și se încarcă înapoi, cu butonul rândului sau trăgând fișierul peste rând. PDF, cel mult 20 MB.</p>
          </div>
          <a href="#" class="${btn.neutral} shrink-0 self-start">${svg('download')}Descarcă toate (.zip)</a>
        </div>
        ${progress}
      </header>
      <ul class="divide-y divide-slate-100">${rows}</ul>
      ${footer.length ? `<footer class="px-4 sm:px-6 py-3 border-t border-slate-100 space-y-1.5 text-sm">${footer.join('')}</footer>` : ''}
    </section>`;
  }

  function groupRow(g) {
    return `<li class="px-4 sm:px-6 py-2.5 bg-slate-50/80 flex items-start gap-3 text-sm">
      <span class="shrink-0 inline-flex items-center justify-center size-9 sm:size-auto text-slate-400">${svg('layers', 'size-4')}</span>
      <span class="text-slate-600"><span class="font-medium text-slate-800">${g.title}.</span> ${g.text}</span></li>`;
  }

  function statusPanel(s) {
    return `
    <section class="bg-white rounded-2xl border border-slate-200 shadow-soft p-5 sm:p-6">
      <div class="flex gap-4">
        <span class="hidden sm:inline-flex shrink-0 items-center justify-center size-10 rounded-xl ${s.done ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'}">${svg(s.done ? 'send' : 'pen', 'size-5')}</span>
        <div class="min-w-0">
          <h2 class="text-lg font-semibold text-slate-900">${s.title}</h2>
          <p class="mt-1 text-sm text-slate-600 max-w-prose">${s.text}</p>
          ${s.cta ? `<div class="mt-4"><a href="${s.ctaHref || '#filing-parts'}" class="${btn.primary} h-10 px-4">${s.cta}</a></div>` : ''}
        </div>
      </div>
    </section>`;
  }

  function aside() {
    return `
    <aside class="space-y-6">
      <section class="bg-white rounded-2xl border border-slate-200 shadow-soft p-5">
        <h3 class="text-sm font-semibold">Instanța și adresa de depunere</h3>
        <p class="mt-2 text-sm">Judecătoria Sectorului 3 București</p>
        <p class="mt-3 font-mono text-sm break-all">registratura-test@example.test</p>
        <p class="text-xs text-slate-500">Registratură, verificată de administrator la 22.10.2026</p>
        <a href="#" class="mt-3 inline-block text-sm font-medium text-lex-navy underline underline-offset-2">Adresele instanței</a>
      </section>
      <section class="bg-white rounded-2xl border border-slate-200 shadow-soft">
        <button type="button" class="w-full px-5 py-4 flex items-center justify-between gap-2 text-sm font-semibold text-slate-700" aria-expanded="false" aria-controls="legal-notes" data-toggle="legal-notes">Note juridice${svg('chevron', 'size-4 rot text-slate-400', 2)}</button>
        <ul id="legal-notes" hidden class="px-5 pb-5 text-sm text-slate-600 space-y-2 list-disc ps-9">
          <li>Fișierele sunt nesemnate. Semnătura electronică calificată se aplică de avocat, local, ca ultim pas; un fișier semnat nu se mai modifică.</li>
          <li>Validatorul public al Comisiei Europene (DSS) se folosește doar cu acordul avocatului, deoarece fișierul conține date ale clientului.</li>
          <li>Textul de pe pagina de gardă a anexelor („conform cu originalul”) este provizoriu. Mențiunea este a avocatului semnatar, nu a platformei.</li>
          <li>Comprimarea anexelor se face local. Serviciile externe de compresie pot încălca secretul profesional (art. 11 din Legea nr. 51/1995) și GDPR.</li>
          <li>Răspunderea pentru semnătura aplicată și pentru conținutul depus aparține avocatului.</li>
        </ul>
      </section>
    </aside>`;
  }

  // ---------- Shell ----------
  const navIcon = { Dashboard: 'scale', Dosare: 'doc', Termene: 'clock' };
  function sidebar() {
    const item = (label, active, badge) => `<a href="#" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm ${active ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600 hover:bg-slate-50'}">${svg(navIcon[label] || 'doc', 'size-4 text-slate-400')}<span class="flex-1">${label}</span>${badge ? `<span class="text-[11px] font-semibold rounded-md bg-red-50 text-red-600 px-1.5">${badge}</span>` : ''}</a>`;
    return `<aside class="hidden lg:flex absolute inset-y-0 left-0 w-60 bg-white border-r border-slate-200 flex-col z-30">
      <div class="h-16 flex items-center gap-2 px-5 border-b border-slate-100"><span class="inline-flex items-center justify-center size-8 rounded-lg bg-lex-navy text-white">${svg('scale')}</span><span class="font-bold text-lex-navy text-lg tracking-tight">LexRecovery</span></div>
      <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2"><span class="inline-flex items-center justify-center size-8 rounded-lg bg-blue-600 text-white text-xs font-bold">AG</span><div><div class="text-sm font-semibold">Cabinet Avocat Demo</div><div class="text-[11px] text-slate-500">Pro, 12 din 25 dosare</div></div></div>
      <nav class="p-3 space-y-0.5">${item('Dashboard')}${item('Dosare', true)}${item('Termene', false, '5')}${item('Notificări')}${item('Creditori')}${item('Debitori')}${item('Setări')}</nav>
      <div class="mt-auto px-4 py-3 border-t border-slate-100 flex items-center gap-2"><span class="inline-flex items-center justify-center size-8 rounded-full bg-lex-navy text-white text-xs font-bold">AG</span><div><div class="text-sm font-semibold">Andrei Georgescu</div><div class="text-[11px] text-slate-500">avocat@test.com</div></div></div>
    </aside>`;
  }
  function topbar() {
    return `<header class="h-14 lg:h-16 bg-white/90 backdrop-blur border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 sticky top-0 z-20">
      <div class="flex items-center gap-3 min-w-0">
        <button type="button" class="lg:hidden inline-flex items-center justify-center size-9 rounded-lg border border-slate-200" aria-label="Meniu">${svg('menu', 'size-5')}</button>
        <ol class="flex items-center gap-2 text-sm text-slate-500 min-w-0"><li class="hidden sm:block">Dosare</li><li class="hidden sm:block text-slate-300">/</li><li class="font-mono text-slate-700 truncate">TMP-LIVE-EX1</li></ol>
      </div>
      <a href="#" class="py-2 px-3 rounded-lg bg-lex-navy text-white text-sm font-semibold">+ Dosar nou</a>
    </header>`;
  }
  function tabs() {
    const t = [['Detalii'], ['Documente', '6'], ['Depunere la instanță', null, true], ['Termene', '1'], ['Activitate portal'], ['Audit', '14']];
    return `<nav class="bg-white rounded-2xl border border-slate-200 shadow-soft mb-5 px-2 flex gap-1 overflow-x-auto" aria-label="Secțiunile dosarului">
      ${t.map(([l, c, a]) => `<a href="#" class="relative shrink-0 py-3.5 px-3 sm:px-4 text-sm inline-flex items-center gap-2 ${a ? 'font-semibold text-slate-900' : 'text-slate-500'}" ${a ? 'aria-current="page"' : ''}>${l}${a && PAGE.tabDot ? `<span class="size-2 rounded-full ${PAGE.tabDot}" aria-hidden="true"></span>` : ''}${c ? `<span class="text-[10px] font-semibold rounded-md bg-slate-100 px-1.5 py-0.5">${c}</span>` : ''}${a ? '<span class="absolute bottom-0 left-3 right-3 h-0.5 rounded-t-full bg-lex-navy"></span>' : ''}</a>`).join('')}
    </nav>`;
  }
  function stepper(cur, sub) {
    const labels = ['Pachet', 'Semnare', 'Trimitere', 'Data depunerii'];
    return `<ol class="bg-white rounded-2xl border border-slate-200 shadow-soft px-4 sm:px-6 py-4 mb-6 grid grid-cols-4 gap-2 sm:gap-4" aria-label="Etapele depunerii">
      ${labels.map((l, i) => {
        const n = i + 1; const done = n < cur; const isCur = n === cur;
        const circle = done ? 'bg-emerald-600 text-white' : isCur ? 'bg-lex-navy text-white' : 'border-2 border-slate-200 text-slate-400 bg-white';
        return `<li class="flex flex-col sm:flex-row items-center sm:items-center gap-1.5 sm:gap-3 text-center sm:text-left" ${isCur ? 'aria-current="step"' : ''}>
          <span class="shrink-0 inline-flex items-center justify-center size-7 rounded-full text-xs font-bold ${circle}">${done ? svg('check', 'size-3.5', 3) : n}</span>
          <span class="min-w-0"><span class="block text-xs sm:text-sm ${isCur ? 'font-semibold text-slate-900' : 'font-medium text-slate-600'}">${l}</span><span class="hidden sm:block text-xs text-slate-500 truncate">${sub[i] || ''}</span></span></li>`;
      }).join('')}
    </ol>`;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const p = window.PAGE;
    document.title = p.title;
    document.body.className = "antialiased bg-slate-50 text-slate-900 relative min-h-screen";
    document.body.innerHTML = `
      ${sidebar()}
      <div class="lg:pl-60 min-h-screen">
        ${topbar()}
        <div class="px-4 lg:px-8 py-5 lg:py-6 max-w-[1280px]">
          <section class="bg-white rounded-2xl border border-slate-200 shadow-soft px-5 lg:px-7 py-5 mb-5">
            <div class="text-sm text-slate-500"><span class="font-mono">TMP-LIVE-EX1</span>, ordonanță de plată</div>
            <h1 class="mt-1 text-xl lg:text-2xl font-bold tracking-tight">SC Live Manual Test SRL <span class="text-slate-300 font-medium">vs</span> SC Debitor Live SRL</h1>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500"><span>Judecătoria Sectorului 3 București</span><span class="tnum">Total creanță 15.131,39 RON</span></div>
          </section>
          ${tabs()}
          ${stepper(p.step || 2, p.stepNotes || [])}
          <div class="grid lg:grid-cols-[minmax(0,1fr)_21rem] gap-6 items-start">
            <div class="space-y-6 min-w-0">${p.status ? statusPanel(p.status) : ''}${card(p.card)}</div>
            ${aside()}
          </div>
        </div>
      </div>`;
    wire();
  });

  // Toggles: disclosures, menus, tooltips. Keyboard: Enter/Space via native buttons, Escape closes.
  function wire() {
    document.querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', () => {
      const t = document.getElementById(b.dataset.toggle);
      const open = t.hasAttribute('hidden');
      t.toggleAttribute('hidden', !open);
      document.querySelectorAll(`[data-toggle="${b.dataset.toggle}"]`).forEach((x) => x.setAttribute('aria-expanded', String(open)));
    }));
    document.querySelectorAll('[data-menu]').forEach((m) => {
      const b = m.querySelector('button'); const pop = m.querySelector('[role=menu]');
      const set = (o) => { pop.toggleAttribute('hidden', !o); b.setAttribute('aria-expanded', String(o)); if (o) pop.querySelector('a').focus(); };
      b.addEventListener('click', () => set(pop.hasAttribute('hidden')));
      m.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { set(false); b.focus(); }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          const items = [...pop.querySelectorAll('[role=menuitem]')]; const i = items.indexOf(document.activeElement);
          items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus(); e.preventDefault();
        }
      });
      document.addEventListener('click', (e) => { if (!m.contains(e.target) && !m.hasAttribute('data-force-open')) set(false); });
    });
    document.querySelectorAll('[data-tt]').forEach((t) => {
      t.querySelector('button').addEventListener('click', () => t.toggleAttribute('data-open'));
      t.addEventListener('keydown', (e) => { if (e.key === 'Escape') { t.removeAttribute('data-open'); t.querySelector('button').blur(); } });
    });
  }
})();
