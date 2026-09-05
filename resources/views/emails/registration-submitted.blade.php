<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Application Acknowledgment</title>
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
                                Registration & Compliance Notice
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 36px 32px; color: #3d281d;">
                            <h2 style="margin-top: 0; margin-bottom: 18px; color: #23150d; font-size: 19px; font-weight: 600; letter-spacing: -0.2px;">
                                Business Registration Acknowledgment
                            </h2>
                            <p style="font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 16px; color: #4a382d;">
                                Dear {{ $firstname }},
                            </p>
                            <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px; color: #4a382d;">
                                Thank you for submitting your business registration application to BrewSpot. We hereby confirm receipt of your application and all accompanying documentation. Our verification and compliance team has initiated the formal review process.
                            </p>

                            <!-- Submission Summary Card -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf7f2; border: 1px solid #e8e0d5; border-radius: 6px; margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <p style="margin: 0 0 12px 0; font-size: 12px; font-weight: 600; color: #786050; text-transform: uppercase; letter-spacing: 0.5px;">
                                            Application Details
                                        </p>
                                        <table border="0" cellpadding="4" cellspacing="0" width="100%" style="font-size: 14px;">
                                            <tr>
                                                <td width="35%" style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Café Name:</td>
                                                <td style="color: #26160e; font-weight: 600;">{{ $cafeName }}</td>
                                            </tr>
                                            <tr>
                                                <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Primary Branch:</td>
                                                <td style="color: #26160e; font-weight: 600;">{{ $branchName }}</td>
                                            </tr>
                                            <tr>
                                                <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Review Status:</td>
                                                <td>
                                                    <span style="display: inline-block; background-color: #fff8eb; color: #8f5b12; border: 1px solid #edd5a6; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">
                                                        Pending Administrative Review
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 15px; line-height: 1.6; color: #4a382d; margin-bottom: 20px;">
                                You may access the submitted records and monitor the real-time status of your verification at any time via the secure portal:
                            </p>

                            <!-- CTA Button -->
                            <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 28px auto;">
                                <tr>
                                    <td align="center" style="border-radius: 5px; background-color: #2b1810;">
                                        <a href="{{ $applicationUrl }}" target="_blank" style="display: inline-block; padding: 13px 30px; font-size: 14px; font-weight: 600; letter-spacing: 0.5px; color: #ffffff; text-decoration: none; border-radius: 5px;">
                                            Review Application Status
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 12px; line-height: 1.5; color: #8c7668; text-align: center; margin-bottom: 28px;">
                                Direct access URL:<br>
                                <a href="{{ $applicationUrl }}" style="color: #5c483c; font-family: monospace; word-break: break-all;">{{ $applicationUrl }}</a>
                            </p>

                            <!-- Verification Protocol -->
                            <div style="border-top: 1px solid #eee8df; padding-top: 20px; margin-top: 20px;">
                                <h3 style="font-size: 14px; font-weight: 600; color: #23150d; margin-top: 0; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px;">
                                    Next Steps in Review Process
                                </h3>
                                <ol style="font-size: 13px; line-height: 1.6; color: #5c483c; padding-left: 20px; margin: 0;">
                                    <li style="margin-bottom: 6px;"><strong>Document Verification:</strong> Our compliance team verifies submitted business permits and identification documents within 1–3 business days.</li>
                                    <li><strong>Account Credentialing:</strong> Upon successful approval, you will receive an official notification to configure your administrator credentials and initialize your merchant portal.</li>
                                </ol>
                            </div>

                            <!-- Sign-off -->
                            <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid #eee8df; font-size: 14px; line-height: 1.6; color: #5c483c;">
                                Sincerely,<br>
                                <strong style="color: #2c1a0e;">BrewSpot Verification &amp; Compliance Team</strong><br>
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
