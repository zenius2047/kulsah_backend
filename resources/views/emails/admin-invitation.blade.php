<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Your Kulsah administrator invitation</title>
</head>
<body style="margin:0; padding:0; background:#f3f5f4; color:#17231d; font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">
        You’ve been invited to join the Kulsah Admin team. Set up your account in a few minutes.
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f5f4;">
        <tr>
            <td align="center" style="padding:36px 16px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:600px; background:#ffffff; border:1px solid #e2e8e4; border-radius:16px; overflow:hidden;">
                    <tr>
                        <td style="padding:24px 32px; border-bottom:1px solid #edf0ee;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="font-size:20px; font-weight:700; letter-spacing:-.4px; color:#123d2b;">Kulsah<span style="color:#d69b37;">.</span></td>
                                    <td align="right" style="font-size:11px; font-weight:700; letter-spacing:1.4px; color:#78857d;">ADMIN CONSOLE</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:38px 40px 20px;">
                            <div style="display:inline-block; padding:7px 11px; border-radius:999px; background:#edf6f0; color:#236643; font-size:12px; font-weight:700;">TEAM INVITATION</div>
                            <h1 style="margin:20px 0 12px; color:#17231d; font-size:28px; line-height:1.25; letter-spacing:-.6px;">You’re invited, {{ $name }}</h1>
                            <p style="margin:0; color:#59665e; font-size:15px; line-height:1.7;">An administrator has invited you to help manage Kulsah. Your assigned role is <strong style="color:#17231d;">{{ $role }}</strong>.</p>
                            <p style="margin:16px 0 0; color:#59665e; font-size:15px; line-height:1.7;">Set a password to activate your account and sign in to the admin console.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px 40px 28px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" style="border-radius:9px; background:#17633c;">
                                        <a href="{{ $inviteUrl }}" target="_blank" style="display:inline-block; padding:15px 25px; border:1px solid #17633c; border-radius:9px; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none;">Set up your account</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:18px 0 0; color:#68756d; font-size:13px; line-height:1.6;">This one-time link expires in <strong>48 hours</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 40px 30px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f7f9f7; border:1px solid #e8ede9; border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 18px; color:#647168; font-size:12px; line-height:1.6;">
                                        <strong style="display:block; margin-bottom:4px; color:#34443a; font-size:13px;">Can’t open the button?</strong>
                                        Copy and paste this link into your browser:<br>
                                        <a href="{{ $inviteUrl }}" style="color:#17633c; word-break:break-all;">{{ $inviteUrl }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 40px; border-top:1px solid #edf0ee;">
                            <p style="margin:0; color:#78847c; font-size:12px; line-height:1.7;">If you weren’t expecting this invitation, ignore this message. The link is single-use, and your account won’t be activated unless you set a password.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px 24px 25px; background:#fafbfa; color:#8a958e; font-size:11px; line-height:1.6;">
                            © {{ date('Y') }} Kulsah. All rights reserved.<br>
                            This is an automated security email. Please don’t reply.
                        </td>
                    </tr>
                </table>
                <p style="margin:18px 0 0; color:#9aa39d; font-size:11px;">Creator Galaxy</p>
            </td>
        </tr>
    </table>
</body>
</html>
