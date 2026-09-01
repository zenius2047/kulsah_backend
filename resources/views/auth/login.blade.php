<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in to {{ config('app.name', 'Kulsah') }}</title>
    <style>
        :root {
            --brand: #38a9e5;
            --brand-dark: #2c86b8;
            --brand-tint: #eaf5fd;
            --bg: #f3f5f8;
            --card: #ffffff;
            --border: #e2e5ea;
            --text: #1c1f26;
            --muted: #5f6672;
            --muted-2: #8a909c;
            --danger: #d13b3b;
            --danger-bg: #fdecec;
            --danger-border: #f3c6c6;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Google Sans", Roboto, Inter, ui-sans-serif, system-ui,
                -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .shell {
            width: 100%;
            max-width: 480px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px 32px 24px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 8px 24px rgba(16, 24, 40, 0.06);
        }

        .consent-panel {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: linear-gradient(180deg, #f8fbfe 0%, #ffffff 100%);
            padding: 18px;
            margin-bottom: 22px;
        }

        .consent-kicker {
            margin: 0 0 8px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--brand-dark);
        }

        .consent-title {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.35;
            color: var(--text);
        }

        .consent-copy {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.55;
        }

        .consent-meta {
            display: grid;
            gap: 10px;
            margin-top: 16px;
        }

        .consent-meta-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-top: 1px solid var(--border);
            padding-top: 10px;
            font-size: 13px;
        }

        .consent-meta-row span {
            color: var(--muted);
        }

        .consent-meta-row strong {
            color: var(--text);
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
        }

        .brand-mark {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .brand-mark svg { width: 18px; height: 18px; }

        .brand-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
        }

        h1 {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 600;
            line-height: 1.35;
        }

        p.subtitle {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.55;
        }

        label {
            display: block;
            font-size: 13px;
            margin-bottom: 6px;
            color: var(--text);
            font-weight: 600;
        }

        .field {
            margin-bottom: 16px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            border: 1px solid var(--border);
            background: var(--card);
            color: var(--text);
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(56, 169, 229, 0.15);
        }

        .error {
            margin-top: 6px;
            color: var(--danger);
            font-size: 12.5px;
        }

        .actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 22px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 13.5px;
        }

        .remember input {
            width: 16px;
            height: 16px;
            margin: 0;
            accent-color: var(--brand);
        }

        button {
            appearance: none;
            border: 1px solid var(--brand);
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            color: #ffffff;
            background: var(--brand);
            cursor: pointer;
            transition: background 0.15s ease, box-shadow 0.15s ease;
        }

        button:hover {
            background: var(--brand-dark);
            border-color: var(--brand-dark);
        }

        .note {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted-2);
            line-height: 1.6;
        }

        .error-banner {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 18px;
            border: 1px solid var(--danger-border);
            background: var(--danger-bg);
            color: var(--danger);
            border-radius: 10px;
            padding: 11px 13px;
            font-size: 13.5px;
            line-height: 1.5;
        }

        .error-banner svg {
            width: 16px;
            height: 16px;
            margin-top: 2px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="card">
            <div class="brand-row">
                <span class="brand-mark">
                    <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M11 24V12h2.6l7 8.3V12h2.6v12h-2.6l-7-8.3V24H11z" fill="#ffffff"/>
                    </svg>
                </span>
                <span class="brand-name">Kulsah</span>
            </div>

            @if (!empty($oauthClient))
                <section class="consent-panel" aria-label="OAuth consent context">
                    <p class="consent-kicker">Mobile sign-in request</p>
                    <h1 class="consent-title">{{ $oauthClient->name }} wants to use your Kulsah account</h1>
                    <p class="consent-copy">
                        This {{ $oauthClient->clientTypeLabel() }} app is asking you to sign in through Kulsah.
                        After you log in, you will review and approve the app permissions before returning to the app.
                    </p>

                    <div class="consent-meta">
                        <div class="consent-meta-row">
                            <span>App type</span>
                            <strong>{{ $oauthClient->clientTypeLabel() }}</strong>
                        </div>
                        <div class="consent-meta-row">
                            <span>Redirect URI</span>
                            <strong>{{ $oauthRedirectUri ?? 'Not set' }}</strong>
                        </div>
                    </div>
                </section>
            @endif

            <h1>Sign in to continue</h1>
            <p class="subtitle">
                Use your Kulsah account to approve this app and return safely to the OAuth flow.
            </p>

            @if ($errors->any())
                <div class="error-banner">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('web.login') }}">
                @csrf

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                    @error('email')
                        <div class="error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    @error('password')
                        <div class="error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="actions">
                    <label class="remember">
                        <input type="checkbox" name="remember" value="1">
                        Remember me
                    </label>

                    <button type="submit">Login</button>
                </div>
            </form>

            <div class="note">
                First-party apps can still use the API login endpoint. This page is only for browser-based OAuth authorization, including Android and iOS native app sign-in.
            </div>
        </section>
    </main>
</body>
</html>
