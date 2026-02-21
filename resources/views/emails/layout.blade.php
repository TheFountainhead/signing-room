<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Frankston Underskriftrum' }}</title>
    <style>
        body { margin: 0; padding: 0; background: #FFF1E5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .email-wrapper { max-width: 600px; margin: 0 auto; padding: 32px 16px; }
        .email-header { text-align: center; padding: 24px 0; }
        .email-logo { font-family: Georgia, 'Times New Roman', serif; font-size: 1.5rem; font-weight: 700; color: #1A1817; text-decoration: none; }
        .email-body { background: #FFFFFF; border: 1px solid #CCCAC2; border-radius: 8px; padding: 40px 32px; }
        .email-body h2 { font-family: Georgia, 'Times New Roman', serif; font-size: 1.5rem; font-weight: 700; color: #1A1817; margin: 0 0 16px; }
        .email-body p { font-size: 1rem; line-height: 1.6; color: #33302E; margin: 0 0 16px; }
        .email-btn { display: inline-block; background: #1A1817; color: #FFFFFF; padding: 14px 32px; border-radius: 4px; font-size: 1rem; font-weight: 600; text-decoration: none; }
        .email-btn:hover { background: #33302E; }
        .email-document { background: #FFF7F0; border: 1px solid #CCCAC2; border-radius: 8px; padding: 16px 20px; margin: 16px 0; }
        .email-document-title { font-weight: 600; color: #1A1817; font-size: 1rem; }
        .email-document-meta { font-size: 0.875rem; color: #757575; margin-top: 4px; }
        .email-footer { text-align: center; padding: 24px 0; font-size: 0.8rem; color: #757575; }
        .email-divider { border: none; border-top: 1px solid #CCCAC2; margin: 24px 0; }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-header">
            <a href="{{ config('app.url') }}" class="email-logo">Frankston</a>
        </div>

        <div class="email-body">
            {{ $slot }}
        </div>

        <div class="email-footer">
            <p>&copy; {{ date('Y') }} Frankston ApS &middot; sign.frankston.io</p>
            <p style="margin-top: 8px; font-size: 0.75rem;">
                Denne e-mail er sendt fra Frankstons underskriftrum.
                Kontakt afsender hvis du har spørgsmål.
            </p>
        </div>
    </div>
</body>
</html>
