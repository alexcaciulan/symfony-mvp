# Partials — snippet-uri HTML reutilizabile între mockup-uri

Aceste fragmente sunt copiate manual în fiecare ecran (nu există sistem de templating; sunt fișiere HTML statice). Modifică snippet-ul aici și sincronizează manual când iterezi.

---

## `<head>` setup (toate ecranele)

```html
<!DOCTYPE html>
<html lang="ro" class="">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUME ECRAN — LexRecovery Mockup</title>
  <script>
    if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            'lex-navy': '#1E3A5F',
            'lex-navy-dark': '#162A45',
            'lex-blue': '#3B82F6',
          },
          fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }
        }
      }
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
```

---

## Header (ecrane autenticate)

Modifică `data-active="..."` la link-ul corespunzător paginii curente.

```html
<header class="sticky top-0 z-30 h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800">
  <div class="max-w-7xl mx-auto h-full px-4 lg:px-8 flex items-center justify-between">

    <a href="../02-dashboard/populated.html" class="flex items-center gap-2 text-lex-navy dark:text-white font-semibold text-lg tracking-tight">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16l-3-3m3 3l3-3"/></svg>
      LexRecovery
    </a>

    <nav class="hidden md:flex items-center gap-1">
      <a href="../02-dashboard/populated.html" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Dashboard</a>
      <a href="#" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Dosare</a>
      <a href="#" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">Termene</a>
    </nav>

    <div class="flex items-center gap-2">
      <a href="#" class="relative p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Notificări">
        <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        <span class="absolute top-1 right-1 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold text-white bg-red-500 rounded-full">3</span>
      </a>
      <button onclick="toggleTheme()" class="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Toggle dark mode">
        <svg class="w-5 h-5 dark:hidden text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        <svg class="w-5 h-5 hidden dark:block text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
      </button>
      <div class="relative pl-2 ml-1 border-l border-slate-200 dark:border-slate-800">
        <button class="flex items-center gap-2 p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-lex-navy text-white text-sm font-semibold">MI</span>
          <span class="hidden md:inline text-sm font-medium text-slate-700 dark:text-slate-300">Av. Mihai Ionescu</span>
        </button>
      </div>
    </div>
  </div>
</header>
```

---

## Footer

```html
<footer class="border-t border-slate-200 dark:border-slate-800 mt-16">
  <div class="max-w-7xl mx-auto px-4 lg:px-8 py-6 text-xs text-slate-400 flex flex-wrap items-center justify-between gap-2">
    <span>© 2026 LexRecovery — MVP v1.0</span>
    <div class="flex gap-4">
      <a href="#" class="hover:text-slate-600 dark:hover:text-slate-300">Termeni</a>
      <a href="#" class="hover:text-slate-600 dark:hover:text-slate-300">GDPR</a>
      <a href="#" class="hover:text-slate-600 dark:hover:text-slate-300">Suport</a>
    </div>
  </div>
</footer>
```

---

## Dark mode toggle script (sfârșit `<body>`)

```html
<script>
  function toggleTheme() {
    const html = document.documentElement;
    const isDark = html.classList.toggle('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
  }
</script>
```

---

## Status badge component (inline)

```html
<!-- AMIABIL -->
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"><span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span>AMIABIL</span>

<!-- SOMATIE_TRIMISA (în mers) -->
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>SOMAȚIE TRIMISĂ</span>

<!-- DEFINITIVA -->
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>DEFINITIVĂ</span>

<!-- RESPINSA -->
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>RESPINSĂ</span>
```

---

## Date mock constante

| Element | Valoare |
|---|---|
| Avocat | `Av. Mihai Ionescu`, Baroul București, BAR-2018-3421 |
| Email | mihai.ionescu@cabinet-ionescu.ro |
| Cabinet | Cabinet Av. Mihai Ionescu |
| Creditor exemplu | S.C. Tehno Construct S.R.L., CUI RO12345678, IBAN RO49 RNCB 0082 0044 8001 0001 |
| Debitor exemplu | S.C. Beta Solutions S.R.L., CUI RO87654321, ONRC: ACTIV |
| Sumă mică | 5.000 RON |
| Sumă medie | 47.500 RON |
| Sumă mare | 152.000 RON (peste prag tribunal) |
| Dosar exemplu | LR-2026-0142 |
| Data referință | 15.05.2026 |
