<?php

namespace App\Http\Resources\V1;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'entry_date' => $this->entry_date->toDateString(),
            'description' => $this->description,
            // Morph aliases ("invoice") are enforced in AppServiceProvider, so no class names leak.
            'source' => $this->source_type === null ? null : [
                'type' => $this->source_type,
                'id' => $this->source_id,
            ],
            'reversal_of_id' => $this->reversal_of_id,
            'reversed_by_id' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->id),
            'total' => $this->whenLoaded('lines', fn () => $this->totalDebits()),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            'posted_at' => $this->posted_at->toIso8601String(),
        ];
    }
}
