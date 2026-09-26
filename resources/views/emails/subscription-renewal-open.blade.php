@extends('emails.layouts.billing')

@section('title', 'Your Subscription Is Ready to Renew')

@section('content')
    <p style="font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 16px; color: #4a382d;">
        Dear {{ $ownerName }},
    </p>
    <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px; color: #4a382d;">
        @if ($renewPlanName === null)
            Your <strong>{{ $planName }}</strong> ends on <strong>{{ $endDate }}</strong>. To keep using BrewSpot without interruption, please choose a plan and complete payment before then.
        @elseif ($renewPlanName !== $planName)
            Payment for your scheduled switch to the <strong>{{ $renewPlanName }}</strong> is now open. Your {{ $planName }} ends on <strong>{{ $endDate }}</strong>, and you are not charged automatically &mdash; please complete payment before then to move onto your new plan without interruption.
        @else
            Payment for your next <strong>{{ $planName }}</strong> term is now open. Your current term ends on <strong>{{ $endDate }}</strong>, and you are not charged automatically &mdash; please renew before then to keep uninterrupted access.
        @endif
    </p>

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf7f2; border: 1px solid #e8e0d5; border-radius: 6px; margin-bottom: 24px;">
        <tr>
            <td style="padding: 18px 20px;">
                <table border="0" cellpadding="4" cellspacing="0" width="100%" style="font-size: 14px;">
                    <tr>
                        <td width="35%" style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Current Plan:</td>
                        <td style="color: #26160e; font-weight: 600;">{{ $planName }}</td>
                    </tr>
                    @if ($renewPlanName !== null && $renewPlanName !== $planName)
                    <tr>
                        <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">New Plan:</td>
                        <td style="color: #26160e; font-weight: 600;">{{ $renewPlanName }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="color: #786050; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Pay Before:</td>
                        <td style="color: #26160e; font-weight: 600;">{{ $endDate }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 24px;">
        <tr>
            <td align="center">
                <a href="{{ $renewUrl }}" style="display: inline-block; background-color: #2b1810; color: #f5ede4; font-size: 14px; font-weight: 600; letter-spacing: 0.3px; text-decoration: none; padding: 13px 34px; border-radius: 5px;">
                    @if ($renewPlanName === null)
                        Choose a Plan
                    @elseif ($renewPlanName !== $planName)
                        Pay for {{ $renewPlanName }}
                    @else
                        Renew {{ $planName }}
                    @endif
                </a>
                <p style="margin: 12px 0 0 0; font-size: 12px; line-height: 1.5; color: #8c7668;">
                    You will be asked to sign in first. You are not charged automatically.
                </p>
            </td>
        </tr>
    </table>
@endsection
