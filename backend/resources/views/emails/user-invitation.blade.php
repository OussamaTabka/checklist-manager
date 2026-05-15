<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation to IntelliTest</title>
</head>
<body style="margin:0; padding:24px; background:#f3f6fb; font-family:Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px; background:#ffffff; border:1px solid #dbe4ef; border-radius:18px;">
                    <tr>
                        <td style="padding:28px 28px 20px; background:linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%); border-radius:18px 18px 0 0;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td valign="middle" style="padding-right:14px;">
                                        <div style="width:54px; height:54px; border-radius:14px; background:linear-gradient(180deg, #ffffff 0%, #e9f1ff 100%); border:1px solid #cfe0ff; text-align:center; line-height:54px; font-size:24px; font-weight:700; color:#1f6feb;">
                                            IT
                                        </div>
                                    </td>
                                    <td valign="middle">
                                        <div style="font-size:26px; line-height:1; font-weight:800; letter-spacing:-0.04em; color:#0f172a;">
                                            Intelli<span style="color:#1f6feb;">Test</span>
                                        </div>
                                        <div style="margin-top:6px; font-size:11px; line-height:1.4; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:#64748b;">
                                            QA Workflow Studio
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top:22px; font-size:13px; line-height:1.7; color:#475569;">
                                Secure invitation to join your IntelliTest workspace
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 18px; font-size:25px; line-height:1.2; color:#0f172a;">
                                Hello {{ $user->name }},
                            </h1>

                            <p style="margin:0 0 14px; font-size:16px; line-height:1.7; color:#334155;">
                                You have been invited{{ $inviterName ? ' by ' . $inviterName : '' }} to join
                                <strong>IntelliTest</strong> as <strong>{{ $role }}</strong>.
                            </p>

                            <p style="margin:0 0 22px; font-size:16px; line-height:1.7; color:#334155;">
                                To activate your account, choose your password using this secure one-time link:
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:10px; background:linear-gradient(180deg, #1f6feb 0%, #174fbb 100%);">
                                        <a
                                            href="{{ $setupLink }}"
                                            style="display:inline-block; padding:14px 20px; color:#ffffff; text-decoration:none; font-size:15px; font-weight:700; border-radius:10px;"
                                        >
                                            Set up my password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin:0 0 22px; padding:16px 18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <div style="margin:0 0 8px; font-size:13px; line-height:1.5; font-weight:700; color:#475569;">
                                    Alternative link
                                </div>
                                <a href="{{ $setupLink }}" style="font-size:14px; line-height:1.7; color:#1d4ed8; word-break:break-all;">
                                    {{ $setupLink }}
                                </a>
                            </div>

                            <p style="margin:0 0 10px; font-size:15px; line-height:1.7; color:#334155;">
                                This link expires on <strong>{{ $expiresAt }}</strong>.
                            </p>

                            <p style="margin:0; font-size:15px; line-height:1.7; color:#334155;">
                                If you did not expect this invitation, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 28px 24px; font-size:13px; line-height:1.7; color:#64748b;">
                            The IntelliTest team
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
