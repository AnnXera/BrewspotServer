<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Temporary Password</title>
</head>
<body style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; margin: 0; padding: 0; background-color: #f7f5f0; -webkit-text-size-adjust: none; text-size-adjust: none;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f7f5f0; padding: 40px 16px;">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 6px; overflow: hidden; border: 1px solid #e7e0d8; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">

                    <!-- Header -->
                    <tr>
                        <td style="background-color: #2b1810; padding: 28px 30px; text-align: center;">
                            <div style="color: #f5ede4; font-size: 20px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase;">
                                BREWSPOT
                            </div>
                            <div style="color: #d1bba9; font-size: 10px; font-weight: 500; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 4px;">
                                Password Recovery Assistance
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 36px 32px; color: #3d281d;">
                            <h2 style="margin-top: 0; margin-bottom: 18px; color: #23150d; font-size: 19px; font-weight: 600; letter-spacing: -0.2px;">
                                Password Reset Request
                            </h2>
                            <p style="font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 16px; color: #4a382d;">
                                Hello {{ $firstname }},
                            </p>
                            <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px; color: #4a382d;">
                                We received a request to recover your password for your BrewSpot account (<strong>{{ $email }}</strong>). A new temporary password has been generated for you below:
                            </p>

                            <!-- Password Box -->
                            <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 28px auto; width: 100%; max-width: 360px;">
                                <tr>
                                    <td align="center" style="background-color: #faf7f2; border-radius: 8px; padding: 18px 24px; font-family: 'Courier New', Courier, monospace; font-size: 26px; font-weight: 700; color: #2b1810; border: 1px solid #dccdbf; letter-spacing: 2px;">
                                        {{ $tempPassword }}
                                    </td>
                                </tr>
                            </table>

                            <!-- Important Notice Block -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf8f5; border-left: 3px solid #9c7356; border-radius: 4px; margin-top: 24px;">
                                <tr>
                                    <td style="padding: 14px 18px; font-size: 13px; line-height: 1.5; color: #6e5849;">
                                        <strong>How to sign in:</strong> Return to the BrewSpot login screen, enter your email and copy-paste the temporary password above. Once logged in, you can update your password anytime in your Account Settings.
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 13px; line-height: 1.5; color: #8c7668; margin-top: 20px; margin-bottom: 0;">
                                If you did not request this password reset, please contact your cafe administrator or sign in immediately to secure your account.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #faf8f5; padding: 20px 32px; text-align: center; border-top: 1px solid #ede7df; font-size: 12px; color: #8c7668;">
                            &copy; {{ date('Y') }} BrewSpot Cafe Management System. All rights reserved.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
