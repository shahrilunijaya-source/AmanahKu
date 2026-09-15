<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') · AmanahKu</title>
{{ Vite::fonts() }}
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
body{margin:0;background:var(--canvas);color:var(--ink);font-family:var(--font-sans);font-size:15px;line-height:1.7;-webkit-font-smoothing:antialiased}
a{color:var(--red);text-decoration:none}
a:hover{text-decoration:underline}
h1,h2{margin:0;letter-spacing:-.4px;font-weight:600}
.top{position:sticky;top:0;z-index:50;background:rgba(246,246,243,.88);backdrop-filter:blur(10px);border-bottom:1px solid var(--hairline)}
.top-in{max-width:760px;margin:0 auto;padding:0 22px;height:58px;display:flex;align-items:center;gap:14px}
.brand{display:flex;align-items:center;gap:10px;font-weight:600;color:var(--ink)}
.mark{width:28px;height:28px;border-radius:8px;background:var(--red);color:#fff;display:grid;place-items:center;font-weight:700;font-size:14px}
.top .spacer{flex:1}
.top a.plain{color:var(--muted);font-size:13.5px;font-weight:500}
main{max-width:760px;margin:0 auto;padding:40px 22px 100px}
main h1{font-size:34px;line-height:1.15;margin-bottom:8px}
main .updated{color:var(--muted);font-size:13.5px;margin:0 0 28px}
main h2{font-size:19px;margin:34px 0 8px}
main p,main li{color:var(--body)}
main ul{padding-left:20px}
main li{margin-bottom:6px}
main strong{color:var(--ink);font-weight:600}
.foot{border-top:1px solid var(--hairline);margin-top:48px;padding-top:18px;font-size:13px;color:var(--muted)}
@media(max-width:600px){main h1{font-size:27px}}
</style>
@include('partials.pwa-head')
</head>
<body>

<div class="top"><div class="top-in">
  <a class="brand" href="/login" style="text-decoration:none"><div class="mark">A</div> AmanahKu</a>
  <div class="spacer"></div>
  <a class="plain" href="{{ route('privacy') }}">Privacy</a>
  <a class="plain" href="{{ route('terms') }}">Terms</a>
  <a class="plain" href="/login">Sign in</a>
</div></div>

<main>
  @yield('content')
  <div class="foot">© {{ date('Y') }} Unijaya Resources Sdn Bhd · Questions: <a href="mailto:developer.unijaya@gmail.com">developer.unijaya@gmail.com</a></div>
</main>

</body>
</html>
