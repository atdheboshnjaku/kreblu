<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title')</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif; color: #1a1a2e; background: #fafafa; line-height: 1.7; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }

        .site-header { background: #1a1a2e; color: #fff; padding: 1.25rem 0; }
        .site-header .container { display: flex; justify-content: space-between; align-items: center; }
        .site-name { font-size: 1.3rem; font-weight: 700; color: #fff; text-decoration: none; letter-spacing: -0.3px; }
        .site-name:hover { text-decoration: none; opacity: 0.9; }
        .site-tagline { color: rgba(255,255,255,0.6); font-size: 0.85rem; margin-left: 1rem; }
        .site-nav a { color: rgba(255,255,255,0.8); margin-left: 1.5rem; font-size: 0.9rem; }
        .site-nav a:hover { color: #fff; text-decoration: none; }

        .container { max-width: 780px; margin: 0 auto; padding: 0 1.5rem; }
        .content { padding: 3rem 0; }

        .post-card { background: #fff; border-radius: 8px; padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .post-card h2 { font-size: 1.4rem; margin-bottom: 0.5rem; }
        .post-card h2 a { color: #1a1a2e; }
        .post-card h2 a:hover { color: #2563eb; text-decoration: none; }
        .post-meta { color: #888; font-size: 0.85rem; margin-bottom: 1rem; }
        .post-body { font-size: 1.05rem; }
        .post-body p { margin-bottom: 1rem; }

        .single-post { background: #fff; border-radius: 8px; padding: 2.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .single-post h1 { font-size: 2rem; margin-bottom: 0.5rem; letter-spacing: -0.5px; }
        .single-post .post-meta { margin-bottom: 1.5rem; }
        .single-post .post-body { font-size: 1.1rem; }

        .site-footer { border-top: 1px solid #e5e7eb; padding: 2rem 0; margin-top: 3rem; text-align: center; color: #888; font-size: 0.85rem; }

        .empty-state { text-align: center; padding: 4rem 0; color: #888; }
        .empty-state h2 { color: #1a1a2e; margin-bottom: 0.5rem; }

        code { background: #f0eee8; padding: 2px 6px; border-radius: 3px; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 0.9em; color: #c6613f; }
        pre { background: #f0eee8; padding: 1.25rem; border-radius: 6px; overflow-x: auto; margin-bottom: 1rem; }
        pre code { background: none; padding: 0; color: inherit; font-size: 0.85em; }
        blockquote { border-left: 3px solid #c6613f; padding: 0.5rem 1rem; margin: 1rem 0; color: #666; font-style: italic; }
        .post-body ul, .post-body ol { margin: 0 0 1rem 1.5rem; }
        .post-body li { margin-bottom: 0.25rem; }
        .post-body img { max-width: 100%; height: auto; border-radius: 6px; margin: 1rem 0; }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <div style="display:flex;align-items:center;">
                <a href="/" class="site-name">{{ $site_name }}</a>
                @isset($site_description)
                    <span class="site-tagline">{{ $site_description }}</span>
                @endisset
            </div>
            <nav class="site-nav">
                <a href="/">Home</a>
                <a href="/about">About</a>
                @if($is_logged_in)
                    <a href="/kb-admin/">K Hub</a>
                @endif
            </nav>
        </div>
    </header>

    <main class="content">
        <div class="container">
            @yield('content')
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ $current_year }} {{ $site_name }}. Powered by <a href="https://kreblu.com" target="_blank">Kreblu</a>.</p>
        </div>
    </footer>
</body>
</html>