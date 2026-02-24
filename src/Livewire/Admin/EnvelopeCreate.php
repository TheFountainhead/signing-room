<?php

namespace Fountainhead\SigningRoom\Livewire\Admin;

use Fountainhead\SigningRoom\Services\SigningRoomService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class EnvelopeCreate extends Component
{
    use WithFileUploads;

    public string $title = '';

    public string $description = '';

    public $document;

    public int $expiresInDays = 30;

    public int $reminderInterval = 7;

    public ?int $internalSignerId = null;

    public array $parties = [
        ['name' => '', 'email' => '', 'role' => 'signer', 'signing_round' => 1],
    ];

    protected function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'document' => 'required|file|mimes:pdf|max:10240',
            'expiresInDays' => 'required|integer|min:1|max:365',
            'reminderInterval' => 'required|integer|min:1|max:30',
            'parties' => 'required|array|min:1',
        ];

        foreach ($this->parties as $i => $party) {
            $rules["parties.{$i}.name"] = 'required|string|max:255';
            $rules["parties.{$i}.email"] = 'required|email|max:255';
            $rules["parties.{$i}.role"] = 'required|in:signer,viewer';
            $rules["parties.{$i}.signing_round"] = 'required|integer|min:1';
        }

        return $rules;
    }

    public function addParty(): void
    {
        $maxRound = max(array_column($this->parties, 'signing_round') ?: [1]);

        $this->parties[] = [
            'name' => '',
            'email' => '',
            'role' => 'signer',
            'signing_round' => $maxRound,
        ];
    }

    public function removeParty(int $index): void
    {
        if (count($this->parties) > 1) {
            unset($this->parties[$index]);
            $this->parties = array_values($this->parties);
        }
    }

    private function allParties(): array
    {
        $parties = $this->parties;

        if ($this->internalSignerId) {
            $user = \App\Models\User::find($this->internalSignerId);

            if ($user) {
                $maxRound = max(array_column($parties, 'signing_round') ?: [1]);

                $parties[] = [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'signer',
                    'signing_round' => $maxRound,
                ];
            }
        }

        return $parties;
    }

    public function sendForSigning(): void
    {
        $this->validate();

        try {
            $path = $this->document->store(
                config('signing-room.storage.path', 'signing-room'),
                config('signing-room.storage.disk', 'local'),
            );

            // Validate PDF with Idura before creating the order
            $disk = Storage::disk(config('signing-room.storage.disk', 'local'));
            $pdfBase64 = base64_encode($disk->get($path));

            $service = app(SigningRoomService::class);

            try {
                $validation = $service->validateDocument($pdfBase64);

                if (! $validation['valid']) {
                    $disk->delete($path);
                    $errors = implode(', ', $validation['errors'] ?? ['Ukendt fejl']);
                    $this->addError('document', "PDF-dokumentet er ikke gyldigt: {$errors}");

                    return;
                }
            } catch (\Exception $e) {
                // If validation service is unavailable, proceed anyway
                report($e);
            }

            $envelope = $service->createEnvelope(
                title: $this->title,
                pdfPath: $path,
                parties: $this->allParties(),
                description: $this->description ?: null,
                expiresInDays: $this->expiresInDays,
                reminderInterval: $this->reminderInterval,
                createdBy: auth()->id(),
            );

            // Check if signing service is configured before attempting to send
            $idura = app(\Fountainhead\SigningRoom\Services\IduraSignatureService::class);
            if (! $idura->isConfigured()) {
                session()->flash('warning', 'Dokumentet er gemt som kladde. Digital signering kræver Idura API-nøgler.');
                $this->redirect(route('signing-room.admin.show', $envelope));

                return;
            }

            $service->sendEnvelope($envelope);

            session()->flash('success', 'Dokumentet er sendt til underskrift.');

            $this->redirect(route('signing-room.admin.show', $envelope));
        } catch (\Throwable $e) {
            report($e);
            $this->addError('document', 'Der opstod en fejl: ' . $e->getMessage());
        }
    }

    public function saveDraft(): void
    {
        $this->validate();

        $path = $this->document->store(
            config('signing-room.storage.path', 'signing-room'),
            config('signing-room.storage.disk', 'local'),
        );

        $service = app(SigningRoomService::class);

        $envelope = $service->createEnvelope(
            title: $this->title,
            pdfPath: $path,
            parties: $this->allParties(),
            description: $this->description ?: null,
            expiresInDays: $this->expiresInDays,
            reminderInterval: $this->reminderInterval,
            createdBy: auth()->id(),
        );

        session()->flash('success', 'Kladde gemt.');

        $this->redirect(route('signing-room.admin.show', $envelope));
    }

    public function render()
    {
        return view('signing-room::admin.envelope-create', [
            'admins' => \App\Models\User::orderBy('name')->get(),
        ])->layout('signing-room::layouts.admin', ['title' => 'Nyt dokument']);
    }
}
