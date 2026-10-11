<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Phones: less padding so the text gets the width. Inline styles below stay for clients that ignore <style>. --}}
    <style>
        @media (max-width: 600px) {
            .email-wrap { padding: 16px 10px !important; }
            .email-card { padding: 20px !important; }
        }
    </style>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6; margin: 0; padding: 0; background: #f3f4f6;">
    <div class="email-wrap" style="max-width: 560px; margin: 0 auto; padding: 32px 24px;">
        <div class="email-card" style="background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e5e7eb;">
            <h2 style="color: #111827; margin-top: 0;">Reset your password</h2>

            <p>Hi {{ $userName }},</p>

            <p>
                We received a request to reset the password for your STIM account. Click the button below to
                choose a new password. This link will expire in 60 minutes.
            </p>

            <p style="margin: 28px 0;">
                <a href="{{ $resetUrl }}" style="background: #16a34a; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-block;">
                    Reset Password
                </a>
            </p>

            <p style="color: #6b7280; font-size: 13px;">
                If you did not request a password reset, you can safely ignore this email &mdash; your password
                will not change.
            </p>

            <p style="margin-top: 28px; color: #6b7280; font-size: 13px;">
                This is an automated message from STIM &mdash; Sorsogon Tourism Information Management.
            </p>
        </div>
    </div>
</body>
</html>
