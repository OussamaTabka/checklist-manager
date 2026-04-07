<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Invitation</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.6;">
    <p>Hello {{ $user->name }},</p>

    <p>
        You have been invited{{ $inviterName ? ' by ' . $inviterName : '' }} to join Checklist Manager
        as <strong>{{ $role }}</strong>.
    </p>

    <p>To activate your account, set your password using this secure one-time link:</p>

    <p>
        <a href="{{ $setupLink }}" style="display:inline-block;padding:10px 14px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:6px;">
            Set up my password
        </a>
    </p>

    <p>Or copy and paste this URL into your browser:</p>
    <p><a href="{{ $setupLink }}">{{ $setupLink }}</a></p>

    <p>This link expires on {{ $expiresAt }}.</p>

    <p>If you did not expect this invitation, you can safely ignore this email.</p>
</body>
</html>
