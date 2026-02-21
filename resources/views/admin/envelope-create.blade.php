<div>
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px;">
        <h1>Nyt dokument til underskrift</h1>
    </div>

    @if(session('error'))
        <div style="background: #FFEBEE; color: var(--ft-red); padding: 16px; border-radius: 8px; margin-bottom: 24px;">
            {{ session('error') }}
        </div>
    @endif

    <form wire:submit="sendForSigning">
        {{-- Document Details --}}
        <div class="card" style="margin-bottom: 24px;">
            <h2 style="font-size: 1.25rem; margin-bottom: 20px;">Dokumentoplysninger</h2>

            <div class="form-group">
                <label class="form-label" for="title">Titel *</label>
                <input wire:model="title" type="text" id="title" class="form-input" placeholder="F.eks. Forvaltningsaftale">
                @error('title') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="description">Beskrivelse</label>
                <textarea wire:model="description" id="description" class="form-input" rows="3" placeholder="Valgfri beskrivelse af dokumentet..."></textarea>
                @error('description') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="document">PDF-dokument *</label>
                <input wire:model="document" type="file" id="document" accept=".pdf"
                       class="form-input" style="padding: 8px 16px;">
                @error('document') <div class="form-error">{{ $message }}</div> @enderror
                <div style="font-size: 0.8rem; color: var(--ft-grey); margin-top: 4px;">Maks 10 MB, kun PDF</div>
            </div>

            <div style="display: flex; gap: 16px;">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label" for="expiresInDays">Udløber om (dage)</label>
                    <input wire:model="expiresInDays" type="number" id="expiresInDays" class="form-input" min="1" max="365">
                    @error('expiresInDays') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label" for="reminderInterval">Påmindelsesinterval (dage)</label>
                    <input wire:model="reminderInterval" type="number" id="reminderInterval" class="form-input" min="1" max="30">
                    @error('reminderInterval') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- Parties --}}
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                <h2 style="font-size: 1.25rem;">Underskrivere</h2>
                <button type="button" wire:click="addParty" class="btn-outline btn-sm">
                    + Tilføj underskriver
                </button>
            </div>

            @foreach($parties as $index => $party)
                <div style="padding: 16px; border: 1px solid var(--ft-border); border-radius: 8px; margin-bottom: 12px; background: var(--ft-pink-light);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <span style="font-weight: 600; color: var(--ft-dark); font-size: 0.9rem;">
                            Underskriver {{ $index + 1 }}
                        </span>
                        @if(count($parties) > 1)
                            <button type="button" wire:click="removeParty({{ $index }})"
                                    style="background: none; border: none; color: var(--ft-red); cursor: pointer; font-size: 0.875rem; font-weight: 600;">
                                Fjern
                            </button>
                        @endif
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">Navn *</label>
                            <input wire:model="parties.{{ $index }}.name" type="text" class="form-input" placeholder="Fulde navn">
                            @error("parties.{$index}.name") <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">E-mail *</label>
                            <input wire:model="parties.{{ $index }}.email" type="email" class="form-input" placeholder="email@eksempel.dk">
                            @error("parties.{$index}.email") <div class="form-error">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">Rolle</label>
                            <select wire:model="parties.{{ $index }}.role" class="form-input">
                                <option value="signer">Underskriver</option>
                                <option value="viewer">Modtager (kopi)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">Underskriftsrunde</label>
                            <input wire:model="parties.{{ $index }}.signing_round" type="number" class="form-input" min="1">
                            <div style="font-size: 0.75rem; color: var(--ft-grey); margin-top: 2px;">Runde 1 underskriver først</div>
                        </div>
                    </div>
                </div>
            @endforeach
            @error('parties') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        {{-- Actions --}}
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <a href="{{ route('signing-room.admin.index') }}" class="btn-outline">Annuller</a>
            <button type="button" wire:click="saveDraft" class="btn-outline">
                Gem som kladde
            </button>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendForSigning">Send til underskrift</span>
                <span wire:loading wire:target="sendForSigning">Sender...</span>
            </button>
        </div>
    </form>
</div>
