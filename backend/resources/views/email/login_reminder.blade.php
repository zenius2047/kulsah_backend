<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Login Alert | Kulsah</title>

    <style>
        @media screen and (max-width: 600px) {
            .container-table {
                width: 95% !important;
            }
            .content-padding {
                padding: 20px !important;
            }
            .header-text {
                font-size: 18px !important;
            }
        }
    </style>
</head>

<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: Arial, Helvetica, sans-serif;">
    
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f4f6f8; padding:30px 0;">
<tr>
<td align="center">

<table class="container-table" width="600" cellpadding="0" cellspacing="0" role="presentation"
    style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.05); width: 600px; max-width: 100%;">

    <!-- LOGO -->
    <tr>
        <td style="background-color:#ffffff; padding:20px; text-align:center;">
            <img src="https://i.postimg.cc/0rg9J7V5/Kulsah-000200.png"
                 alt="Kulsah Logo"
                 style="max-width:150px; height:auto; display:block; margin:0 auto;">
        </td>
    </tr>

    <!-- HEADER -->
    <tr>
        <td style="background-color:#103720; padding:25px 20px; text-align:center;">
            <h1 style="margin:0; color:#ffffff; font-size:20px;">
                New Login Detected
            </h1>
        </td>
    </tr>

    <!-- BODY -->
    <tr>
        <td class="content-padding" style="padding:40px 30px; color:#374151; font-size:15px; line-height:1.6;">

            <p style="margin-top:0; font-size:18px;">
                Hello <strong>{{ $name }}</strong>,
            </p>

            <p>
                We detected a new login to your <strong>Kulsah</strong> account. If this was you, you can ignore this message.  
                If not, we recommend securing your account immediately.
            </p>

            <hr style="border:0; border-top:1px solid #e5e7eb; margin:25px 0;">

            <!-- LOGIN DETAILS -->
            <p><strong>Login Details:</strong></p>

            <ul style="padding-left:18px; color:#374151;">
                <li><strong>IP Address:</strong> {{ $ip }}</li>

                <li><strong>Location:</strong>
                    {{ is_array($location) ? json_encode($location) : $location }}
                </li>

                <li><strong>Device:</strong>
                    {{ $device['device'] ?? 'Unknown Device' }}
                </li>

                <li><strong>Browser:</strong>
                    {{ $device['browser'] ?? 'Unknown' }}
                </li>

                <li><strong>Platform:</strong>
                    {{ $device['platform'] ?? 'Unknown' }}
                </li>

                <li><strong>Time:</strong> {{ $time }}</li>
            </ul>

            <hr style="border:0; border-top:1px solid #e5e7eb; margin:25px 0;">

            <!-- WARNING -->
            <p style="font-size:13px; color:#6b7280; border-left:3px solid #e5e7eb; padding-left:15px;">
                <em>
                    If this wasn't you, please reset your password immediately and secure your account.
                </em>
            </p>

            <p style="margin-top:25px;">
                Regards,<br>
                <strong>Kulsah Security Team</strong>
            </p>

        </td>
    </tr>

    <!-- FOOTER -->
    <tr>
        <td style="background-color:#f9fafb; padding:20px; text-align:center; font-size:12px; color:#9ca3af;">
            © {{ date('Y') }} Kulsah. All rights reserved.<br>
            This is an automated security alert, please do not reply.
        </td>
    </tr>

</table>

</td>
</tr>
</table>

</body>
</html>