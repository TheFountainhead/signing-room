<x-signing-room::emails.layout :subject="'Underskrevet: ' . $envelope->title">
    <h2>Dokument underskrevet</h2>

    <p>Hej {{ $party->name }},</p>

    <p>Alle parter har nu underskrevet dokumentet. Det signerede dokument er klar til download.</p>

    <div class="email-document">
        <div class="email-document-title">{{ $envelope->title }}</div>
        @if($envelope->description)
            <div class="email-document-meta">{{ $envelope->description }}</div>
        @endif
        <div class="email-document-meta">Underskrevet {{ $envelope->completed_at->format('j. F Y') }}</div>
    </div>

    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ $downloadUrl }}" class="email-btn">Download signeret dokument</a>
    </p>

    <hr class="email-divider">

    <p style="font-size: 0.875rem; color: #757575;">
        Det signerede dokument indeholder alle underskrifter og overholder kravene til avancerede elektroniske signaturer (AES) i henhold til eIDAS-forordningen.
    </p>
</x-signing-room::emails.layout>
