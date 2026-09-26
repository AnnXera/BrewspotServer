@extends('emails.layouts.billing')

@section('title', 'Your Subscription Has Expired')

@section('content')
    <p style="font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 16px; color: #4a382d;">
        Dear {{ $ownerName }},
    </p>
    <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px; color: #4a382d;">
        Your <strong>{{ $planName }}</strong> subscription ended on <strong>{{ $endedOn }}</strong>. Management features that depend on your plan are now restricted until you subscribe again.
        @if ($renewPlanName !== null && $renewPlanName !== $planName)
            You had scheduled a switch to the <strong>{{ $renewPlanName }}</strong> &mdash; paying for it now restores your access on that plan.
        @endif
    </p>

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #faf8f5; border-left: 3px solid #9c7356; border-radius: 4px; margin-bottom: 24px;">
        <tr>
            <td style="padding: 14px 18px; font-size: 13px; line-height: 1.5; color: #6e5849;">
                <strong>Your data is safe.</strong> Your cafe, branches and menu are kept as they are and become fully available again as soon as payment is completed.
            </td>
        </tr>
    </table>

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 24px;">
        <tr>
            <td align="center">
                <a href="{{ $renewUrl }}" style="display: inline-block; background-color: #2b1810; color: #f5ede4; font-size: 14px; font-weight: 600; letter-spacing: 0.3px; text-decoration: none; padding: 13px 34px; border-radius: 5px;">
                    {{ $renewPlanName === null ? 'Choose a Plan' : 'Renew with ' . $renewPlanName }}
                </a>
            </td>
        </tr>
    </table>
@endsection
