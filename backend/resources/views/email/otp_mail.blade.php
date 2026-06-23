<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Created | Kulsah</title>
    <style>
        /* Responsive styles for mobile devices */
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
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: Arial, Helvetica, sans-serif; -webkit-font-smoothing: antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f4f6f8; padding:30px 0;">
        <tr>
            <td align="center">
              <table class="container-table" width="600" cellpadding="0" cellspacing="0" role="presentation"
    style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.05); width: 600px; max-width: 100%;">

    <!-- LOGO SECTION -->
    <tr>
        <td style="background-color:#ffffff; padding:20px; text-align:center;">
            <img src="https://i.postimg.cc/0rg9J7V5/Kulsah-000200.png"
                 alt="Kulsah Logo"
                 style="max-width:150px; height:auto; display:block; margin:0 auto;">
        </td>
    </tr>

    <!-- HEADER -->

    <!-- BODY -->
    <tr>
        <td class="content-padding" style="padding:40px 30px; color:#374151; font-size:15px; line-height:1.6;">
            <p style="margin-top:0; font-size: 18px;">
                Hello <strong>{{ $name }}</strong>,
            </p>

            <p>
               Welcome to Kulsah, where creators create, earn, connect, and shine.Use the verification code below to complete your account setup
            </p>

            <p style="margin-bottom: 10px;">
                Your OTP is:
            </p>

            <div style="background:#f3f4f6; padding:15px; border-radius:5px; text-align:center; font-size:18px; border: 1px dashed #d1d5db; margin-bottom: 20px;">
                <strong style="color: #103720; letter-spacing: 1px;">{{ $otp }}</strong>
            </div>

            <p>
            This code will expire in 10 minutes. If you did not request this code, please ignore this message.
            </p>

            <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 30px 0;">

            <p style="font-size:13px; color:#6b7280; margin-top: 40px; border-left: 3px solid #e5e7eb; padding-left: 15px;">
                <em>If you did not expect this email, you can safely ignore it. No action is required.</em>
            </p>

            <p style="margin-bottom:0; padding-top: 20px;">
                The Kulsah Team,<br>
                <strong>
               Creator Galaxy 🌌</strong>
            </p>
        </td>
    </tr>

    <!-- FOOTER -->
    <tr>
        <td style="background-color:#f9fafb; padding:20px; text-align:center; font-size:12px; color:#9ca3af; border-top: 1px solid #f3f4f6;">
            © {{ date('Y') }} Kulsah. All rights reserved.<br>
            This is an automated message, please do not reply.
        </td>
    </tr>

</table>
            </td>
        </tr>
    </table>
</body>
</html>