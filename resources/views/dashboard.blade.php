<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | {{ config('app.name', 'Kulsah') }}</title>
    <style>
        :root {
            --bg: #eef3f8;
            --card: #ffffff;
            --text: #1c1f26;
            --muted: #5f6672;
            --brand: #38a9e5;
            --brand-dark: #2c86b8;
            --border: #dfe5ec;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Google Sans", Roboto, Inter, ui-sans-serif, system-ui,
                -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(56, 169, 229, 0.18), transparent 30%),
                linear-gradient(180deg, #f7fafc 0%, var(--bg) 100%);
            color: var(--text);
            min-height: 100vh;
            padding: 32px;
        }

        .shell {
            max-width: 960px;
            margin: 0 auto;
        }

        .hero {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 14px 40px rgba(16, 24, 40, 0.08);
        }

        .eyebrow {
            margin: 0 0 12px;
            color: var(--brand-dark);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(30px, 5vw, 52px);
            line-height: 1.05;
        }

        p {
            margin: 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.7;
            max-width: 68ch;
        }

        .actions {
            margin-top: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        a.button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            padding: 12px 18px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid transparent;
        }

        .primary {
            background: var(--brand);
            color: #fff;
        }

        .primary:hover {
            background: var(--brand-dark);
        }

        .secondary {
            background: #fff;
            color: var(--text);
            border-color: var(--border);
        }

        .secondary:hover {
            border-color: var(--brand);
            color: var(--brand-dark);
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <p class="eyebrow">Signed in</p>
            <h1>Welcome to your dashboard</h1>
            <p>
                Your login is now routed through the web backend, and this page is the default landing spot after a successful sign-in.
            </p>
            <div class="actions">
                <a class="button primary" href="{{ url('/') }}">Go home</a>
                <a class="button secondary" href="{{ route('logout') }}"
                   onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Sign out</a>
            </div>
            <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none;">
                @csrf
            </form>
        </section>
    </main>
</body>
</html>
