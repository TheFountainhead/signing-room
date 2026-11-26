<x-signing-room::emails.layout :subject="'Underskrevet af ' . $signedParty->name . ': ' . $envelope->title" :envelope="$envelope">
    <h2>Ny underskrift</h2>

    <p>{{ $signedParty->name }} har underskrevet dokumentet.</p>

    <div class="email-document">
        <div class="email-document-title">{{ $envelope->title }}</div>
        @if($envelope->description)
            <div class="email-document-meta">{{ $envelope->description }}</div>
        @endif
        <div class="email-document-meta">{{ $signedCount }} af {{ $signerCount }} underskrivere har underskrevet</div>
    </div>

    <p>Du får besked, når alle parter har underskrevet.</p>

    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ $adminUrl }}" class="email-btn">Se i adminpanelet</a>
    </p>
</x-signing-room::emails.layout>
