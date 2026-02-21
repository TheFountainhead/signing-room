<div>
    <div class="fade-up" style="margin-bottom: 40px;">
        <h1>Mine dokumenter</h1>
        <p style="color: var(--ft-grey); margin-top: 8px;">Oversigt over dokumenter der afventer din underskrift.</p>
    </div>

    {{-- Pending Documents --}}
    @if($pending->isNotEmpty())
        <div class="fade-up" style="margin-bottom: 48px;">
            <h2 style="font-size: 1.5rem; margin-bottom: 20px;">Afventer underskrift</h2>
            <div style="display: grid; gap: 16px;">
                @foreach($pending as $party)
                    <div class="card card-hover" style="display: flex; align-items: center; justify-content: space-between; padding: 24px 32px;">
                        <div>
                            <h3 style="font-size: 1.125rem; margin-bottom: 4px;">{{ $party->envelope->title }}</h3>
                            <div style="font-size: 0.9rem; color: var(--ft-grey);">
                                Modtaget {{ $party->notified_at?->format('j. M Y') ?? $party->created_at->format('j. M Y') }}
                                @if($party->envelope->expires_at)
                                    &middot; Deadline: {{ $party->envelope->expires_at->format('j. M Y') }}
                                    @php
                                        $daysLeft = (int) now()->diffInDays($party->envelope->expires_at, false);
                                    @endphp
                                    @if($daysLeft > 0 && $daysLeft <= 7)
                                        <span style="color: var(--ft-red); font-weight: 600;">({{ $daysLeft }} dage tilbage)</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                        <a href="{{ route('signing-room.portal.sign', $party) }}" class="btn-primary" style="white-space: nowrap;">
                            Åbn dokument
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Signed Documents --}}
    @if($signed->isNotEmpty())
        <div class="fade-up" style="margin-bottom: 48px;">
            <h2 style="font-size: 1.5rem; margin-bottom: 20px;">Underskrevet</h2>
            <div style="display: grid; gap: 16px;">
                @foreach($signed as $party)
                    <div class="card" style="display: flex; align-items: center; justify-content: space-between; padding: 24px 32px;">
                        <div>
                            <h3 style="font-size: 1.125rem; margin-bottom: 4px;">{{ $party->envelope->title }}</h3>
                            <div style="font-size: 0.9rem; color: var(--ft-grey);">
                                Underskrevet {{ $party->signed_at?->format('j. M Y') }}
                                <span class="badge badge-green" style="margin-left: 8px;">Signeret</span>
                            </div>
                        </div>
                        @if($party->envelope->signed_document)
                            <a href="{{ route('signing-room.portal.sign', $party) }}" class="btn-outline" style="white-space: nowrap;">
                                Se dokument
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Other (rejected/error) --}}
    @if($other->isNotEmpty())
        <div class="fade-up" style="margin-bottom: 48px;">
            <h2 style="font-size: 1.5rem; margin-bottom: 20px;">Øvrige</h2>
            <div style="display: grid; gap: 16px;">
                @foreach($other as $party)
                    <div class="card" style="display: flex; align-items: center; justify-content: space-between; padding: 24px 32px; opacity: 0.7;">
                        <div>
                            <h3 style="font-size: 1.125rem; margin-bottom: 4px;">{{ $party->envelope->title }}</h3>
                            <div style="font-size: 0.9rem; color: var(--ft-grey);">
                                @if($party->status === \Fountainhead\SigningRoom\Enums\SigningPartyStatus::Rejected)
                                    Afvist {{ $party->rejected_at?->format('j. M Y') }}
                                    <span class="badge badge-red" style="margin-left: 8px;">Afvist</span>
                                @else
                                    <span class="badge badge-red" style="margin-left: 8px;">Fejl</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Empty State --}}
    @if($pending->isEmpty() && $signed->isEmpty() && $other->isEmpty())
        <div class="card fade-up" style="text-align: center; padding: 64px;">
            <div style="font-size: 3rem; margin-bottom: 16px;">&#128196;</div>
            <h2 style="font-size: 1.5rem; margin-bottom: 8px;">Ingen dokumenter</h2>
            <p style="color: var(--ft-grey);">Der er ingen dokumenter der afventer din underskrift lige nu.</p>
        </div>
    @endif
</div>
