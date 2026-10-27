<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Global scope tenant: aktif hanya bila organisasi aktif sudah dipasang di container
 * (middleware console / ValidateApiKey). Job dan command tidak ter-scope kecuali dipasang manual.
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $q) {
            if (app()->bound('org.current')) {
                $q->where($q->getModel()->getTable().'.organization_id', app('org.current')->id);
            }
        });

        static::creating(function ($model) {
            if (! $model->organization_id && app()->bound('org.current')) {
                $model->organization_id = app('org.current')->id;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
