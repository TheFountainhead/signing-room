<?php

namespace Fountainhead\SigningRoom\Livewire\Admin;

use Fountainhead\SigningRoom\Enums\EnvelopeStatus;
use Fountainhead\SigningRoom\Models\SigningEnvelope;
use Livewire\Component;
use Livewire\WithPagination;

class EnvelopeList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $envelopes = SigningEnvelope::query()
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->withCount([
                'parties',
                'parties as signed_count' => fn ($q) => $q->where('status', 'signed'),
            ])
            ->latest()
            ->paginate(20);

        return view('signing-room::admin.envelope-list', [
            'envelopes' => $envelopes,
            'statuses' => EnvelopeStatus::cases(),
        ])->layout('signing-room::layouts.admin', ['title' => 'Alle dokumenter']);
    }
}
