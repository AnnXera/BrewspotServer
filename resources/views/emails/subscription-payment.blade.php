<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscription Payment {{ $status === 'active' ? 'Successful' : 'Failed' }}</title>
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
                            @if ($status === 'active')
                                <h2 style="margin-top: 0; color: #2c1a0e; font-size: 20px;">Payment Successful</h2>
                                <p style="font-size: 16px; line-height: 1.6; margin-bottom: 25px; color: #5c3d2e;">
                                    Hi {{ $ownerName }}, your payment has been received and your subscription is now active.
                                </p>

                                <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 30px auto; width: 100%;">
                                    <tr>
                                        <td style="background-color: #fdf3e7; border-radius: 6px; padding: 20px 30px; border: 1px solid #d4a574;">
                                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #7a5c44;">Plan</p>
                                            <p style="margin: 0 0 16px 0; font-size: 18px; font-weight: bold; color: #3b1f0e;">{{ $planName }}</p>

                                            @if ($amount)
                                                <p style="margin: 0 0 8px 0; font-size: 14px; color: #7a5c44;">Amount Paid</p>
                                                <p style="margin: 0 0 16px 0; font-size: 18px; font-weight: bold; color: #3b1f0e;">₱{{ $amount }}</p>
                                            @endif

                                            @if ($endDate)
                                                <p style="margin: 0 0 8px 0; font-size: 14px; color: #7a5c44;">Renews / Expires On</p>
                                                <p style="margin: 0; font-size: 18px; font-weight: bold; color: #3b1f0e;">{{ $endDate }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                </table>

                                <p style="font-size: 14px; line-height: 1.5; color: #7a5c44; margin-top: 25px;">
                                    ✅ You can now continue using all features included in this plan.
                                </p>
                            @else
                                <h2 style="margin-top: 0; color: #2c1a0e; font-size: 20px;">Payment Failed</h2>
                                <p style="font-size: 16px; line-height: 1.6; margin-bottom: 25px; color: #5c3d2e;">
                                    Hi {{ $ownerName }}, we were unable to process your payment for the following plan:
                                </p>

                                <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin: 30px auto; width: 100%;">
                                    <tr>
                                        <td style="background-color: #fdf3e7; border-radius: 6px; padding: 20px 30px; border: 1px solid #d4a574;">
                                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #7a5c44;">Plan</p>
                                            <p style="margin: 0; font-size: 18px; font-weight: bold; color: #3b1f0e;">{{ $planName }}</p>
                                        </td>
                                    </tr>
                                </table>

                                <p style="font-size: 14px; line-height: 1.5; color: #7a5c44; margin-top: 25px;">
                                    ⚠️ Your subscription has not been activated. Please try again or use a different payment method.
                                </p>
                            @endif
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