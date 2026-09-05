<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Authentication Code</title>
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
                                Authentication & Security Notice
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 36px 32px; color: #3d281d;">
                            <h2 style="margin-top: 0; margin-bottom: 18px; color: #23150d; font-size: 19px; font-weight: 600; letter-spacing: -0.2px;">
                                Sign-In Verification Code
                            </h2>
                            <p style="font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 16px; color: #4a382d;">
                                Dear User,
                            </p>
                            <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px; color: #4a382d;">
                                A sign-in request was detected for your BrewSpot account. To authorize this session and complete your login, please enter the following verification code:
                            </p>

                            <!-- Code Block -->
                            <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 28px auto; width: 100%; max-width: 320px;">
                                <tr>
                                    <td align="center" style="background-color: #faf7f2; border-radius: 6px; padding: 18px 24px; letter-spacing: 8px; font-family: 'Courier New', Courier, monospace; font-size: 32px; font-weight: 700; color: #2b1810; border: 1px solid #dccdbf;">
                                        {{ $code }}
                                    </td>
                                </tr>
                            </table>

                            <!-- Important Notice Block -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf8f5; border-left: 3px solid #9c7356; border-radius: 4px; margin-top: 24px;">
                                <tr>
                                    <td style="padding: 14px 18px; font-size: 13px; line-height: 1.5; color: #6e5849;">
                                        <strong>Security Notice:</strong> This code is valid for <strong>15 minutes</strong>. If you did not initiate this sign-in attempt, please reset your password immediately to secure your account.
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 13px; line-height: 1.5; color: #8c7668; margin-top: 20px; margin-bottom: 0;">
                                For security reasons, BrewSpot staff will never ask for your authentication code.
                            </p>

                            <!-- Sign-off -->
                            <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid #eee8df; font-size: 14px; line-height: 1.6; color: #5c483c;">
                                Sincerely,<br>
                                <strong style="color: #2c1a0e;">BrewSpot Security Team</strong><br>
                                <span style="font-size: 12px; color: #8c7668;">BrewSpot Platform</span>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f7f3ed; padding: 22px 30px; text-align: center; font-size: 12px; line-height: 1.6; color: #8c7668; border-top: 1px solid #e7ded3;">
                            <p style="margin: 0 0 4px 0;">This is an automated system notification. Please do not reply directly to this email.</p>
                            <p style="margin: 0; color: #a39083;">&copy; {{ date('Y') }} BrewSpot Platform. All rights reserved.</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>