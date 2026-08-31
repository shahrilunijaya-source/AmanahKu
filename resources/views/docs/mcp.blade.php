<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Ask Claude about AmanahKu · Guide</title>
{{ Vite::fonts() }}
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
body{margin:0;background:var(--canvas);color:var(--ink);font-family:var(--font-sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:var(--red);text-decoration:none}
a:hover{text-decoration:underline}
code{font-family:var(--font-mono);font-size:.86em;background:var(--hairline-soft);padding:1px 5px;border-radius:4px}
h1,h2,h3{margin:0;letter-spacing:-.4px;font-weight:600}

/* ---- top bar ---- */
.top{position:sticky;top:0;z-index:50;background:rgba(246,246,243,.88);backdrop-filter:blur(10px);border-bottom:1px solid var(--hairline)}
.top-in{max-width:1180px;margin:0 auto;padding:0 26px;height:58px;display:flex;align-items:center;gap:14px}
.brand{display:flex;align-items:center;gap:10px;font-weight:600;color:var(--ink)}
.mark{width:28px;height:28px;border-radius:8px;background:var(--red);color:#fff;display:grid;place-items:center;font-weight:700;font-size:14px}
.pill{font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:3px 8px;border-radius:999px;background:var(--shelf);color:var(--muted);border:1px solid var(--shelf-line)}
.top .spacer{flex:1}
.top a.plain{color:var(--muted);font-size:13.5px;font-weight:500}
.langtog{display:flex;background:var(--shelf);border-radius:8px;padding:3px;gap:2px}
.langtog button{padding:4px 11px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;background:transparent;color:var(--muted);border:0;font-family:inherit}
.langtog button[data-on]{background:#fff;color:var(--ink)}

/* ---- shell ---- */
.shell{max-width:1180px;margin:0 auto;padding:0 26px;display:grid;grid-template-columns:212px 1fr;gap:44px;align-items:start}
nav.side{position:sticky;top:82px;padding:34px 0 60px;font-size:13.5px}
nav.side .grp{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted-soft);font-weight:700;margin:20px 0 7px}
nav.side .grp:first-child{margin-top:0}
nav.side a{display:block;padding:5px 10px;margin-left:-10px;border-radius:7px;color:var(--body);font-weight:500}
nav.side a:hover{background:var(--hairline-soft);text-decoration:none;color:var(--ink)}
nav.side a.on{background:var(--red-tint);color:var(--red-active);font-weight:600}
main{padding:34px 0 100px;min-width:0}

/* ---- hero ---- */
.hero{padding-bottom:8px}
.hero h1{font-size:34px;line-height:1.15;margin-bottom:12px}
.hero .lede{font-size:16.5px;color:var(--body);max-width:640px;margin-bottom:6px}
.cta{display:flex;gap:11px;flex-wrap:wrap;margin:20px 0 10px}
.btn{border:0;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600;padding:12px 20px;border-radius:10px;display:inline-flex;align-items:center;gap:9px;transition:.14s}
.btn-p{background:var(--red);color:#fff;box-shadow:0 1px 2px rgba(214,35,43,.3)}
.btn-p:hover{background:var(--red-active);text-decoration:none}
.btn-s{background:var(--card);color:var(--ink);border:1px solid var(--hairline)}
.btn-s:hover{border-color:var(--muted-soft);text-decoration:none}

/* ---- sections ---- */
section{padding-top:52px;scroll-margin-top:78px}
section > h2{font-size:22px;margin-bottom:6px}
section > .sub{color:var(--muted);margin-bottom:20px;max-width:660px}

/* ---- capability cards ---- */
.caps{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:13px}
.cap{background:var(--card);border:1px solid var(--hairline);border-radius:12px;padding:16px 17px}
.cap h3{font-size:15px;margin-bottom:5px}
.cap p{margin:0;font-size:13.5px;color:var(--body)}

/* ---- steps ---- */
.steps{counter-reset:s;display:grid;gap:14px}
.step{background:var(--card);border:1px solid var(--hairline);border-radius:12px;padding:17px 19px 17px 56px;position:relative}
.step::before{counter-increment:s;content:counter(s);position:absolute;left:17px;top:17px;width:25px;height:25px;border-radius:50%;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:12.5px;font-weight:700}
.step h3{font-size:15px;margin-bottom:4px}
.step p{margin:0 0 10px;font-size:13.5px;color:var(--body)}
.step p:last-child{margin-bottom:0}

/* ---- code ---- */
.codewrap{background:var(--sidebar);border-radius:11px;overflow:hidden;margin:10px 0 0}
pre{margin:0;padding:15px 17px;overflow-x:auto;font-family:var(--font-mono);font-size:12.6px;line-height:1.6;color:#e9e7df;white-space:pre-wrap;word-break:break-all}
.ph{color:#f2a0a4}

/* ---- example questions ---- */
.qs{display:grid;gap:9px}
.q{background:var(--card);border:1px solid var(--hairline);border-radius:10px;padding:12px 15px;font-size:14px;color:var(--ink);display:flex;gap:9px;align-items:flex-start}
.q svg{flex-shrink:0;margin-top:3px;color:var(--muted-soft)}

/* ---- safety list ---- */
.safe{display:grid;gap:11px}
.safeitem{background:#fffaf2;border:1px solid #ecdcc0;border-radius:12px;padding:14px 17px}
.safeitem b{display:block;font-size:14px;color:var(--amber-ink);margin-bottom:3px}
.safeitem p{margin:0;font-size:13.5px;color:var(--body)}

footer{border-top:1px solid var(--hairline);margin-top:64px;padding:26px 0 0;font-size:12.5px;color:var(--muted);display:flex;gap:18px;flex-wrap:wrap}

@media(max-width:900px){
  .shell{grid-template-columns:1fr;gap:0}
  nav.side{display:none}
  .hero h1{font-size:26px}
}
</style>
@include('partials.pwa-head')
</head>
<body>

<div class="top"><div class="top-in">
  <div class="brand"><div class="mark">A</div> AmanahKu</div>
  <span class="pill" x-data x-text="$store.ui.lang==='en' ? 'Guide' : 'Panduan'">Guide</span>
  <div class="spacer"></div>
  <div class="langtog" x-data>
    <button type="button" @click="$store.ui.setLang('en')" :data-on="$store.ui.lang==='en'">EN</button>
    <button type="button" @click="$store.ui.setLang('ms')" :data-on="$store.ui.lang==='ms'">BM</button>
  </div>
  <a class="plain" href="{{ route('app.screen') }}" x-data x-text="$store.ui.lang==='en' ? 'Back to app' : 'Kembali ke app'">Back to app</a>
</div></div>

<div class="shell">
  <nav class="side">
    <div class="grp" x-data x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</div>
    <a href="#what" class="on" x-data x-text="$store.ui.lang==='en' ? 'What this is' : 'Apa ini'">What this is</a>
    <a href="#see" x-data x-text="$store.ui.lang==='en' ? 'What it can see' : 'Apa yang boleh dilihat'">What it can see</a>
    <a href="#need" x-data x-text="$store.ui.lang==='en' ? 'What you need' : 'Apa yang diperlukan'">What you need</a>
    <div class="grp" x-data x-text="$store.ui.lang==='en' ? 'Set up' : 'Persediaan'">Set up</div>
    <a href="#key" x-data x-text="$store.ui.lang==='en' ? 'Getting your key' : 'Dapatkan kunci anda'">Getting your key</a>
    <a href="#connect" x-data x-text="$store.ui.lang==='en' ? 'Connecting' : 'Menyambung'">Connecting</a>
    <a href="#try" x-data x-text="$store.ui.lang==='en' ? 'Try it' : 'Cuba'">Try it</a>
    <div class="grp" x-data x-text="$store.ui.lang==='en' ? 'Changes' : 'Perubahan'">Changes</div>
    <a href="#writes" x-data x-text="$store.ui.lang==='en' ? 'Letting it make changes' : 'Membenarkan ia membuat perubahan'">Letting it make changes</a>
    <a href="#safe" x-data x-text="$store.ui.lang==='en' ? 'Keeping it safe' : 'Kekal selamat'">Keeping it safe</a>
  </nav>

  <main>
    <div class="hero">
      <h1 x-data x-text="$store.ui.lang==='en' ? 'Ask Claude about your AmanahKu account' : 'Tanya Claude tentang akaun AmanahKu anda'">Ask Claude about your AmanahKu account</h1>
      <p class="lede" x-data x-text="$store.ui.lang==='en'
              ? 'Claude Code, running on your own computer, can look things up in AmanahKu for you and even make changes when you let it — instead of you clicking through screens.'
              : 'Claude Code, yang berjalan pada komputer anda sendiri, boleh mencari maklumat dalam AmanahKu untuk anda dan juga membuat perubahan apabila anda membenarkannya — tanpa perlu anda mengklik melalui skrin.'">
        Claude Code, running on your own computer, can look things up in AmanahKu for you and even make changes when you let it — instead of you clicking through screens.
      </p>
      <div class="cta">
        <a class="btn btn-p" href="{{ route('app.screen', 'security') }}" x-data x-text="$store.ui.lang==='en' ? 'Go to Account & security' : 'Ke Akaun & keselamatan'">Go to Account &amp; security</a>
        <a class="btn btn-s" href="https://claude.com/product/claude-code" target="_blank" rel="noopener" x-data x-text="$store.ui.lang==='en' ? 'Get Claude Code' : 'Dapatkan Claude Code'">Get Claude Code</a>
      </div>
    </div>

    <section id="what">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'What this is' : 'Apa ini'">What this is</h2>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'Claude Code is an assistant you run on your own computer. Once it is connected to AmanahKu, you can ask it plain questions — \'what did I log last week\', \'what\'s on my board\' — and it looks the answer up for you, the same way you would by opening the app yourself.'
              : 'Claude Code adalah pembantu yang anda jalankan pada komputer anda sendiri. Setelah disambungkan ke AmanahKu, anda boleh bertanya soalan mudah — \'apa yang saya log minggu lepas\', \'apa yang ada pada board saya\' — dan ia mencari jawapannya untuk anda, sama seperti anda membuka app itu sendiri.'">
        Claude Code is an assistant you run on your own computer. Once it is connected to AmanahKu, you can ask it plain questions — "what did I log last week", "what's on my board" — and it looks the answer up for you, the same way you would by opening the app yourself.
      </p>
    </section>

    <section id="see">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'What it can see' : 'Apa yang boleh dilihat'">What it can see</h2>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'Three things, and one rule that never changes.'
              : 'Tiga perkara, dan satu peraturan yang tidak pernah berubah.'">
        Three things, and one rule that never changes.
      </p>
      <div class="caps">
        <div class="cap">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Timesheets' : 'Lembaran masa'">Timesheets</h3>
          <p x-data x-text="$store.ui.lang==='en' ? 'Your weekly timesheet entries — one week at a time.' : 'Kemasukan lembaran masa mingguan anda — satu minggu pada satu masa.'">Your weekly timesheet entries — one week at a time.</p>
        </div>
        <div class="cap">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Board cards' : 'Kad board'">Board cards</h3>
          <p x-data x-text="$store.ui.lang==='en' ? 'Work items on the board — title, status, priority, due date, who it\'s assigned to.' : 'Item kerja pada board — tajuk, status, keutamaan, tarikh akhir, siapa yang ditugaskan.'">Work items on the board — title, status, priority, due date, who it's assigned to.</p>
        </div>
        <div class="cap">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'TOT sessions' : 'Sesi TOT'">TOT sessions</h3>
          <p x-data x-text="$store.ui.lang==='en' ? 'Transfer of Training sessions — date, topic, presenter, who attended.' : 'Sesi Transfer of Training — tarikh, topik, penyampai, siapa yang hadir.'">Transfer of Training sessions — date, topic, presenter, who attended.</p>
        </div>
      </div>
      <p class="sub" style="margin-top:16px;" x-data x-text="$store.ui.lang==='en'
              ? 'It only ever sees what you can already see in the app when you\'re signed in as yourself. An ordinary staff member sees their own timesheet and their own or unassigned cards. HR and management see the whole company, exactly as they do on screen. TOT sessions are company-wide for everyone, in the app and here.'
              : 'Ia hanya melihat apa yang anda sendiri boleh lihat dalam app apabila anda log masuk sebagai diri anda. Kakitangan biasa melihat lembaran masa sendiri dan kad sendiri atau yang belum ditugaskan. HR dan pengurusan melihat seluruh syarikat, sama seperti pada skrin. Sesi TOT adalah untuk seluruh syarikat bagi semua orang, dalam app dan di sini.'">
        It only ever sees what you can already see in the app when you're signed in as yourself. An ordinary staff member sees their own timesheet and their own or unassigned cards. HR and management see the whole company, exactly as they do on screen. TOT sessions are company-wide for everyone, in the app and here.
      </p>
    </section>

    <section id="need">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'What you need' : 'Apa yang diperlukan'">What you need</h2>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'Claude Code is a program you install on your own computer — it is not something that runs inside your browser. Install it from claude.com/product/claude-code and follow its own setup steps first — the rest of this guide picks up once it\'s installed.'
              : 'Claude Code adalah program yang anda pasang pada komputer anda sendiri — ia bukan sesuatu yang berjalan di dalam pelayar anda. Pasang dari claude.com/product/claude-code dan ikut langkah persediaannya sendiri dahulu — selebihnya panduan ini bermula selepas ia dipasang.'">
        Claude Code is a program you install on your own computer — it is not something that runs inside your browser. Install it from claude.com/product/claude-code and follow its own setup steps first — the rest of this guide picks up once it's installed.
      </p>
      <a href="https://claude.com/product/claude-code" target="_blank" rel="noopener" x-data x-text="$store.ui.lang==='en' ? 'claude.com/product/claude-code →' : 'claude.com/product/claude-code →'">claude.com/product/claude-code →</a>
    </section>

    <section id="key">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'Getting your key' : 'Dapatkan kunci anda'">Getting your key</h2>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'Your key is what lets Claude Code sign in as you. Anyone holding it can see what you can see, so it is shown to you exactly once.'
              : 'Kunci anda membolehkan Claude Code log masuk sebagai anda. Sesiapa yang memegangnya boleh melihat apa yang anda boleh lihat, jadi ia dipaparkan kepada anda hanya sekali sahaja.'">
        Your key is what lets Claude Code sign in as you. Anyone holding it can see what you can see, so it is shown to you exactly once.
      </p>
      <div class="steps">
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Open Account & security' : 'Buka Akaun & keselamatan'">Open Account &amp; security</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'Open the Account & security screen and find the “AI access key” card.'
                  : 'Buka skrin Akaun & keselamatan dan cari kad “Kunci akses AI”.'">
            Open the Account &amp; security screen and find the "AI access key" card.
          </p>
          <a href="{{ route('app.screen', 'security') }}" x-data x-text="$store.ui.lang==='en' ? 'Open Account & security →' : 'Buka Akaun & keselamatan →'">Open Account &amp; security →</a>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Confirm your password and generate' : 'Sahkan password anda dan jana'">Confirm your password and generate</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'Type your password and click Generate key. If you also want it to be able to make changes, tick the checkbox first — you can turn that on or off any time by generating a new key.'
                  : 'Taip password anda dan klik Jana kunci. Jika anda juga mahu ia boleh membuat perubahan, tandakan kotak semak dahulu — anda boleh hidupkan atau matikan bila-bila masa dengan menjana kunci baharu.'">
            Type your password and click Generate key. If you also want it to be able to make changes, tick the checkbox first — you can turn that on or off any time by generating a new key.
          </p>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Copy it immediately' : 'Salin dengan segera'">Copy it immediately</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'The key is shown once, on that page, right after you generate it. Copy it before you navigate away — nobody, including you, can see it again after that. Lost it? Generate a new one; the old one stops working the moment you do.'
                  : 'Kunci itu dipaparkan sekali sahaja, pada halaman itu, sejurus selepas anda menjananya. Salin sebelum anda beralih ke halaman lain — tiada sesiapa, termasuk anda sendiri, boleh melihatnya lagi selepas itu. Hilang? Jana kunci baharu; yang lama akan berhenti berfungsi serta-merta.'">
            The key is shown once, on that page, right after you generate it. Copy it before you navigate away — nobody, including you, can see it again after that. Lost it? Generate a new one; the old one stops working the moment you do.
          </p>
        </div>
      </div>
    </section>

    <section id="connect">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'Connecting' : 'Menyambung'">Connecting</h2>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'That same page also gives you a ready-made command, with your key already filled in. Copy it and paste it into a terminal window (the plain black text window Claude Code opens on your computer) — you don\'t need to understand it, just paste it and press Enter.'
              : 'Halaman yang sama juga memberikan anda arahan sedia dibuat, dengan kunci anda sudah diisi. Salin dan tampalkannya ke dalam tetingkap terminal (tetingkap teks hitam ringkas yang dibuka oleh Claude Code pada komputer anda) — anda tidak perlu memahaminya, cuma tampal dan tekan Enter.'">
        That same page also gives you a ready-made command, with your key already filled in. Copy it and paste it into a terminal window (the plain black text window Claude Code opens on your computer) — you don't need to understand it, just paste it and press Enter.
      </p>
      {{-- Shape only, no Copy button here on purpose: "<your key>" is a placeholder, not
           a real key, and copying it would hand someone a command that fails. The real,
           ready-to-paste command (with an actual key filled in) lives on the key page. --}}
      <div class="codewrap">
        <pre>claude mcp add --transport http amanahku {{ url('/mcp/amanahku') }} --header <span class="ph">"Authorization: Bearer &lt;your key&gt;"</span></pre>
      </div>
      <p class="sub" style="margin-top:12px;" x-data x-text="$store.ui.lang==='en'
              ? 'Pasting this tells Claude Code where AmanahKu is and hands it your key so it can sign in as you. It does not open AmanahKu in a browser or change anything by itself — you talk to Claude Code, and it fetches your data in the background.'
              : 'Menampal ini memberitahu Claude Code di mana AmanahKu berada dan memberikannya kunci anda supaya ia boleh log masuk sebagai anda. Ia tidak membuka AmanahKu dalam pelayar atau mengubah apa-apa dengan sendirinya — anda bercakap dengan Claude Code, dan ia mengambil data anda di latar belakang.'">
        Pasting this tells Claude Code where AmanahKu is and hands it your key so it can sign in as you. It does not open AmanahKu in a browser or change anything by itself — you talk to Claude Code, and it fetches your data in the background.
      </p>
    </section>

    <section id="try">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'Try it' : 'Cuba'">Try it</h2>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'Just type these into Claude Code, in plain English or Malay. Timesheets are answered one week at a time — if you don\'t say which week, it will ask.'
              : 'Cuma taip ini ke dalam Claude Code, dalam Bahasa Inggeris atau Bahasa Malaysia yang mudah. Lembaran masa dijawab satu minggu pada satu masa — jika anda tidak nyatakan minggu yang mana, ia akan bertanya.'">
        Just type these into Claude Code, in plain English or Malay. Timesheets are answered one week at a time — if you don't say which week, it will ask.
      </p>
      <div class="qs">
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“What did I book on my timesheet for the week starting 3 August?”' : '“Apa yang saya log pada lembaran masa untuk minggu bermula 3 Ogos?”'">"What did I book on my timesheet for the week starting 3 August?"</span>
        </div>
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“What’s on my board right now?”' : '“Apa yang ada pada board saya sekarang?”'">"What's on my board right now?"</span>
        </div>
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“Which of my cards are due this week?”' : '“Kad saya yang mana perlu disiapkan minggu ini?”'">"Which of my cards are due this week?"</span>
        </div>
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“When is the next TOT session?”' : '“Bila sesi TOT seterusnya?”'">"When is the next TOT session?"</span>
        </div>
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“What TOT sessions were there last month, and who presented?”' : '“Sesi TOT apa yang berlangsung bulan lepas, dan siapa penyampainya?”'">"What TOT sessions were there last month, and who presented?"</span>
        </div>
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“Draft my timesheet for the week starting 3 August”' : '“Rangka lembaran masa saya untuk minggu bermula 3 Ogos”'">"Draft my timesheet for the week starting 3 August"</span>
        </div>
      </div>
      <p class="sub" style="margin-top:16px;" x-data x-text="$store.ui.lang==='en'
              ? 'Ask this and it will ask what you worked on each day — or read it from wherever you point it, like a doc or a chat log — then show you the full week it has worked out, day by day, for you to approve or correct before anything is saved. This only works if you\'ve also let it make changes (see below).'
              : 'Tanya ini dan ia akan bertanya apa yang anda buat setiap hari — atau membacanya dari mana-mana yang anda tunjukkan, seperti dokumen atau log chat — kemudian menunjukkan minggu penuh yang telah ia susun, hari demi hari, untuk anda luluskan atau betulkan sebelum apa-apa disimpan. Ini hanya berfungsi jika anda juga telah membenarkan ia membuat perubahan (lihat di bawah).'">
        Ask this and it will ask what you worked on each day — or read it from wherever you point it, like a doc or a chat log — then show you the full week it has worked out, day by day, for you to approve or correct before anything is saved. This only works if you've also let it make changes (see below).
      </p>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'What it draws up is an estimate for you to check, not a measurement — it is only as good as what you told it. And even once you approve, it only saves a DRAFT; you still submit your timesheet yourself, same as always.'
              : 'Apa yang ia hasilkan adalah anggaran untuk anda semak, bukan ukuran tepat — ia sebaik apa yang anda beritahu sahaja. Dan walaupun selepas anda meluluskan, ia hanya menyimpan sebagai DRAF; anda masih perlu hantar lembaran masa anda sendiri, seperti biasa.'">
        What it draws up is an estimate for you to check, not a measurement — it is only as good as what you told it. And even once you approve, it only saves a DRAFT; you still submit your timesheet yourself, same as always.
      </p>
    </section>

    <section id="writes">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'Letting it make changes' : 'Membenarkan ia membuat perubahan'">Letting it make changes</h2>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'The checkbox on the AI access key card — \'Also let it make changes\' — is off by default. Tick it and Claude Code can also create, edit, move, and archive your board cards (including setting a card\'s effort type and project), save a draft of your timesheet, and, if you are a manager or HR, assign a task to someone or post an external event.'
              : 'Kotak semak pada kad kunci akses AI — \'Juga benarkan ia membuat perubahan\' — dimatikan secara lalai. Tandakannya dan Claude Code juga boleh mencipta, mengedit, memindah, dan mengarkibkan kad board anda (termasuk menetapkan jenis usaha dan projek sesuatu kad), menyimpan draf lembaran masa anda, dan, jika anda pengurus atau HR, menugaskan tugasan kepada seseorang atau menyiarkan acara luaran.'">
        The checkbox on the AI access key card — "Also let it make changes" — is off by default. Tick it and Claude Code can also create, edit, move, and archive your board cards (including setting a card's effort type and project), save a draft of your timesheet, and, if you are a manager or HR, assign a task to someone or post an external event.
      </p>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'Every one of those changes works the same way, in two steps:'
              : 'Setiap perubahan itu berfungsi dengan cara yang sama, dalam dua langkah:'">
        Every one of those changes works the same way, in two steps:
      </p>
      <div class="steps">
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'It shows you exactly what it will change' : 'Ia menunjukkan tepat apa yang akan diubah'">It shows you exactly what it will change</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'Before anything happens, Claude Code shows you a plain-language summary of the change — for example, moving a card to Done, or saving Monday and Tuesday on your timesheet at 100% each.'
                  : 'Sebelum apa-apa berlaku, Claude Code menunjukkan ringkasan mudah tentang perubahan itu — contohnya, memindahkan kad ke Selesai, atau menyimpan Isnin dan Selasa pada lembaran masa anda pada 100% setiap satu.'">
            Before anything happens, Claude Code shows you a plain-language summary of the change — for example, moving a card to Done, or saving Monday and Tuesday on your timesheet at 100% each.
          </p>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Nothing happens until you say yes' : 'Tiada apa berlaku sehingga anda kata ya'">Nothing happens until you say yes</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'It waits for your approval. Say no, or ask for something different, and nothing is saved. Only once you approve does it actually make the change.'
                  : 'Ia menunggu kelulusan anda. Kata tidak, atau minta sesuatu yang lain, dan tiada apa yang disimpan. Hanya selepas anda meluluskan barulah ia benar-benar membuat perubahan itu.'">
            It waits for your approval. Say no, or ask for something different, and nothing is saved. Only once you approve does it actually make the change.
          </p>
        </div>
      </div>

      <p class="sub" style="margin-top:24px;" x-data x-text="$store.ui.lang==='en'
              ? 'A few real examples — what you type, and exactly what comes back before you say yes.'
              : 'Beberapa contoh sebenar — apa yang anda taip, dan tepat apa yang dipaparkan sebelum anda kata ya.'">
        A few real examples — what you type, and exactly what comes back before you say yes.
      </p>

      <h3 style="margin:22px 0 8px;font-size:15px;" x-data x-text="$store.ui.lang==='en' ? 'A board card' : 'Kad board'">A board card</h3>
      <div class="qs">
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“add a card to my board to draft the Q3 handover notes”' : '“add a card to my board to draft the Q3 handover notes”'">"add a card to my board to draft the Q3 handover notes"</span>
        </div>
      </div>
      <p class="sub" style="margin-top:10px;" x-data x-text="$store.ui.lang==='en'
              ? 'It shows you: “Create a new \'Draft Q3 handover notes\' card on your board, in the todo column.” Say yes, and the card appears.'
              : 'Ia menunjukkan: “Create a new \'Draft Q3 handover notes\' card on your board, in the todo column.” Kata ya, dan kad itu muncul.'">
        It shows you: "Create a new 'Draft Q3 handover notes' card on your board, in the todo column." Say yes, and the card appears.
      </p>

      <h3 style="margin:26px 0 8px;font-size:15px;" x-data x-text="$store.ui.lang==='en' ? 'Drafting a timesheet from a project folder' : 'Merangka lembaran masa dari folder projek'">Drafting a timesheet from a project folder</h3>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'This is the flow Claude Code was really built for. Say you\'re sitting in a project folder on your computer — you ask it to draft the week from what you worked on there.'
              : 'Ini adalah aliran kerja yang menjadi sebab utama Claude Code dibina. Katakan anda berada dalam folder projek pada komputer anda — anda minta ia merangka minggu itu daripada apa yang anda kerjakan di situ.'">
        This is the flow Claude Code was really built for. Say you're sitting in a project folder on your computer — you ask it to draft the week from what you worked on there.
      </p>
      <div class="steps">
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'It reads your board first' : 'Ia membaca board anda dahulu'">It reads your board first</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'It looks at the cards you had in In Progress or In Review that week — the same ones your timesheet screen already suggests — each already carrying its own effort type and project. It matches what you actually worked on in that folder to those cards, drafts those days, then shows you the full week to approve.'
                  : 'Ia melihat kad-kad yang anda ada dalam In Progress atau In Review pada minggu itu — sama seperti yang skrin lembaran masa anda sudah cadangkan — setiap satu sudah membawa jenis usaha dan projeknya sendiri. Ia memadankan apa yang anda benar-benar kerjakan dalam folder itu dengan kad-kad tersebut, merangka hari-hari itu, kemudian menunjukkan minggu penuh untuk anda luluskan.'">
            It looks at the cards you had in In Progress or In Review that week — the same ones your timesheet screen already suggests — each already carrying its own effort type and project. It matches what you actually worked on in that folder to those cards, drafts those days, then shows you the full week to approve.
          </p>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Do it again from another folder, same week' : 'Buat lagi dari folder lain, minggu yang sama'">Do it again from another folder, same week</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'If you split your week across projects, you do the same thing standing in the other project\'s folder for the other days — it picks up whichever cards match that folder.'
                  : 'Jika minggu anda terbahagi antara projek, buat perkara yang sama semasa berada dalam folder projek lain untuk hari-hari yang selebihnya — ia mengambil kad-kad yang sepadan dengan folder itu.'">
            If you split your week across projects, you do the same thing standing in the other project's folder for the other days — it picks up whichever cards match that folder.
          </p>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'A day already logged is added to, not overwritten' : 'Hari yang sudah dilog akan ditambah, bukan ditimpa'">A day already logged is added to, not overwritten</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'If a day already has something stored from the other folder, this doesn\'t erase it. The preview marks each line as \'already stored\' or \'added by this change\', so a Tuesday split half-and-half between two cards on the same project shows both halves — two different cards on the same project on the same day both stay.'
                  : 'Jika sesuatu hari sudah menyimpan sesuatu daripada folder lain, ini tidak memadamkannya. Pratonton menandakan setiap baris sebagai \'already stored\' atau \'added by this change\', jadi Selasa yang terbahagi separuh-separuh antara dua kad pada projek yang sama menunjukkan kedua-dua bahagian — dua kad berbeza pada projek yang sama pada hari yang sama kekal kedua-duanya.'">
            If a day already has something stored from the other folder, this doesn't erase it. The preview marks each line as "already stored" or "added by this change", so a Tuesday split half-and-half between two cards on the same project shows both halves — two different cards on the same project on the same day both stay.
          </p>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'A day can\'t go over 100%' : 'Satu hari tidak boleh melebihi 100%'">A day can't go over 100%</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'A full day is 100% — the first Saturday of the month is a half day, full at 50%. Try to push a day over its capacity and it refuses, plainly: “Tue, 18 Aug already has 100% stored; adding 25% would bring it to 125%, over the 100% capacity for that day.”'
                  : 'Satu hari penuh ialah 100% — Sabtu pertama setiap bulan adalah setengah hari, penuh pada 50%. Cuba tolak satu hari melebihi kapasitinya dan ia menolak, secara jelas: “Tue, 18 Aug already has 100% stored; adding 25% would bring it to 125%, over the 100% capacity for that day.”'">
            A full day is 100% — the first Saturday of the month is a half day, full at 50%. Try to push a day over its capacity and it refuses, plainly: "Tue, 18 Aug already has 100% stored; adding 25% would bring it to 125%, over the 100% capacity for that day."
          </p>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'Got it wrong? Just say so' : 'Tersilap? Cakap sahaja'">Got it wrong? Just say so</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'To take something back off a day, tell it — for example \'actually Tuesday was all Amanahku\' — and it replaces that day instead of adding to it.'
                  : 'Untuk menarik balik sesuatu daripada satu hari, beritahu ia — contohnya \'actually Tuesday was all Amanahku\' — dan ia menggantikan hari itu, bukan menambah kepadanya.'">
            To take something back off a day, tell it — for example "actually Tuesday was all Amanahku" — and it replaces that day instead of adding to it.
          </p>
        </div>
        <div class="step">
          <h3 x-data x-text="$store.ui.lang==='en' ? 'A card with no effort type set gets refused' : 'Kad tanpa jenis usaha ditetapkan akan ditolak'">A card with no effort type set gets refused</h3>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'If a card hasn\'t been told what kind of work it is yet, Claude won\'t guess. It stops and tells you to set that on the card in the board first, then try again — same if the card\'s effort type needs a project and the card doesn\'t have one.'
                  : 'Jika sesuatu kad belum ditetapkan jenis kerjanya, Claude tidak akan meneka. Ia berhenti dan memberitahu anda untuk menetapkannya pada kad di board dahulu, kemudian cuba semula — sama juga jika jenis usaha kad itu memerlukan projek tetapi kad itu tiada projek.'">
            If a card hasn't been told what kind of work it is yet, Claude won't guess. It stops and tells you to set that on the card in the board first, then try again — same if the card's effort type needs a project and the card doesn't have one.
          </p>
        </div>
      </div>
      <p class="sub" style="margin-top:14px;" x-data x-text="$store.ui.lang==='en'
              ? 'A card you didn\'t actually work on that day still shows up on the timesheet screen as a suggestion — striking it off is something you do yourself there, on purpose, since it also takes that work out of the cost report.'
              : 'Kad yang anda tidak kerjakan pada hari itu masih akan muncul pada skrin lembaran masa sebagai cadangan — membuangnya adalah sesuatu yang anda lakukan sendiri di situ, secara sengaja, kerana ia turut mengeluarkan kerja itu daripada laporan kos.'">
        A card you didn't actually work on that day still shows up on the timesheet screen as a suggestion — striking it off is something you do yourself there, on purpose, since it also takes that work out of the cost report.
      </p>
      <p class="sub" style="margin-top:14px;" x-data x-text="$store.ui.lang==='en'
              ? 'As always: what it drafts is an estimate to check, not a measurement, and it only ever saves a DRAFT — submitting your timesheet stays something you do yourself.'
              : 'Seperti biasa: apa yang ia rangka adalah anggaran untuk disemak, bukan ukuran tepat, dan ia hanya menyimpan sebagai DRAF — menghantar lembaran masa anda kekal sebagai sesuatu yang anda buat sendiri.'">
        As always: what it drafts is an estimate to check, not a measurement, and it only ever saves a DRAFT — submitting your timesheet stays something you do yourself.
      </p>

      <h3 style="margin:26px 0 8px;font-size:15px;" x-data x-text="$store.ui.lang==='en' ? 'Assigning a task (managers and HR)' : 'Menugaskan tugasan (pengurus dan HR)'">Assigning a task (managers and HR)</h3>
      <div class="qs">
        <div class="q">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 2-3 4M12 17h.01"/></svg>
          <span x-data x-text="$store.ui.lang==='en' ? '“assign the Q3 review to Huraidi, due next Friday”' : '“assign the Q3 review to Huraidi, due next Friday”'">"assign the Q3 review to Huraidi, due next Friday"</span>
        </div>
      </div>
      <p class="sub" style="margin-top:10px;" x-data x-text="$store.ui.lang==='en'
              ? 'It shows you: “Assign \'Review the Q3 numbers\' to Huraidi Haidar Bin Adnan, due 2026-09-04. This WILL email and notify Huraidi Haidar Bin Adnan.” The preview names exactly who gets emailed, before you agree to anything — so you never send a notification by accident.'
              : 'Ia menunjukkan: “Assign \'Review the Q3 numbers\' to Huraidi Haidar Bin Adnan, due 2026-09-04. This WILL email and notify Huraidi Haidar Bin Adnan.” Pratonton menyebut dengan tepat siapa yang akan menerima e-mel, sebelum anda bersetuju dengan apa-apa — supaya anda tidak pernah menghantar notifikasi secara tidak sengaja.'">
        It shows you: "Assign 'Review the Q3 numbers' to Huraidi Haidar Bin Adnan, due 2026-09-04. This WILL email and notify Huraidi Haidar Bin Adnan." The preview names exactly who gets emailed, before you agree to anything — so you never send a notification by accident.
      </p>

      <h3 style="margin:26px 0 8px;font-size:15px;" x-data x-text="$store.ui.lang==='en' ? 'An external event from a pasted invite (managers and HR)' : 'Acara luaran daripada jemputan yang ditampal (pengurus dan HR)'">An external event from a pasted invite (managers and HR)</h3>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'Paste an invite message and ask it to post the event. It fills in the title, host, date, time, venue and registration link for you, and posts it on the Events screen marked as External — there is no separate External TOT screen any more. It always needs the host (the outside organisation running it), because that is what marks the event as an outside one rather than one of ours.'
              : 'Tampal mesej jemputan dan minta ia menyiarkan acara itu. Ia mengisi tajuk, hos, tarikh, masa, tempat dan pautan pendaftaran untuk anda, dan menyiarkannya pada skrin Acara dengan tanda Luaran — tiada lagi skrin TOT Luaran yang berasingan. Ia sentiasa memerlukan hos (organisasi luar yang menganjurkannya), kerana itulah yang menandakan acara itu acara luar, bukan acara kita sendiri.'">
        Paste an invite message and ask it to post the event. It fills in the title, host, date, time, venue and registration link for you, and posts it on the Events screen marked as External — there is no separate External TOT screen any more. It always needs the host (the outside organisation running it), because that is what marks the event as an outside one rather than one of ours.
      </p>
      <p class="sub" x-data x-text="$store.ui.lang==='en'
              ? 'It shows you: “Post the external event \'MDEC AI Governance Briefing\' on 2026-09-10. No employees will be tagged or notified.” Tagging someone sends them a \'you\'re required to attend\' email, so this tool never tags anyone by itself — you add tags yourself in the app, where you can see exactly who they are.'
              : 'Ia menunjukkan: “Post the external event \'MDEC AI Governance Briefing\' on 2026-09-10. No employees will be tagged or notified.” Menandakan seseorang menghantar e-mel \'anda diperlukan hadir\' kepada mereka, jadi alat ini tidak pernah menandakan sesiapa dengan sendirinya — anda tambah tag sendiri dalam app, di mana anda boleh lihat dengan tepat siapa mereka.'">
        It shows you: "Post the external event 'MDEC AI Governance Briefing' on 2026-09-10. No employees will be tagged or notified." Tagging someone sends them a "you're required to attend" email, so this tool never tags anyone by itself — you add tags yourself in the app, where you can see exactly who they are.
      </p>

      <p class="sub" style="margin-top:22px;" x-data x-text="$store.ui.lang==='en'
              ? 'Changed your mind? Say no, or just don\'t reply — nothing was saved, so there is nothing to clean up.'
              : 'Berubah fikiran? Kata tidak, atau cuma jangan balas — tiada apa yang disimpan, jadi tiada apa untuk dibersihkan.'">
        Changed your mind? Say no, or just don't reply — nothing was saved, so there is nothing to clean up.
      </p>
    </section>

    <section id="safe">
      <h2 x-data x-text="$store.ui.lang==='en' ? 'Keeping it safe' : 'Kekal selamat'">Keeping it safe</h2>
      <div class="safe">
        <div class="safeitem">
          <b x-data x-text="$store.ui.lang==='en' ? 'Treat it like a password' : 'Layan seperti password'">Treat it like a password</b>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'Never share your key, paste it in a chat, or commit it into any code or document. Anyone who has it can see everything you can see in AmanahKu.'
                  : 'Jangan sekali-kali kongsi kunci anda, tampalkannya dalam chat, atau commit ke dalam mana-mana kod atau dokumen. Sesiapa yang memilikinya boleh melihat segala yang anda boleh lihat dalam AmanahKu.'">
            Never share your key, paste it in a chat, or commit it into any code or document. Anyone who has it can see everything you can see in AmanahKu.
          </p>
        </div>
        <div class="safeitem">
          <b x-data x-text="$store.ui.lang==='en' ? 'Lost your laptop? Revoke it' : 'Hilang laptop? Batalkan kunci'">Lost your laptop? Revoke it</b>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'Go back to Account & security and click Revoke key. That switches it off immediately — Claude Code on that machine can no longer reach AmanahKu.'
                  : 'Kembali ke Akaun & keselamatan dan klik Batalkan kunci. Itu akan mematikannya serta-merta — Claude Code pada mesin itu tidak lagi boleh mencapai AmanahKu.'">
            Go back to Account &amp; security and click Revoke key. That switches it off immediately — Claude Code on that machine can no longer reach AmanahKu.
          </p>
        </div>
        <div class="safeitem">
          <b x-data x-text="$store.ui.lang==='en' ? 'Leave the safety pause turned on' : 'Biarkan jeda keselamatan dihidupkan'">Leave the safety pause turned on</b>
          <p x-data x-text="$store.ui.lang==='en'
                  ? 'The \'show me first, then ask\' step above only works while Claude Code is set to ask permission before doing things. If it is set to a mode that skips asking (sometimes called auto-approve or \'yolo\' mode), it will make changes on its own without showing you first — leave that switched off when working with AmanahKu.'
                  : 'Langkah \'tunjuk dahulu, kemudian tanya\' di atas hanya berfungsi selagi Claude Code ditetapkan untuk meminta kebenaran sebelum membuat sesuatu. Jika ia ditetapkan pada mod yang melangkau permintaan itu (kadangkala dipanggil auto-approve atau mod \'yolo\'), ia akan membuat perubahan dengan sendirinya tanpa menunjukkan kepada anda dahulu — pastikan itu dimatikan semasa bekerja dengan AmanahKu.'">
            The "show me first, then ask" step above only works while Claude Code is set to ask permission before doing things. If it is set to a mode that skips asking (sometimes called auto-approve or "yolo" mode), it will make changes on its own without showing you first — leave that switched off when working with AmanahKu.
          </p>
        </div>
      </div>
    </section>

    <footer>
      <span x-data x-text="$store.ui.lang==='en' ? 'AmanahKu · staff guide' : 'AmanahKu · panduan kakitangan'">AmanahKu · staff guide</span>
      <a href="{{ route('app.screen', 'security') }}" x-data x-text="$store.ui.lang==='en' ? 'Account & security' : 'Akaun & keselamatan'">Account &amp; security</a>
    </footer>
  </main>
</div>

<script>
    // Same bootstrap as layouts/wizard.blade.php: this is a standalone page (not part
    // of the app shell), so it registers its own copy of the shared lang store rather
    // than pulling in the whole SPA layout for one guide page.
    (function () {
        var l = localStorage.getItem('amanahku-lang') || 'en';
        document.cookie = 'amanahku-lang=' + l + ';path=/;max-age=31536000;samesite=lax';
    })();
    document.addEventListener('alpine:init', () => {
        Alpine.store('ui', {
            lang: localStorage.getItem('amanahku-lang') || 'en',
            setLang(l) {
                this.lang = l;
                localStorage.setItem('amanahku-lang', l);
                document.cookie = 'amanahku-lang=' + l + ';path=/;max-age=31536000;samesite=lax';
            },
        });
    });

    document.querySelectorAll('nav.side a').forEach(a=>a.addEventListener('click',()=>{
      document.querySelectorAll('nav.side a').forEach(x=>x.classList.remove('on'));a.classList.add('on');
    }));
</script>
</body>
</html>
