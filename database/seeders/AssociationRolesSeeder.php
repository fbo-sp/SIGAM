<?php

namespace Database\Seeders;

use App\Support\Access\AssociationRoleCatalog;
use Illuminate\Database\Seeder;

class AssociationRolesSeeder extends Seeder
{
    public function run(): void
    {
        AssociationRoleCatalog::ensure();
    }
}
