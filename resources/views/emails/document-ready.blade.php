<x-signing-room::emails.layout :subject="'Dokument til underskrift: ' . $envelope->title">
    <h2>Dokument til underskrift</h2>

    <p>Hej {{ $party->name }},</p>

    <p>Frankston har sendt dig et dokument til digital underskrift.</p>

    <div class="email-document">
        <div class="email-document-title">{{ $envelope->title }}</div>
        @if($envelope->description)
            <div class="email-document-meta">{{ $envelope->description }}</div>
        @endif
        @if($envelope->expires_at)
            <div class="email-document-meta">Deadline: {{ $envelope->expires_at->format('j. F Y') }}</div>
        @endif
    </div>

    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ $signingUrl }}" class="email-btn">Se dokument og underskriv</a>
    </p>

    <hr class="email-divider">

    <p style="font-size: 0.875rem; color: #757575;">
        Du bliver bedt om at identificere dig med MitID for at underskrive dokumentet.
        Hvis du ikke forventede denne e-mail, kan du ignorere den.
    </p>
</x-signing-room::emails.layout>
