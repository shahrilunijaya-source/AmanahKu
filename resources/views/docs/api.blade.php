<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AmanahKu API · Developer reference</title>
{{ Vite::fonts() }}
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
body{margin:0;background:var(--canvas);color:var(--ink);font-family:var(--font-sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:var(--red);text-decoration:none}
a:hover{text-decoration:underline}
code{font-family:var(--font-mono);font-size:.86em}
h1,h2,h3{margin:0;letter-spacing:-.4px;font-weight:600}

/* ---- top bar ---- */
.top{position:sticky;top:0;z-index:50;background:rgba(246,246,243,.88);backdrop-filter:blur(10px);border-bottom:1px solid var(--hairline)}
.top-in{max-width:1180px;margin:0 auto;padding:0 26px;height:58px;display:flex;align-items:center;gap:14px}
.brand{display:flex;align-items:center;gap:10px;font-weight:600;color:var(--ink)}
.mark{width:28px;height:28px;border-radius:8px;background:var(--red);color:#fff;display:grid;place-items:center;font-weight:700;font-size:14px}
.pill{font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:3px 8px;border-radius:999px;background:var(--shelf);color:var(--muted);border:1px solid var(--shelf-line)}
.top .spacer{flex:1}
.top a.plain{color:var(--muted);font-size:13.5px;font-weight:500}

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
.hero h1{font-size:38px;line-height:1.12;margin-bottom:12px}
.hero .lede{font-size:17px;color:var(--body);max-width:640px;margin-bottom:22px}
.baseurl{display:inline-flex;align-items:center;gap:9px;background:var(--sidebar);color:#e9e7df;font-family:var(--font-mono);font-size:13px;padding:9px 13px;border-radius:9px;margin-bottom:24px}
.baseurl .g{color:var(--sidebar-dim)}
.cta{display:flex;gap:11px;flex-wrap:wrap;margin-bottom:10px}
.btn{border:0;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600;padding:12px 20px;border-radius:10px;display:inline-flex;align-items:center;gap:9px;transition:.14s}
.btn-p{background:var(--red);color:#fff;box-shadow:0 1px 2px rgba(214,35,43,.3)}
.btn-p:hover{background:var(--red-active)}
.btn-s{background:var(--card);color:var(--ink);border:1px solid var(--hairline)}
.btn-s:hover{border-color:var(--muted-soft)}
.btn.ok{background:var(--success)!important;color:#fff!important;border-color:var(--success)!important}
.hint{font-size:12.5px;color:var(--muted);margin-top:2px}

/* ---- sections ---- */
section{padding-top:52px;scroll-margin-top:78px}
section > h2{font-size:23px;margin-bottom:6px}
section > .sub{color:var(--muted);margin-bottom:20px;max-width:660px}

/* ---- capability cards ---- */
.caps{display:grid;grid-template-columns:repeat(auto-fill,minmax(268px,1fr));gap:13px}
.cap{background:var(--card);border:1px solid var(--hairline);border-radius:12px;padding:16px 17px}
.cap .m{font-family:var(--font-mono);font-size:12px;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:7px}
.verb{font-size:10px;font-weight:700;letter-spacing:.4px;background:var(--shelf);color:var(--muted);padding:2px 6px;border-radius:5px}
.cap h3{font-size:15px;margin-bottom:5px}
.cap p{margin:0;font-size:13.5px;color:var(--body)}
.scope{display:inline-block;font-family:var(--font-mono);font-size:11px;background:var(--red-tint);color:var(--red-active);border:1px solid #f3c6c8;padding:2px 8px;border-radius:999px;margin-top:11px}

/* ---- "not" list ---- */
.nots{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:11px}
.not{background:var(--card);border:1px solid var(--hairline);border-left:3px solid var(--amber);border-radius:10px;padding:13px 15px}
.not b{display:block;font-size:13.5px;margin-bottom:2px}
.not span{font-size:13px;color:var(--body)}

/* ---- steps ---- */
.steps{counter-reset:s;display:grid;gap:14px}
.step{background:var(--card);border:1px solid var(--hairline);border-radius:12px;padding:17px 19px 17px 56px;position:relative}
.step::before{counter-increment:s;content:counter(s);position:absolute;left:17px;top:17px;width:25px;height:25px;border-radius:50%;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:12.5px;font-weight:700}
.step h3{font-size:15px;margin-bottom:4px}
.step p{margin:0 0 10px;font-size:13.5px;color:var(--body)}
.step p:last-child{margin-bottom:0}

/* ---- code ---- */
.codewrap{background:var(--sidebar);border-radius:11px;overflow:hidden;margin:0}
.tabs{display:flex;gap:2px;padding:7px 8px 0;border-bottom:1px solid var(--sidebar-line);overflow-x:auto}
.tab{background:transparent;border:0;color:var(--sidebar-dim);font-family:inherit;font-size:12.5px;font-weight:600;padding:7px 13px;border-radius:7px 7px 0 0;cursor:pointer;white-space:nowrap}
.tab:hover{color:#fff}
.tab.on{background:var(--sidebar-soft,#2b2a25);color:#fff}
pre{margin:0;padding:17px 19px;overflow-x:auto;font-family:var(--font-mono);font-size:12.6px;line-height:1.66;color:#e9e7df}
.k{color:#f2a0a4}.s{color:#a8d8b9}.c{color:#7e7b70;font-style:italic}.n{color:#e3c07b}.f{color:#8fc7e8}
.copysm{position:absolute;top:9px;right:10px;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.14);color:#d8d5cc;font-family:inherit;font-size:11.5px;font-weight:600;padding:5px 10px;border-radius:7px;cursor:pointer}
.copysm:hover{background:rgba(255,255,255,.16)}
.rel{position:relative}

/* ---- endpoint reference ---- */
.ep{background:var(--card);border:1px solid var(--hairline);border-radius:11px;margin-bottom:9px;overflow:hidden}
.ep summary{cursor:pointer;list-style:none;padding:14px 17px;display:flex;align-items:center;gap:11px;font-family:var(--font-mono);font-size:13px}
.ep summary::-webkit-details-marker{display:none}
.ep summary .arrow{color:var(--muted-soft);font-size:11px;transition:.15s}
.ep[open] summary .arrow{transform:rotate(90deg)}
.ep summary .path{font-weight:600;color:var(--ink)}
.ep summary .tail{margin-left:auto;display:flex;align-items:center;gap:8px}
.ep .body{padding:0 17px 17px;border-top:1px solid var(--hairline-soft)}
.ep .body p{font-size:13.5px;color:var(--body);margin:13px 0 11px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:13px}
th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted-soft);padding:7px 9px;background:var(--hairline-soft)}
td{padding:8px 9px;border-top:1px solid var(--hairline-soft);vertical-align:top}
td code{color:var(--ink);background:var(--hairline-soft);padding:1px 5px;border-radius:4px}
.t{color:var(--muted);font-family:var(--font-mono);font-size:11.5px}

/* ---- gotchas ---- */
.got{background:#fffaf2;border:1px solid #ecdcc0;border-radius:12px;padding:17px 19px;margin-bottom:12px}
.got h3{font-size:14.5px;color:var(--amber-ink);margin-bottom:5px;display:flex;align-items:center;gap:8px}
.got p{margin:0 0 9px;font-size:13.5px;color:var(--body)}
.got p:last-child{margin:0}
.got pre{background:var(--sidebar);border-radius:8px;font-size:12.2px;padding:12px 14px;margin:9px 0 0}

/* ---- toast ---- */
#toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,22px);background:var(--ink);color:#fff;padding:11px 19px;border-radius:10px;font-size:13.5px;font-weight:500;opacity:0;pointer-events:none;transition:.22s;z-index:99;box-shadow:0 6px 22px rgba(0,0,0,.22)}
#toast.on{opacity:1;transform:translate(-50%,0)}

footer{border-top:1px solid var(--hairline);margin-top:64px;padding:26px 0 0;font-size:12.5px;color:var(--muted);display:flex;gap:18px;flex-wrap:wrap}

@media(max-width:900px){
  .shell{grid-template-columns:1fr;gap:0}
  nav.side{display:none}
  .hero h1{font-size:29px}
}
</style>
@include('partials.pwa-head')
</head>
<body>

<div class="top"><div class="top-in">
  <div class="brand"><div class="mark">A</div> AmanahKu</div>
  <span class="pill">API v1</span>
  <div class="spacer"></div>
  <a class="plain" href="/openapi.json">openapi.json</a>
  <a class="plain" href="/login">Sign in</a>
</div></div>

<div class="shell">
  <nav class="side">
    <div class="grp">Start</div>
    <a href="#what" class="on">What this is</a>
    <a href="#quickstart">Quick start</a>
    <a href="#samples">Sample code</a>
    <div class="grp">Reference</div>
    <a href="#capabilities">What it can do</a>
    <a href="#limits">What it won't do</a>
    <a href="#endpoints">Endpoints</a>
    <a href="#errors">Errors</a>
    <div class="grp">Careful</div>
    <a href="#gotchas">Known traps</a>
  </nav>

  <main>
    <div class="hero">
      <h1>AmanahKu API</h1>
      <p class="lede">The source of truth for which projects exist across Unijaya, and what kind of work each one is. Read-only, one key per application, six endpoints.</p>
      <div class="baseurl"><span class="g">BASE</span> {{ $baseUrl }}</div>
      <div class="cta">
        <button class="btn btn-p" onclick="copyAgent(this)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M18 6l-2.5 2.5M8.5 15.5L6 18"/></svg>
          Copy everything for your AI
        </button>
        <button class="btn btn-s" onclick="copyText(OPENAPI_URL_TEXT,this,'OpenAPI URL copied')">Copy OpenAPI JSON</button>
        <button class="btn btn-s" onclick="copyText(CURL_TEXT,this,'curl copied')">Copy a test call</button>
      </div>
      <p class="hint">The first button copies this whole page as one instruction block, written for a coding agent. Paste it into Claude Code, Cursor or ChatGPT and ask it to build your client.</p>
    </div>

    <section id="what">
      <h2>What this is</h2>
      <p class="sub">AmanahKu answers <b>which projects exist and what kind they are</b>. It does not answer how they are going. Budget, phases and delivery cadence live in Track, deliberately, so there is never a second copy to keep in sync.</p>
    </section>

    <section id="quickstart">
      <h2>Quick start</h2>
      <p class="sub">Three steps, about five minutes.</p>
      <div class="steps">
        <div class="step">
          <h3>Get a key for your app</h3>
          <p>Ask a super-admin to issue one at <code>/admin/companies/{company}/api-keys</code>. They tick exactly the data your app needs. The key is shown once and cannot be recovered, so store it where your app keeps its secrets.</p>
        </div>
        <div class="step">
          <h3>Send it on every request</h3>
          <p>One header. Nothing else to set up, no token exchange, no refresh.</p>
          <div class="codewrap rel"><button class="copysm" onclick="copyPre(this)">Copy</button><pre><span class="k">Authorization</span>: Bearer &lt;key&gt;</pre></div>
        </div>
        <div class="step">
          <h3>Read the envelope</h3>
          <p>Every successful response is <code>{"data": …, "error": null}</code>. Check <code>error</code> first, then use <code>data</code>.</p>
        </div>
      </div>
    </section>

    <section id="samples">
      <h2>Sample code</h2>
      <p class="sub">Listing this company's projects, in four flavours. Swap the endpoint for any of the six.</p>
      <div class="codewrap">
        <div class="tabs">
          <button class="tab on" onclick="tab(this,'curl')">cURL</button>
          <button class="tab" onclick="tab(this,'php')">PHP (Laravel)</button>
          <button class="tab" onclick="tab(this,'js')">JavaScript</button>
          <button class="tab" onclick="tab(this,'py')">Python</button>
        </div>
        <div class="rel">
          <button class="copysm" onclick="copyPre(this)">Copy</button>
<pre id="c-curl"><span class="c"># List every active project and its category tags</span>
curl -s <span class="s">"{{ $baseUrl }}/projects"</span> \
  -H <span class="s">"Authorization: Bearer $AMANAHKU_KEY"</span>

<span class="c"># One week of effort. week_start MUST be a Monday.</span>
curl -s <span class="s">"{{ $baseUrl }}/timesheet-effort?week_start=2026-08-03"</span> \
  -H <span class="s">"Authorization: Bearer $AMANAHKU_KEY"</span></pre>

<pre id="c-php" hidden><span class="k">use</span> Illuminate\Support\Facades\<span class="n">Http</span>;

<span class="k">$response</span> = <span class="n">Http</span>::withToken(config(<span class="s">'services.amanahku.key'</span>))
    -&gt;acceptJson()
    -&gt;get(<span class="s">'{{ $baseUrl }}/projects'</span>);

<span class="k">if</span> (<span class="k">$response</span>-&gt;failed()) {
    <span class="c">// 401 = key rejected. 403 = key lacks this scope.</span>
    <span class="k">throw new</span> \RuntimeException(<span class="k">$response</span>-&gt;json(<span class="s">'error'</span>) ?? <span class="s">'AmanahKu unreachable'</span>);
}

<span class="k">foreach</span> (<span class="k">$response</span>-&gt;json(<span class="s">'data'</span>) <span class="k">as</span> <span class="k">$project</span>) {
    <span class="c">// $project['categories'] is e.g. ['Development', 'Maintenance']</span>
    Project::updateOrCreate(
        [<span class="s">'amanahku_project_id'</span> =&gt; <span class="k">$project</span>[<span class="s">'id'</span>]],
        [<span class="s">'name'</span> =&gt; <span class="k">$project</span>[<span class="s">'name'</span>], <span class="s">'kind'</span> =&gt; <span class="k">$project</span>[<span class="s">'categories'</span>][<span class="n">0</span>] ?? <span class="k">null</span>],
    );
}</pre>

<pre id="c-js" hidden><span class="k">const</span> res = <span class="k">await</span> <span class="f">fetch</span>(<span class="s">'{{ $baseUrl }}/projects'</span>, {
  headers: { <span class="n">Authorization</span>: <span class="s">`Bearer ${process.env.AMANAHKU_KEY}`</span> },
});

<span class="k">const</span> { data, error } = <span class="k">await</span> res.<span class="f">json</span>();

<span class="k">if</span> (error) <span class="k">throw new</span> <span class="n">Error</span>(error);

<span class="c">// data: [{ id, code, name, categories: string[] }]</span>
<span class="k">for</span> (<span class="k">const</span> project <span class="k">of</span> data) {
  console.<span class="f">log</span>(project.name, project.categories.<span class="f">join</span>(<span class="s">', '</span>));
}</pre>

<pre id="c-py" hidden><span class="k">import</span> os, requests

r = requests.<span class="f">get</span>(
    <span class="s">"{{ $baseUrl }}/projects"</span>,
    headers={<span class="s">"Authorization"</span>: <span class="s">f"Bearer {os.environ['AMANAHKU_KEY']}"</span>},
    timeout=<span class="n">10</span>,
)

payload = r.<span class="f">json</span>()

<span class="k">if</span> payload.<span class="f">get</span>(<span class="s">"error"</span>):
    <span class="k">raise</span> <span class="n">RuntimeError</span>(payload[<span class="s">"error"</span>])

<span class="k">for</span> project <span class="k">in</span> payload[<span class="s">"data"</span>]:
    <span class="f">print</span>(project[<span class="s">"name"</span>], project[<span class="s">"categories"</span>])</pre>
        </div>
      </div>
    </section>

    <section id="capabilities">
      <h2>What it can do</h2>
      <p class="sub">Six endpoints. Your key only opens the ones it was ticked for.</p>
      <div class="caps">
        @foreach ($endpoints as $endpoint)
          <div class="cap">
            <div class="m"><span class="verb">GET</span> {{ $endpoint['path'] }}</div>
            <h3>{{ $endpoint['title'] }}</h3>
            <p>{{ $endpoint['blurb'] }}</p>
            @if ($endpoint['app_key'])
              <span class="scope">{{ $endpoint['scope'] }}</span>
            @else
              <span class="scope" style="background:var(--shelf);color:var(--muted);border-color:var(--shelf-line);">Staff tokens only</span>
            @endif
          </div>
        @endforeach
      </div>
    </section>

    <section id="limits">
      <h2>What it won't do</h2>
      <p class="sub">Assume any of these and your integration breaks, or worse, silently does the wrong thing.</p>
      <div class="nots">
        <div class="not"><b>No writes, ever</b><span>All six routes are GET. You cannot create, change or delete anything here.</span></div>
        <div class="not"><b>No webhooks</b><span>AmanahKu never calls you. Poll on your own schedule.</span></div>
        <div class="not"><b>No pagination</b><span>Every list returns the whole company at once. No cursor will appear later.</span></div>
        <div class="not"><b>No rate limit today</b><span>No 429, no X-RateLimit headers. Don't build retry logic around them.</span></div>
      </div>
    </section>

    <section id="endpoints">
      <h2>Endpoints</h2>
      <p class="sub">Real response bodies, not invented ones.</p>

      {{-- Path, scope chip and the "Staff tokens only" marker come from $endpoints, same
           as the capability cards above, so a scope rename can't leave this section
           contradicting them. The worked JSON bodies below have no structured equivalent
           in ApiReference::ENDPOINTS, so they stay hand-authored here, matched by path. --}}
      @foreach ($endpoints as $endpoint)
        <details class="ep" @if ($loop->first) open @endif>
          <summary>
            <span class="arrow">▶</span><span class="verb">GET</span><span class="path">{{ $endpoint['path'] }}</span>
            <span class="tail">
              @if ($endpoint['path'] === '/timesheet-effort')<span class="t">?week_start=</span>@endif
              @if ($endpoint['app_key'])
                <span class="scope" style="margin:0">{{ $endpoint['scope'] }}</span>
              @else
                <span class="scope" style="margin:0;background:var(--shelf);color:var(--muted);border-color:var(--shelf-line);">Staff tokens only</span>
              @endif
            </span>
          </summary>
          <div class="body">
            @if ($endpoint['path'] === '/projects')
              <p>Active projects only. <code>categories</code> is sorted alphabetically and is <code>[]</code> for an untagged project.</p>
              <div class="codewrap rel"><button class="copysm" onclick="copyPre(this)">Copy</button><pre>{
  <span class="n">"data"</span>: [
    { <span class="n">"id"</span>: <span class="n">7</span>,  <span class="n">"code"</span>: <span class="s">"UJ-014"</span>, <span class="n">"name"</span>: <span class="s">"KDN: iLPF"</span>,    <span class="n">"categories"</span>: [<span class="s">"Development"</span>, <span class="s">"Maintenance"</span>] },
    { <span class="n">"id"</span>: <span class="n">1</span>,  <span class="n">"code"</span>: <span class="k">null</span>,      <span class="n">"name"</span>: <span class="s">"JKDM: MyStods"</span>, <span class="n">"categories"</span>: [<span class="s">"Development"</span>] },
    { <span class="n">"id"</span>: <span class="n">14</span>, <span class="n">"code"</span>: <span class="k">null</span>,      <span class="n">"name"</span>: <span class="s">"Amanahku"</span>,      <span class="n">"categories"</span>: [] }
  ],
  <span class="n">"error"</span>: <span class="k">null</span>
}</pre></div>
            @elseif ($endpoint['path'] === '/timesheet-effort')
              <p>One week of effort, aggregated per project per position band. Aggregation happens server-side on purpose: no employee name, id or salary ever crosses the wire.</p>
              <div class="codewrap rel"><button class="copysm" onclick="copyPre(this)">Copy</button><pre>{
  <span class="n">"data"</span>: {
    <span class="n">"week_start"</span>: <span class="s">"2026-08-03"</span>,
    <span class="n">"projects"</span>: [
      { <span class="n">"project_id"</span>: <span class="n">1</span>, <span class="n">"positions"</span>: [
          { <span class="n">"position_id"</span>: <span class="n">31</span>, <span class="n">"position_title"</span>: <span class="s">"Developer"</span>,
            <span class="n">"headcount"</span>: <span class="n">1</span>, <span class="n">"person_days"</span>: <span class="n">5</span>, <span class="n">"days_present"</span>: <span class="n">5</span>, <span class="n">"alloc_pct"</span>: <span class="n">100</span> },
          { <span class="n">"position_id"</span>: <span class="n">29</span>, <span class="n">"position_title"</span>: <span class="s">"Project Exec"</span>,
            <span class="n">"headcount"</span>: <span class="n">1</span>, <span class="n">"person_days"</span>: <span class="n">3</span>, <span class="n">"days_present"</span>: <span class="n">5</span>, <span class="n">"alloc_pct"</span>: <span class="n">60</span> }
      ]}
    ]
  },
  <span class="n">"error"</span>: <span class="k">null</span>
}</pre></div>
            @else
              <p>{{ $endpoint['blurb'] }}</p>
            @endif
          </div>
        </details>
      @endforeach
    </section>

    <section id="errors">
      <h2>Errors</h2>
      <p class="sub">Two of these do not use the standard envelope. Handle both shapes or your client will throw on the parse.</p>
      <table style="background:var(--card);border:1px solid var(--hairline);border-radius:11px;overflow:hidden">
        <tr><th style="width:74px">Status</th><th>Body</th><th>Means</th></tr>
        <tr><td><code>401</code></td><td><code>{"message": "Unauthenticated."}</code></td><td>Key missing or unrecognised. <b>Not</b> the usual envelope.</td></tr>
        <tr><td><code>401</code></td><td><code>{"data": null, "error": "Unauthenticated."}</code></td><td>Key is real but its company binding no longer holds.</td></tr>
        <tr><td><code>403</code></td><td><code>{"data": null, "error": "This token lacks the payslips:read scope."}</code></td><td>Valid key, wrong scope. Ask for it to be re-issued.</td></tr>
        <tr><td><code>5xx</code></td><td><code>{"message": "…", "reference": "…"}</code></td><td>Quote <code>reference</code> when reporting. Not always present.</td></tr>
      </table>
    </section>

    <section id="gotchas">
      <h2>Known traps</h2>
      <p class="sub">Each of these has already cost somebody an afternoon.</p>

      <div class="got">
        <h3>⚠ week_start must be an exact Monday</h3>
        <p>Send any other day and you get <code>200 OK</code> with an empty list. No error, no warning. Your sync looks like it worked and quietly records nothing.</p>
        <pre><span class="c">// wrong — a Wednesday</span>
{ <span class="n">"data"</span>: { <span class="n">"week_start"</span>: <span class="s">"2026-08-05"</span>, <span class="n">"projects"</span>: [] }, <span class="n">"error"</span>: <span class="k">null</span> }</pre>
      </div>

      <div class="got">
        <h3>⚠ A missing key and a stale key look different</h3>
        <p>An unrecognised key never reaches our code, so it gets the framework's own <code>{"message": …}</code>. A recognised key whose company binding broke gets <code>{"data": null, "error": …}</code>. Read <code>error</code> <i>and</i> <code>message</code>, or you will log <code>undefined</code>.</p>
      </div>

      <div class="got">
        <h3>⚠ Keys are shown exactly once</h3>
        <p>Only a scrambled copy is stored, so nobody, including the person who issued it, can read it back. Lost it? Revoke and issue a new one.</p>
      </div>
    </section>

    <footer>
      <span>AmanahKu API v1 · read-only</span>
      <a href="/openapi.json">OpenAPI 3.1</a>
      <a href="#">Report a problem</a>
    </footer>
  </main>
</div>

<div id="toast"></div>

{{-- The agent brief, rendered server-side from ApiReference::agentBrief() so the "copy
     everything for your AI" button and the page can never disagree. A hidden <pre> (not
     a <script> raw-text element) so the browser parses and decodes it normally: reading
     .textContent below returns the real characters, not HTML entities. --}}
<pre id="agent-brief" hidden>{{ $brief }}</pre>

@php
    $openapiUrl = \Illuminate\Support\Str::replaceLast(\App\Support\ApiReference::BASE_PATH, '/openapi.json', $baseUrl);
    $curlText = 'curl -s "'.$baseUrl.'/projects" -H "Authorization: Bearer $AMANAHKU_KEY"';
@endphp
<script>
const OPENAPI_URL_TEXT = {!! \Illuminate\Support\Js::from($openapiUrl) !!};
const CURL_TEXT = {!! \Illuminate\Support\Js::from($curlText) !!};

function toast(m){const t=document.getElementById('toast');t.textContent=m;t.classList.add('on');clearTimeout(t._x);t._x=setTimeout(()=>t.classList.remove('on'),1900);}
function flash(b,label){const o=b.textContent;b.classList.add('ok');b.textContent=label||'Copied';setTimeout(()=>{b.classList.remove('ok');b.textContent=o;},1500);}
function copyText(txt,btn,msg){navigator.clipboard.writeText(txt).then(()=>{if(btn)flash(btn);toast(msg||'Copied');});}
function copyPre(btn){const pre=btn.parentElement.querySelector('pre:not([hidden])');copyText(pre.innerText,btn,'Copied');}
function tab(btn,id){
  btn.parentElement.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));
  btn.classList.add('on');
  ['curl','php','js','py'].forEach(k=>document.getElementById('c-'+k).hidden = (k!==id));
}
function copyAgent(btn){
  const t = document.getElementById('agent-brief').textContent;
  copyText(t, btn, 'Full instruction block copied — paste it into your AI');
}
document.querySelectorAll('nav.side a').forEach(a=>a.addEventListener('click',()=>{
  document.querySelectorAll('nav.side a').forEach(x=>x.classList.remove('on'));a.classList.add('on');
}));
</script>
</body>
</html>
