{{-- Installable web app. Must be on EVERY page with its own <head>: a user can add to
     the Home Screen from any screen, and the browser only reads these tags from the page
     it is on. On iOS this is also the only route to notifications at all: Safari shows
     them just for a web app added to the Home Screen. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#d6232b">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Amanahku">
