<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6; margin: 0; padding: 0; background: #f3f4f6;">
    <div style="max-width: 560px; margin: 0 auto; padding: 32px 24px;">
        <div style="background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e5e7eb; border-top: 4px solid #d97706;">
            <h2 style="color: #111827; margin-top: 0;">Notice regarding visitor feedback</h2>

            <p>Dear {{ $submission->user->name }},</p>

            <p>
                We are writing regarding your listing "<strong>{{ $submission->business_name }}</strong>" on the
                STIM tourism platform. Our team has observed a continued pattern of negative reviews from visitors,
                indicating that the quality of service or experience may not be meeting the standards expected of
                businesses featured on the platform.
            </p>

            <p>
                To maintain the trust of travelers visiting Sorsogon, we kindly ask that you review recent visitor
                feedback and take appropriate corrective action.
            </p>

            <div style="background: #fffbeb; border-left: 4px solid #d97706; padding: 12px 16px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0;">
                    <strong>If negative reviews persist, your business will be removed from the system after
                    {{ $submission->warning_deadline->format('F j, Y') }}.</strong>
                </p>
            </div>

            @if($submission->warning_notes)
                <div style="background: #f9fafb; border-left: 4px solid #6b7280; padding: 12px 16px; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; font-weight: 600; font-size: 14px;">Additional note from the administrator</p>
                    <p style="margin: 6px 0 0;">{{ $submission->warning_notes }}</p>
                </div>
            @endif

            <p>
                We value your partnership and are confident these concerns can be addressed. If you believe this notice
                was sent in error or would like to discuss it, please contact the Sorsogon Provincial Tourism Office.
            </p>

            <p>Respectfully,<br>STIM Administration</p>

            <p style="margin-top: 28px; color: #6b7280; font-size: 13px;">
                This is an automated message from STIM &mdash; Sorsogon Tourism Information Management.
            </p>
        </div>
    </div>
</body>
</html>
