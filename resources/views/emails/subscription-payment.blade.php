<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Payment {{ $status === 'active' ? 'Receipt' : 'Notice' }}</title>
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
                                Subscription &amp; Billing Notice
                            </div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 36px 32px; color: #3d281d;">
                            @if ($status === 'active')
                                <h2 style="margin-top: 0; margin-bottom: 18px; color: #23150d; font-size: 19px; font-weight: 600; letter-spacing: -0.2px;">
                                    Subscription Payment Confirmation
                                </h2>
                                <p style="font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 16px; color: #4a382d;">
                                    Dear {{ $ownerName }},
                                </p>
                                <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px; color: #4a382d;">
                                    We have successfully received and processed your subscription payment. Your subscription is now active, and all platform features associated with your plan are fully accessible.
                                </p>

                                <!-- Payment Details Card -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf7f2; border: 1px solid #e8e0d5; border-radius: 6px; margin-bottom: 24px;">
                                    <tr>
                                        <td style="padding: 18px 20px;">
                                            <p style="margin: 0 0 12px 0; font-size: 12px; font-weight: 600; color: #786050; text-transform: uppercase; letter-spacing: 0.5px;">
                                                Transaction Summary
                                            </p>
                                            <table border="0" cellpadding="4" cellspacing="0" width="100%" style="font-size: 14px;">
                                                <tr>
                                                    <td width="35%" style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Subscription Plan:</td>
                                                    <td style="color: #26160e; font-weight: 600;">{{ $planName }}</td>
                                                </tr>
                                                @if ($amount)
                                                    <tr>
                                                        <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Amount Paid:</td>
                                                        <td style="color: #26160e; font-weight: 600;">₱{{ $amount }}</td>
                                                    </tr>
                                                @endif
                                                @if ($endDate)
                                                    <tr>
                                                        <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Valid Until:</td>
                                                        <td style="color: #26160e; font-weight: 600;">{{ $endDate }}</td>
                                                    </tr>
                                                @endif
                                                <tr>
                                                    <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Payment Status:</td>
                                                    <td>
                                                        <span style="display: inline-block; background-color: #f0f7f0; color: #1e6628; border: 1px solid #c3e2c5; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">
                                                            Completed
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <p style="font-size: 13px; line-height: 1.6; color: #8c7668; margin-top: 20px; margin-bottom: 0;">
                                    This email serves as your official electronic receipt. Please retain this notice for your records.
                                </p>
                            @else
                                <h2 style="margin-top: 0; margin-bottom: 18px; color: #23150d; font-size: 19px; font-weight: 600; letter-spacing: -0.2px;">
                                    Notice of Unsuccessful Subscription Payment
                                </h2>
                                <p style="font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 16px; color: #4a382d;">
                                    Dear {{ $ownerName }},
                                </p>
                                <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px; color: #4a382d;">
                                    We regret to inform you that we were unable to process the subscription payment for the plan specified below. As a result, your subscription has not been activated.
                                </p>

                                <!-- Failed Payment Details Card -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf7f2; border: 1px solid #e8e0d5; border-radius: 6px; margin-bottom: 24px;">
                                    <tr>
                                        <td style="padding: 18px 20px;">
                                            <p style="margin: 0 0 12px 0; font-size: 12px; font-weight: 600; color: #786050; text-transform: uppercase; letter-spacing: 0.5px;">
                                                Attempted Transaction
                                            </p>
                                            <table border="0" cellpadding="4" cellspacing="0" width="100%" style="font-size: 14px;">
                                                <tr>
                                                    <td width="35%" style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Target Plan:</td>
                                                    <td style="color: #26160e; font-weight: 600;">{{ $planName }}</td>
                                                </tr>
                                                <tr>
                                                    <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Transaction Status:</td>
                                                    <td>
                                                        <span style="display: inline-block; background-color: #fcf0f0; color: #9a2020; border: 1px solid #ecc5c5; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">
                                                            Payment Unsuccessful
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Warning Block -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf8f5; border-left: 3px solid #9c7356; border-radius: 4px; margin-bottom: 24px;">
                                    <tr>
                                        <td style="padding: 14px 18px; font-size: 13px; line-height: 1.5; color: #6e5849;">
                                            <strong>Action Required:</strong> Please sign in to your merchant dashboard to review your payment details or provide an alternative payment method.
                                        </td>
                                    </tr>
                                </table>

                                <p style="font-size: 13px; line-height: 1.6; color: #8c7668; margin-top: 20px; margin-bottom: 0;">
                                    If you require assistance or believe this transaction was declined in error, please contact our billing support department.
                                </p>
                            @endif

                            <!-- Sign-off -->
                            <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid #eee8df; font-size: 14px; line-height: 1.6; color: #5c483c;">
                                Sincerely,<br>
                                <strong style="color: #2c1a0e;">BrewSpot Billing Department</strong><br>
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