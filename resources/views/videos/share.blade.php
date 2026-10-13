<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Kulsah</title>
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="description" content="{{ $description }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Kulsah">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if($thumbnail)
        <meta property="og:image" content="{{ $thumbnail }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <style>
        body{margin:0;background:#10141d;color:#fff;font:17px system-ui,sans-serif}
        main{max-width:680px;margin:40px auto;padding:24px}header{display:flex;align-items:center;gap:12px;font-size:26px;font-weight:700}
        header img{width:60px;height:60px}video,.poster{width:100%;max-height:70vh;object-fit:contain;border-radius:16px;background:#000}
        a{display:inline-block;color:#fff;background:#008fff;padding:12px 20px;border-radius:10px;text-decoration:none}p{line-height:1.5;overflow-wrap:anywhere}
    </style>
</head>
<body><main>
    <header><img src="{{ rtrim(config('app.url'), '/') }}/brand/kulsah-logo.png" alt="Kulsah logo">Kulsah</header>
    <h1>{{ $title }}</h1>
    @if($playback)
        <video controls playsinline preload="metadata" @if($thumbnail) poster="{{ $thumbnail }}" @endif src="{{ $playback }}"></video>
    @elseif($thumbnail)
        <img class="poster" src="{{ $thumbnail }}" alt="Video thumbnail">
    @endif
    @if($handle)<p>{{ $handle }}</p>@endif
    <p>{{ $description }}</p>
    <a href="{{ $canonical }}" rel="noreferrer">Open in Kulsah</a>
</main></body>
</html>
