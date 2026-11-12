<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'role', 'manager_ids'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    private ?array $pendingManagers = null;

    public function roleDefinition()
    {
        return $this->belongsTo(Role::class, 'role', 'code');
    }

    public function clientProfile()
    {
        return $this->hasOne(ClientProfile::class, 'user_id');
    }

    public function managers()
    {
        return $this->belongsToMany(self::class, 'client_manager_assignments', 'client_id', 'manager_id')->withTimestamps();
    }

    public function assignedClients()
    {
        return $this->belongsToMany(self::class, 'client_manager_assignments', 'manager_id', 'client_id')->withTimestamps();
    }

    public function getManagerIdsAttribute(): array
    {
        return $this->pendingManagers ?? $this->managers->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function setManagerIdsAttribute(?array $ids): void
    {
        $this->pendingManagers = array_values(array_unique(array_map('intval', $ids ?? [])));
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            if ($this->exists && $this->pendingManagers !== null) {
                self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            }
            if ($this->exists && $this->isDirty('role')) {
                throw new \InvalidArgumentException('Role changes require an explicit reassignment workflow.');
            }
            $new = ! $this->exists;
            $saved = parent::save($options);
            if (! $saved) {
                return false;
            }
            if ($this->role === 'client') {
                ClientProfile::firstOrCreate(['user_id' => $this->id]);
            }
            if ($this->pendingManagers !== null) {
                if (($this->role !== 'client' && $this->pendingManagers) || self::where('role', 'manager')->whereIn('id', $this->pendingManagers)->count() !== count($this->pendingManagers)) {
                    throw new \InvalidArgumentException('Only managers can be assigned to client profiles.');
                }
                $this->managers()->sync($this->pendingManagers);
                $this->pendingManagers = null;
                $this->unsetRelation('managers');
                DB::table('audit_events')->insert(['actor_id' => auth()->id(), 'subject_user_id' => $this->id, 'action' => 'client.assignments_updated', 'occurred_at' => now()->utc()]);
            }
            if ($new) {
                DB::table('audit_events')->insert(['actor_id' => auth()->id(), 'subject_user_id' => $this->id, 'action' => 'user.created', 'occurred_at' => now()->utc()]);
            }

            return true;
        });
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
