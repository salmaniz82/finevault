<?php

namespace App\Models;

use App\Services\DomainRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Shared identity and lifecycle index. `data` is a view DTO, never a database column. */
class PortalEntry extends Model
{
    protected $table = 'portal_entries';

    protected $fillable = ['client_id', 'kind', 'data'];

    protected ?array $pendingData = null;

    public function holding()
    {
        return $this->hasOne(Holding::class, 'id');
    }

    public function documentVersion()
    {
        return $this->hasOne(DocumentVersion::class, 'id');
    }

    public function deal()
    {
        return $this->hasOne(Deal::class, 'id');
    }

    public function serviceRequest()
    {
        return $this->hasOne(ServiceRequest::class, 'id');
    }

    public function profileChange()
    {
        return $this->hasOne(ProfileChange::class, 'id');
    }

    public function acknowledgement()
    {
        return $this->hasOne(DocumentAcknowledgement::class, 'id');
    }

    public function directSignature()
    {
        return $this->hasOne(DirectSignatureRecord::class, 'id');
    }

    public function getDataAttribute(): array
    {
        return $this->pendingData ?? app(DomainRecords::class)->read($this);
    }

    public function setDataAttribute(array $value): void
    {
        $this->pendingData = $value;
    }

    /** Transitional query vocabulary for shared screens; always targets real relational columns. */
    public function scopeWhereField($query, string $field, $value)
    {
        if ($field === 'status') {
            return $query->where('status', $value);
        }
        $relation = match ($field) {
            'category', 'visibility', 'series' => 'documentVersion.document',
            'title', 'uploaded_by_id', 'replaces_id' => 'documentVersion',
            'token' => 'directSignature',
            default => throw new \InvalidArgumentException('Unsupported relational filter: '.$field),
        };

        return $query->whereHas($relation, fn ($q) => $q->where($field, $value));
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $new = ! $this->exists;
            if (! $new && ($this->isDirty('client_id') || $this->isDirty('kind'))) {
                throw new \InvalidArgumentException('Record identity cannot change.');
            }
            $data = $this->pendingData;
            if ($new && $data === null) {
                throw new \InvalidArgumentException('A new entry requires its typed domain details.');
            }
            $before = $this->getOriginal('status');
            if ($data !== null) {
                app(DomainRecords::class)->validate($this->kind, $data);
                $this->status = $data['status'] ?? null;
            }
            $saved = parent::save($options);
            if ($saved && $data !== null) {
                app(DomainRecords::class)->write($this, $data);
            }
            if ($saved && ($new || $before !== $this->status)) {
                DB::table('audit_events')->insert([
                    'actor_id' => auth()->id(), 'entry_id' => $this->id, 'action' => $new ? 'entry.created' : 'entry.status_changed',
                    'previous_status' => $before, 'new_status' => $this->status, 'occurred_at' => now()->utc(),
                ]);
            }
            $this->pendingData = null;
            $this->unsetRelations();

            return $saved;
        });
    }
}
