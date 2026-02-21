<x-signing-room::emails.layout :subject="'Påmindelse: Dokument venter på din underskrift'">
    <h2>Påmindelse om underskrift</h2>

    <p>Hej {{ $party->name }},</p>

    <p>Du har et dokument der venter på din underskrift.</p>

    <div class="email-document">
        <div class="email-document-title">{{ $envelope->title }}</div>
        @if($envelope->expires_at)
            @php $daysLeft = (int) now()->diffInDays($envelope->expires_at, false); @endphp
            <div class="email-document-meta">
                Deadline: {{ $envelope->expires_at->format('j. F Y') }}
                @if($daysLeft > 0)
                    ({{ $daysLeft }} {{ $daysLeft === 1 ? 'dag' : 'dage' }} tilbage)
                @endif
            </div>
        @endif
    </div>

    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ $signingUrl }}" class="email-btn">Se dokument og underskriv</a>
    </p>

    <hr class="email-divider">

    <p style="font-size: 0.875rem; color: #757575;">
        Du modtager denne påmindelse fordi dokumentet endnu ikke er underskrevet.
        Hvis du har spørgsmål, bedes du kontakte afsenderen.
    </p>
</x-signing-room::emails.layout>
