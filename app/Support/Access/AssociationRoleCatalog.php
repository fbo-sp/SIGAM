<?php

namespace App\Support\Access;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * Perfis associativos fixos atribuídos pela tela de usuários.
 * Alterar estas listas exige atualizar RBAC.md, identity-access.md e os testes.
 */
final class AssociationRoleCatalog
{
    public const MANAGER = 'association_manager';

    public const FINANCE = 'association_finance';

    public const PORTAL = 'portal_user';

    private const FINANCE_PERMISSIONS = [
        'association.view', 'dojo.view', 'person.view', 'student.view',
        'finance.view', 'finance.manage', 'student_plan.manage', 'discount.manage', 'discount.approve',
        'gratuity.request', 'gratuity.approve_association', 'charge.view', 'charge.generate',
        'payment.manage', 'payment.reverse', 'finance.report', 'dashboard.association',
    ];

    /**
     * Permissões pessoais: dão acesso apenas aos próprios dados, não poder sobre terceiros.
     * O gestor não as recebe e elas não contam na regra contra escalonamento.
     */
    public const PERSONAL_PERMISSIONS = ['portal.access', 'dashboard.teacher'];

    /** @return array<string, array{name: string, description: string}> */
    public static function definitions(): array
    {
        return [
            self::MANAGER => ['name' => 'Gestor da Associação', 'description' => 'Administra cadastros, turmas, financeiro, campeonatos, usuários e acessos da associação.'],
            self::FINANCE => ['name' => 'Financeiro da Associação', 'description' => 'Planos, benefícios, cobranças, pagamentos, estornos e relatórios financeiros.'],
            self::PORTAL => ['name' => 'Portal do aluno/responsável', 'description' => 'Acesso aos próprios dados e aos dos dependentes autorizados.'],
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    /** @return list<string> */
    public static function permissionsFor(string $code): array
    {
        return match ($code) {
            self::MANAGER => Permission::query()->whereNotIn('code', self::PERSONAL_PERMISSIONS)->pluck('code')->all(),
            self::FINANCE => self::FINANCE_PERMISSIONS,
            self::PORTAL => ['portal.access'],
        };
    }

    /**
     * Cria ou completa os perfis fixos sem remover permissões já concedidas.
     *
     * @return Collection<string, Role>
     */
    public static function ensure(): Collection
    {
        return collect(self::definitions())->map(function (array $definition, string $code): Role {
            $role = Role::query()->firstOrCreate(
                ['association_id' => null, 'code' => $code],
                ['name' => $definition['name'], 'scope_type' => 'association', 'status' => 'active'],
            );
            $ids = collect(self::permissionsFor($code))
                ->map(fn (string $permission) => Permission::query()->firstOrCreate(['code' => $permission], ['description' => $permission])->id);
            $role->permissions()->syncWithoutDetaching($ids->all());

            return $role;
        });
    }
}
