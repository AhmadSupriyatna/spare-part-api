<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 */
class ActivityLogResource extends JsonResource
{
    private const SUBJECT_LABELS = [
        'Branch' => 'Plant',
        'Supplier' => 'Supplier',
        'PartInstallation' => 'Pemasangan Part',
        'PartReplacementRequest' => 'Penggantian Breakdown',
        'PartRepair' => 'Perbaikan Part',
        'PartUnitActionRequest' => 'Aksi Unit (QR)',
        'Budget' => 'Budget',
        'Rack' => 'Rak',
        'Location' => 'Bin',
        'Finding' => 'Temuan (Finding)',
        'MaintenanceDecision' => 'Keputusan Maintenance',
    ];

    private const DISPLAY_FIELDS = ['name', 'code', 'label', 'title', 'slot_label', 'category'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subjectClass = class_basename((string) $this->subject_type);
        $subjectLabel = self::SUBJECT_LABELS[$subjectClass] ?? $subjectClass;

        return [
            'id' => $this->id,
            'event' => $this->event,
            'subject_type' => $subjectClass,
            'subject_label' => $subjectLabel,
            'subject_id' => $this->subject_id,
            'subject_display' => $this->subjectDisplay(),
            'causer_name' => $this->causer?->name,
            'description' => $this->humanDescription($subjectLabel),
            'changes' => $this->changeList(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Falls back to the snapshot captured at log time when the subject no
     * longer exists to read a live field from (most commonly: it was
     * deleted, but the same gap applies to any activity whose subject
     * happened to get removed later) — better than just showing "#13".
     */
    private function subjectDisplay(): ?string
    {
        $snapshot = (array) ($this->attribute_changes['attributes'] ?? $this->attribute_changes['old'] ?? []);

        foreach (self::DISPLAY_FIELDS as $field) {
            $value = $this->subject?->{$field} ?? $snapshot[$field] ?? null;
            if ($value) {
                return (string) $value;
            }
        }

        return null;
    }

    private function humanDescription(string $subjectLabel): string
    {
        $what = match ($this->event) {
            'created' => 'Menambahkan',
            'updated' => 'Mengubah',
            'deleted' => 'Menghapus',
            default => (string) $this->event,
        };
        $display = $this->subjectDisplay();
        $target = $display ? "{$subjectLabel} \"{$display}\"" : "{$subjectLabel} #{$this->subject_id}";

        return "{$what} {$target}";
    }

    /**
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    private function changeList(): array
    {
        $old = (array) ($this->attribute_changes['old'] ?? []);
        $attributes = (array) ($this->attribute_changes['attributes'] ?? []);

        if ($this->event === 'created') {
            return collect($attributes)
                ->map(fn ($value, $field) => ['field' => $field, 'old' => null, 'new' => $value])
                ->values()
                ->all();
        }

        if ($this->event === 'deleted') {
            return collect($old)
                ->map(fn ($value, $field) => ['field' => $field, 'old' => $value, 'new' => null])
                ->values()
                ->all();
        }

        return collect($attributes)
            ->map(fn ($value, $field) => [
                'field' => $field,
                'old' => $old[$field] ?? null,
                'new' => $value,
            ])
            ->values()
            ->all();
    }
}
