<?php

namespace App\Support\Tenancy;

use App\Models\Association;

class AssociationContext
{
    public function __construct(private ?Association $association = null) {}

    public function set(?Association $association): void
    {
        $this->association = $association;
    }

    public function association(): ?Association
    {
        return $this->association;
    }

    public function id(): ?int
    {
        return $this->association?->getKey();
    }
}
