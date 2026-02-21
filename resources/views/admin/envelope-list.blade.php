<div>
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
        <h1>Underskriftrum</h1>
        <a href="{{ route('signing-room.admin.create') }}" class="btn-primary">
            + Nyt dokument til underskrift
        </a>
    </div>

    {{-- Filters --}}
    <div style="display: flex; gap: 16px; margin-bottom: 24px;">
        <input wire:model.live.debounce.300ms="search"
               type="text"
               placeholder="Søg i dokumenter..."
               class="form-input"
               style="flex: 1;">
        <select wire:model.live="statusFilter" class="form-input" style="width: 200px;">
            <option value="">Alle statuser</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    {{-- Table --}}
    <div class="card" style="padding: 0; overflow: hidden;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Titel</th>
                    <th>Underskrivere</th>
                    <th>Status</th>
                    <th>Oprettet</th>
                </tr>
            </thead>
            <tbody>
                @forelse($envelopes as $envelope)
                    <tr onclick="window.location='{{ route('signing-room.admin.show', $envelope) }}'">
                        <td style="font-weight: 500; color: var(--ft-black);">
                            {{ $envelope->title }}
                        </td>
                        <td>
                            {{ $envelope->signed_count }}/{{ $envelope->parties_count }} signeret
                        </td>
                        <td>
                            @php
                                $badgeClass = match($envelope->status) {
                                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Completed => 'badge-green',
                                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Sent,
                                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::PartiallySigned => 'badge-blue',
                                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Expired,
                                    \Fountainhead\SigningRoom\Enums\EnvelopeStatus::Cancelled => 'badge-red',
                                    default => 'badge-gray',
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ $envelope->status->label() }}</span>
                        </td>
                        <td style="color: var(--ft-grey);">
                            {{ $envelope->created_at->format('j. M Y') }}
                        </td>
                    </tr>
                @empty
                    <tr style="cursor: default;">
                        <td colspan="4" style="padding: 48px; text-align: center; color: var(--ft-grey);">
                            Ingen dokumenter endnu
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 24px;">
        {{ $envelopes->links() }}
    </div>
</div>
