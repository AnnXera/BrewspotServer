<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Branch Status Update</title>
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
                            <h2 style="margin-top: 0; color: #2c1a0e; font-size: 20px;">{{ $heading }}</h2>
                            <p style="font-size: 16px; line-height: 1.6; margin-bottom: 10px; color: #5c3d2e;">
                                Hi {{ $firstname }},
                            </p>
                            <p style="font-size: 16px; line-height: 1.6; color: #5c3d2e;">
                                {{ $bodyMessage }}
                            </p>

                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 24px 0; background-color: #f5ede3; border-radius: 6px;">
                                <tr>
                                    <td style="padding: 16px 20px; font-size: 14px; color: #4a2e1a;">
                                        <strong>Branch:</strong> {{ $branchName }}
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 14px; line-height: 1.6; color: #a0846c;">
                                If you have any questions, feel free to reach out to our support team.
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