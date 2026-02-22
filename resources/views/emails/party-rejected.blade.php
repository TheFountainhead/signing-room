<x-signing-room::emails.layout :subject="'Afvist: ' . $envelope->title">
    <h2>Dokument afvist</h2>

    <p>En underskriver har afvist dokumentet.</p>

    <div class="email-document">
        <div class="email-document-title">{{ $envelope->title }}</div>
        @if($envelope->description)
            <div class="email-document-meta">{{ $envelope->description }}</div>
        @endif
    </div>

    <div style="background: #FFEBEE; border: 1px solid #FFCDD2; border-radius: 8px; padding: 16px 20px; margin: 16px 0;">
        <div style="font-weight: 600; color: #CC0000; font-size: 1rem;">{{ $rejectedParty->name }}</div>
        <div style="font-size: 0.875rem; color: #CC0000; margin-top: 4px;">{{ $rejectedParty->email }}</div>
        @if($rejectedParty->rejection_reason)
            <div style="font-size: 0.9rem; color: #33302E; margin-top: 12px; font-style: italic;">
                "{{ $rejectedParty->rejection_reason }}"
            </div>
        @endif
    </div>

    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ $adminUrl }}" class="email-btn">Se i adminpanelet</a>
    </p>
</x-signing-room::emails.layout>
