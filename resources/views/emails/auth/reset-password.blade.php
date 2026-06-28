<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your SplitEasy password</title>
</head>
<body style="margin: 0; padding: 0; background: #f6f1ea; color: #1f2933; font-family: Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #f6f1ea; padding: 32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 560px; background: #ffffff; border-radius: 14px; overflow: hidden;">
                    <tr>
                        <td style="padding: 28px 28px 12px;">
                            <p style="margin: 0 0 8px; color: #f97316; font-size: 13px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;">SplitEasy</p>
                            <h1 style="margin: 0; color: #111827; font-size: 26px; line-height: 1.25;">Reset your password</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 28px 0;">
                            <p style="margin: 0 0 16px; color: #374151; font-size: 16px; line-height: 1.55;">
                                Hi {{ $user->name }},
                            </p>
                            <p style="margin: 0 0 20px; color: #374151; font-size: 16px; line-height: 1.55;">
                                We received a request to reset your SplitEasy password. Use the button below to choose a new password.
                            </p>
                            <p style="margin: 0 0 24px;">
                                <a href="{{ $resetUrl }}" style="display: inline-block; background: #f97316; color: #ffffff; border-radius: 10px; font-size: 15px; font-weight: 700; padding: 13px 18px; text-decoration: none;">
                                    Reset password
                                </a>
                            </p>
                            <p style="margin: 0 0 16px; color: #6b7280; font-size: 14px; line-height: 1.55;">
                                This link expires in {{ $expiresInMinutes }} minutes. If you did not request a password reset, you can safely ignore this email.
                            </p>
                            <p style="margin: 0 0 8px; color: #6b7280; font-size: 14px; line-height: 1.55;">
                                If the button does not work, copy and paste this link into your browser:
                            </p>
                            <p style="margin: 0 0 24px; color: #4b5563; font-size: 13px; line-height: 1.55; word-break: break-all;">
                                {{ $resetUrl }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background: #fff7ed; padding: 18px 28px; color: #9a3412; font-size: 13px; line-height: 1.5;">
                            SplitEasy keeps shared expenses clear and easy to settle.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
