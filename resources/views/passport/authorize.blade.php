<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize {{ $client->name }}</title>
    <style>
        :root {
            --brand: #38a9e5;
            --brand-dark: #2c86b8;
            --bg: #f3f5f8;
            --card: #ffffff;
            --border: #e2e5ea;
            --text: #1c1f26;
            --muted: #5f6672;
            --muted-2: #8a909c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "Google Sans", Roboto, Inter, ui-sans-serif, system-ui,
                -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background: var(--bg);
        }
        .card {
            width: 100%;
            max-width: 440px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px 32px 24px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 8px 24px rgba(16, 24, 40, 0.06);
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

        /* App identity block */
        .app-identity {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }
        .app-logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            border: 1px solid var(--border);
            object-fit: cover;
            flex-shrink: 0;
            background: var(--card);
        }
        .app-logo-fallback {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            flex-shrink: 0;
            background: var(--brand);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .app-identity-text {
            min-width: 0;
        }
        .app-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
            margin: 0 0 2px;
            overflow-wrap: anywhere;
        }
        .app-description {
            font-size: 13px;
            color: var(--muted);
            margin: 0;
            line-height: 1.5;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 600;
            line-height: 1.35;
        }
        .subtitle {
            margin: 0 0 20px;
            font-size: 14px;
            color: var(--muted);
            line-height: 1.55;
        }
        .subtitle strong {
            color: var(--text);
            font-weight: 600;
        }
        .panel {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }
        .panel-label {
            margin: 0 0 10px;
            font-size: 13px;
            color: var(--muted);
        }
        .scope-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 7px 0;
        }
        .scope-icon {
            width: 18px;
            height: 18px;
            margin-top: 1px;
            flex-shrink: 0;
            color: var(--brand-dark);
        }
        .scope-text {
            font-size: 14px;
            line-height: 1.45;
        }
        .scope-text .scope-id {
            display: block;
            font-weight: 600;
            color: var(--text);
        }
        .scope-text .scope-desc {
            display: block;
            color: var(--muted);
            font-size: 13px;
        }
        .basic-auth-note {
            margin: 0;
            font-size: 14px;
            color: var(--muted);
        }
        .fine-print {
            margin: 0 0 22px;
            font-size: 12px;
            line-height: 1.6;
            color: var(--muted-2);
        }
        .fine-print .redirect {
            color: var(--muted);
            word-break: break-all;
        }
        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            margin: 0;
        }
        form.actions { margin: 0; }
        button {
            appearance: none;
            border-radius: 8px;
            padding: 9px 18px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s ease, box-shadow 0.15s ease;
        }
        .deny {
            background: var(--card);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .deny:hover { background: #f7f8fa; }
        .approve {
            background: var(--brand);
            color: #ffffff;
            border: 1px solid var(--brand);
        }
        .approve:hover { background: var(--brand-dark); border-color: var(--brand-dark); }
        .button-row {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 4px;
        }
        .hint {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted-2);
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand-row">
            <span class="brand-mark">
                <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M11 24V12h2.6l7 8.3V12h2.6v12h-2.6l-7-8.3V24H11z" fill="#ffffff"/>
                </svg>
            </span>
            <span class="brand-name">Kulsah</span>
        </div>

        <div class="app-identity">
            @if (!empty($client->logo_url))
                <img class="app-logo" src="{{ $client->logo_url }}" alt="{{ $client->name }} logo">
            @else
                <span class="app-logo-fallback">{{ strtoupper(substr($client->name, 0, 1)) }}</span>
            @endif
            <div class="app-identity-text">
                <p class="app-name">{{ $client->name }}</p>
                @if (!empty($client->description))
                    <p class="app-description">{{ $client->description }}</p>
                @endif
            </div>
        </div>

        <h1>{{ $client->name }} wants to access your Kulsah account</h1>
        <p class="subtitle">
            Signed in as <strong>{{ $user->name ?? $user->email ?? 'Current user' }}</strong>
        </p>

        <div class="panel">
            @if (count($scopes))
                <p class="panel-label">This will allow {{ $client->name }} to:</p>
                @foreach ($scopes as $scope)
                    <div class="scope-row">
                        <svg class="scope-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6L9 17l-5-5" />
                        </svg>
                        <span class="scope-text">
                            <span class="scope-id">{{ $scope->id }}</span>
                            @if($scope->description)
                                <span class="scope-desc">{{ $scope->description }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            @else
                <p class="basic-auth-note">This app is requesting basic authorization.</p>
            @endif
        </div>

        <p class="fine-print">
            Make sure you trust {{ $client->name }} before continuing. You can review or remove this access anytime from your Kulsah account settings.
            <br class="redirect">Redirecting to: <span class="redirect">{{ $client->redirect_uris[0] ?? 'Not set' }}</span>
        </p>

        <div class="button-row">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="deny">Cancel</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="approve">Allow</button>
            </form>
        </div>

        <div class="hint">
            First-party apps can still use the API login endpoint. This consent screen is only for third-party OAuth clients.
        </div>
    </main>
</body>
</html>