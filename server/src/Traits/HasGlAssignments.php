<?php

namespace Fleetbase\Ledger\Traits;

use Fleetbase\Ledger\Models\GlAssignment;

trait HasGlAssignments
{
    public function glAssignments()
    {
        return $this->morphMany(GlAssignment::class, 'assignable', 'assignable_type', 'assignable_uuid');
    }

    public function primaryGlAccount()
    {
        return $this->glAssignments()->first()?->glAccount;
    }
}
