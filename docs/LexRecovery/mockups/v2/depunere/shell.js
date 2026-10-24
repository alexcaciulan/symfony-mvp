// Shared chrome for the "Depunere la instanță" mockups: app sidebar, top bar,
// case hero, tabs and the filing stepper. Each page only writes its own content
// inside <main data-shell>. Static mockup, no application logic.
(function () {
  const style = document.createElement('style');
  style.textContent = `
    .tnum { font-variant-numeric: tabular-nums; }
    details > summary { list-style: none; cursor: pointer; }
    details > summary::-webkit-details-marker { display: none; }
    details[open] > summary .chev { transform: rotate(90deg); }
    .chev { transition: transform .15s ease; }
    :focus-visible { outline: 2px solid #2B4F7C; outline-offset: 2px; border-radius: 6px; }
    @media (prefers-reduced-motion: reduce) { .chev { transition: none; } }
  `;
  document.head.appendChild(style);
  tailwind.config = {
    theme: { extend: {
      colors: { 'lex-navy': '#1E3A5F', 'lex-navy-dark': '#162A45', 'lex-navy-light': '#2B4F7C' },
      fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'] },
      boxShadow: {
        soft: '0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 12px -2px rgba(15, 23, 42, 0.05)',
        card: '0 1px 2px rgba(15, 23, 42, 0.04), 0 8px 24px -4px rgba(15, 23, 42, 0.06)',
      },
    } },
  };

  const icon = {
    home: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-8 9 8M5 10v10h14V10"/>',
    doc: '<path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v13H7zM14 3v5h5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/>',
    bell: '<path stroke-linecap="round" stroke-linejoin="round" d="M6 16V11a6 6 0 1112 0v5l2 2H4zM10 20a2 2 0 004 0"/>',
    flag: '<path stroke-linecap="round" stroke-linejoin="round" d="M5 21V4h11l-2 4 2 4H5"/>',
    user: '<circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21a8 8 0 0116 0"/>',
    card: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
    scale: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 7h14M5 7l-3 7a4 4 0 006 0zM19 7l-3 7a4 4 0 006 0zM8 21h8"/>',
  };
  const svg = (p, cls = 'size-4') => `<svg class="${cls}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">${p}</svg>`;

  function sidebar() {
    const item = (ic, label, active, badge) => `
      <a href="#" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm ${active ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-600 hover:bg-slate-50'}">
        ${svg(icon[ic], 'size-4 text-slate-400')}<span class="flex-1">${label}</span>
        ${badge ? `<span class="text-[11px] font-semibold rounded-md bg-red-50 text-red-600 px-1.5">${badge}</span>` : ''}
      </a>`;
    return `
    <aside class="absolute inset-y-0 left-0 w-60 bg-white border-r border-slate-200 flex flex-col z-30">
      <div class="h-16 flex items-center gap-2 px-5 border-b border-slate-100">
        <span class="inline-flex items-center justify-center size-8 rounded-lg bg-lex-navy text-white">${svg(icon.scale, 'size-4')}</span>
        <span class="font-bold text-lex-navy text-lg tracking-tight">LexRecovery</span>
      </div>
      <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2">
        <span class="inline-flex items-center justify-center size-8 rounded-lg bg-blue-600 text-white text-xs font-bold">AG</span>
        <div><div class="text-sm font-semibold">Cabinet Avocat Demo</div><div class="text-[11px] text-slate-500">Pro, 12 din 25 dosare</div></div>
      </div>
      <nav class="p-3 space-y-0.5">
        ${item('home', 'Dashboard')}${item('doc', 'Dosare', true)}${item('clock', 'Termene', false, '5')}${item('bell', 'Notificări')}
        <div class="pt-4 pb-1 px-3 text-xs font-semibold text-slate-400">Bibliotecă</div>
        ${item('flag', 'Creditori')}${item('user', 'Debitori')}
        <div class="pt-4 pb-1 px-3 text-xs font-semibold text-slate-400">Cabinet</div>
        ${item('card', 'Abonament')}${item('doc', 'Facturi')}${item('gear', 'Setări')}
      </nav>
    </aside>`;
  }

  function topbar(crumbs) {
    return `
    <header class="h-16 bg-white/90 backdrop-blur border-b border-slate-200 flex items-center justify-between px-8 sticky top-0 z-20">
      <ol class="flex items-center gap-2 text-sm text-slate-500">${crumbs}</ol>
      <div class="flex items-center gap-4">
        <span class="relative">${svg(icon.bell, 'size-5 text-slate-500')}<span class="absolute -top-1.5 -right-2 text-[10px] font-bold bg-red-500 text-white rounded-full px-1">3</span></span>
        <span class="text-xs font-semibold text-slate-700">RO <span class="text-slate-300">/</span> <span class="text-slate-400 font-normal">EN</span></span>
        <a href="#" class="py-2 px-3.5 rounded-lg bg-lex-navy text-white text-sm font-semibold">+ Dosar nou</a>
      </div>
    </header>`;
  }

  const tones = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    blue: 'bg-blue-50 text-blue-700 ring-blue-200',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
  };
  const dot = { slate: 'bg-slate-400', blue: 'bg-blue-500', green: 'bg-emerald-500', amber: 'bg-amber-500', red: 'bg-red-500' };

  function hero(d) {
    return `
    <section class="bg-white rounded-2xl border border-slate-200 shadow-soft px-7 py-6 mb-5 flex items-start justify-between gap-6">
      <div>
        <div class="text-sm text-slate-500"><span class="font-mono">TMP-LIVE-EA</span>, ordonanță de plată</div>
        <h1 class="mt-1 text-2xl font-bold tracking-tight">SC Live Manual Test SRL <span class="text-slate-300 font-medium">vs</span> SC Debitor Live SRL</h1>
        <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-slate-500">
          <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ${tones[d.tone || 'slate']}"><span class="size-1.5 rounded-full ${dot[d.tone || 'slate']}"></span>${d.badge}</span>
          <span>Judecătoria Sectorului 3 București</span>
          <span>Total creanță 15.131,39 RON</span>
        </div>
      </div>
      ${d.cta ? `<a href="#depunere-status" class="shrink-0 py-2.5 px-4 rounded-lg ${d.ctaTone === 'secondary' ? 'border border-slate-300 bg-white text-slate-800' : 'bg-lex-navy text-white'} text-sm font-semibold shadow-sm">${d.cta}</a>` : ''}
    </section>`;
  }

  function tabs(active, filingTone) {
    const t = [['detalii', 'Detalii'], ['documente', 'Documente', '6'], ['depunere', 'Depunere la instanță'], ['termene', 'Termene', '1'], ['portal', 'Activitate portal'], ['audit', 'Audit', '14']];
    return `
    <nav class="bg-white rounded-2xl border border-slate-200 shadow-soft mb-6 px-2 flex gap-1" role="tablist">
      ${t.map(([k, l, c]) => `
        <a href="#" class="relative py-3.5 px-4 text-sm inline-flex items-center gap-2 ${k === active ? 'font-semibold text-slate-900' : 'text-slate-500'}">
          ${l}
          ${k === 'depunere' && filingTone ? `<span class="size-2 rounded-full ${dot[filingTone]}" aria-label="necesită atenție"></span>` : ''}
          ${c ? `<span class="text-[10px] font-semibold rounded-md bg-slate-100 px-1.5 py-0.5">${c}</span>` : ''}
          ${k === active ? '<span class="absolute bottom-0 left-3 right-3 h-0.5 rounded-t-full bg-lex-navy"></span>' : ''}
        </a>`).join('')}
    </nav>`;
  }

  // Stepper: steps are Pachet, Semnare, Trimitere, Data depunerii.
  // data-stepper="3" marks the current step; data-stepper-state = ok | warn | error | done.
  function stepper(current, state, notes) {
    const labels = ['Pachet', 'Semnare', 'Trimitere', 'Data depunerii'];
    const sub = (notes || '').split('|');
    return `
    <ol class="bg-white rounded-2xl border border-slate-200 shadow-soft px-6 py-4 mb-6 grid grid-cols-4 gap-4" aria-label="Etapele depunerii">
      ${labels.map((l, i) => {
        const n = i + 1;
        const done = n < current || (n === current && state === 'done');
        const cur = n === current && state !== 'done';
        let circle = 'border-2 border-slate-200 text-slate-400 bg-white';
        let inner = n;
        if (done) { circle = 'bg-emerald-600 text-white'; inner = '<svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>'; }
        if (cur) {
          circle = state === 'error' ? 'bg-red-600 text-white' : state === 'warn' ? 'bg-amber-500 text-white' : 'bg-lex-navy text-white';
          if (state === 'error' || state === 'warn') inner = '!';
        }
        return `
        <li class="flex items-center gap-3 ${n > current ? 'opacity-70' : ''}" ${cur ? 'aria-current="step"' : ''}>
          <span class="shrink-0 inline-flex items-center justify-center size-7 rounded-full text-xs font-bold ${circle}">${inner}</span>
          <span class="min-w-0">
            <span class="block text-sm ${cur ? 'font-semibold text-slate-900' : 'font-medium text-slate-700'}">${l}</span>
            <span class="block text-xs text-slate-500 truncate">${sub[i] || ''}</span>
          </span>
          ${n < 4 ? '<span class="hidden xl:block flex-1 h-px bg-slate-200"></span>' : ''}
        </li>`;
      }).join('')}
    </ol>`;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const main = document.querySelector('main[data-shell]');
    if (!main) return;
    const d = main.dataset;
    const crumbs = d.crumbs || '<li>Dosare</li><li class="text-slate-300">/</li><li class="font-mono text-slate-700">TMP-LIVE-EA</li>';
    const content = main.innerHTML;
    const showCase = d.noCase === undefined;
    document.body.classList.add("relative","min-h-screen"); document.body.innerHTML = `
      ${sidebar()}
      <div class="pl-60 min-h-screen">
        ${topbar(crumbs)}
        <div class="px-8 py-6 max-w-[1280px]">
          ${showCase ? hero({ badge: d.badge, tone: d.tone, cta: d.cta, ctaTone: d.ctaTone }) : ''}
          ${showCase ? tabs(d.tab || 'depunere', d.tabDot) : ''}
          ${d.stepper ? stepper(+d.stepper, d.stepperState, d.stepperNotes) : ''}
          ${content}
        </div>
      </div>`;
  });
})();
