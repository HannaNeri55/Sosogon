<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6; margin: 0; padding: 0; background: #f3f4f6;">
    <div style="max-width: 560px; margin: 0 auto; padding: 32px 24px;">
        <div style="background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e5e7eb;">
            <h2 style="color: #111827; margin-top: 0;">
                @if($submission->status === 'approved')
                    Your submission has been approved
                @elseif($submission->status === 'removed')
                    Your listing has been removed
                @else
                    Your submission needs changes
                @endif
            </h2>

            <p>Hi {{ $submission->user->name }},</p>

            @if($submission->status === 'approved')
                <p>
                    Good news! Your business submission "<strong>{{ $submission->business_name }}</strong>"
                    has been reviewed and approved. It is now live on the STIM tourism platform.
                </p>
            @elseif($submission->status === 'removed')
                <p>
                    We regret to inform you that your business "<strong>{{ $submission->business_name }}</strong>"
                    has been removed from the STIM tourism platform. This decision was made because the concerns
                    raised in visitor reviews were not resolved within the notice period.
                </p>
            @else
                <p>
                    Your business submission "<strong>{{ $submission->business_name }}</strong>"
                    was reviewed and was not approved at this time.
                </p>
            @endif

            @if($submission->admin_notes)
                <div style="background: #f9fafb; border-left: 4px solid {{ $submission->status === 'approved' ? '#16a34a' : '#dc2626' }}; padding: 12px 16px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; font-weight: 600; font-size: 14px;">Message from the admin</p>
                    <p style="margin: 6px 0 0;">{{ $submission->admin_notes }}</p>
                </div>
            @endif

            @if($submission->status === 'rejected')
                <p>
                    You can update your submission with the requested changes and resubmit it from your
                    business dashboard. It will need to go through admin approval again before it goes live.
                </p>
            @elseif($submission->status === 'removed')
                <p>
                    Once the issues have been addressed, you may update your submission and resubmit it from your
                    business dashboard. It will need to go through admin review again before it can be listed.
                    For questions, please contact the Sorsogon Provincial Tourism Office.
                </p>
            @endif

            <p style="margin-top: 28px; color: #6b7280; font-size: 13px;">
                This is an automated message from STIM &mdash; Sorsogon Tourism Information Management.
            </p>
        </div>
    </div>
</body>
</html>
