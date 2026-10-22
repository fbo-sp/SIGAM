<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Access\OperationalScope;
use Illuminate\Database\Seeder;

class OperationalRolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['dojo_operator', 'Gestor do dojo', 'dojo', OperationalScope::DOJO_PERMISSIONS],
            ['dojo_teacher', 'Professor do dojo', 'teacher', OperationalScope::TEACHER_PERMISSIONS],
        ] as [$code, $name, $scope, $codes]) {
            $role = Role::query()->firstOrCreate(['association_id' => null, 'code' => $code], ['name' => $name, 'scope_type' => $scope, 'status' => 'active']);
            // Preserve custom permissions and assignments while adding new baseline capabilities.
            $ids = collect($codes)->map(fn ($permission) => Permission::query()->firstOrCreate(['code' => $permission], ['description' => match ($permission) {
                'dojo.access' => 'Entrar no contexto do dojo',
                'teacher.assign' => 'Vincular professor da associação ao dojo',
                'student.document.manage' => 'Enviar documento no cadastro de aluno do dojo',
                'visitor.manage' => 'Autorizar e revogar visitante em aula',
                'attendance.reopen' => 'Reabrir chamadas finalizadas',
                'import.manage' => 'Importar alunos e presenças por planilha',
                'charge.view' => 'Consultar cobranças do dojo',
                'student_plan.manage' => 'Vincular planos aos alunos do dojo',
                'payment.manage' => 'Registrar pagamentos das cobranças do dojo',
                'gratuity.request' => 'Solicitar gratuidade para aluno do dojo',
                'gratuity.approve_dojo' => 'Realizar a etapa local da gratuidade',
                'championship.view' => 'Consultar campeonatos da associação no dojo',
                'championship.register' => 'Inscrever alunos do dojo em campeonatos',
                default => $permission,
            }])->id);
            $role->permissions()->syncWithoutDetaching($ids);
        }

        $teacherPermission = Permission::query()->firstOrCreate(
            ['code' => 'dashboard.teacher'],
            ['description' => 'Visualizar dashboard do professor'],
        );
        $teacherAccount = Role::query()->firstOrCreate(
            ['association_id' => null, 'code' => 'teacher_account'],
            ['name' => 'Conta de professor', 'scope_type' => 'association', 'status' => 'active'],
        );
        $teacherAccount->permissions()->syncWithoutDetaching([$teacherPermission->id]);
    }
}
