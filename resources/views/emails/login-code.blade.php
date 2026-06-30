<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login Verification</title>
</head>
<body style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; margin: 0; padding: 0; background-color: #f5f0eb; -webkit-text-size-adjust: none; text-size-adjust: none;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f0eb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #fffdf9; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.08);">

                    <tr>
                        <td style="background-color: #3b1f0e; padding: 30px; text-align: center;">
                            <h1 style="color: #f5e6d3; margin: 0; font-size: 26px; font-weight: 700; letter-spacing: 2px;">☕ BrewSpot</h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 40px 30px; color: #4a2e1a;">
                            <h2 style="margin-top: 0; color: #2c1a0e; font-size: 20px;">Login Verification Required</h2>
                            <p style="font-size: 16px; line-height: 1.6; margin-bottom: 25px; color: #5c3d2e;">
                                Someone is attempting to sign in to your account. Enter the 6-digit code below to complete your login:
                            </p>

                            <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 30px auto;">
                                <tr>
                                    <td align="center" style="background-color: #fdf3e7; border-radius: 6px; padding: 15px 40px; letter-spacing: 8px; font-family: monospace; font-size: 32px; font-weight: bold; color: #3b1f0e; border: 1px solid #d4a574;">
                                        {{ $code }}
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 14px; line-height: 1.5; color: #7a5c44; margin-top: 25px;">
                                ⚠️ This code expires in <strong>15 minutes</strong>.
                            </p>
                            <p style="font-size: 14px; line-height: 1.5; color: #a0846c; margin-bottom: 0;">
                                If this wasn't you, please change your password immediately.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color: #f5ede3; padding: 20px 30px; text-align: center; font-size: 12px; color: #a0846c; border-top: 1px solid #e8d5c0;">
                            &copy; {{ date('Y') }} BrewSpot Platform. All rights reserved.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>