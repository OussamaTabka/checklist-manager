<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Initialisation de votre mot de passe - IntelliTest</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.6; background: #f8fafc; margin: 0; padding: 24px;">
    <div style="max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e5e7eb;">
        <h1 style="margin-top: 0; color: #0f172a; font-size: 24px;">Bonjour {{ $user->name }},</h1>

        <p>Un compte a été créé pour vous sur la plateforme IntelliTest.</p>

        @if($inviterName)
            <p>Ce compte vous a été attribué par <strong>{{ $inviterName }}</strong>.</p>
        @endif

        <p>Veuillez cliquer sur le lien ci-dessous pour initialiser votre mot de passe :</p>

        <p style="margin: 24px 0;">
            <a href="{{ $setupLink }}" style="display:inline-block;padding:12px 18px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">
                Créer mon mot de passe
            </a>
        </p>

        <p>Ce lien est temporaire et expirera pour des raisons de sécurité le <strong>{{ $expiresAt }}</strong>.</p>

        <p>Si le bouton ne fonctionne pas, vous pouvez copier ce lien dans votre navigateur :</p>
        <p><a href="{{ $setupLink }}">{{ $setupLink }}</a></p>

        <p style="margin-top: 32px;">Cordialement,<br>L’équipe IntelliTest</p>
    </div>
</body>
</html>
