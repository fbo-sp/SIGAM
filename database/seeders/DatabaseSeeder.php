<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\NotificationCatalog;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $permissions = collect([
            'association.view' => 'Visualizar associação',
            'association.manage' => 'Administrar associação',
            'dojo.view' => 'Visualizar dojos',
            'dojo.manage' => 'Administrar dojos',
            'person.view' => 'Visualizar pessoas',
            'person.manage' => 'Administrar pessoas',
            'martial_art.view' => 'Visualizar modalidades e graduações',
            'martial_art.manage' => 'Administrar modalidades e graduações',
            'student.view' => 'Visualizar alunos',
            'student.manage' => 'Administrar alunos e responsáveis',
            'teacher.view' => 'Visualizar professores',
            'teacher.manage' => 'Administrar professores e habilitações',
            'teacher.assign' => 'Vincular professor da associação ao dojo',
            'class.view' => 'Visualizar turmas e horários',
            'class.manage' => 'Administrar turmas e horários',
            'enrollment.manage' => 'Administrar matrículas em turmas',
            'session.view' => 'Visualizar aulas',
            'session.manage' => 'Cancelar e reagendar aulas',
            'attendance.manage' => 'Registrar e fechar chamadas',
            'visitor.manage' => 'Autorizar e revogar visitante em aula',
            'attendance.reopen' => 'Reabrir chamadas finalizadas',
            'import.manage' => 'Importar alunos e presenças por planilha',
            'document.view' => 'Visualizar documentos e validades',
            'document.manage' => 'Enviar, configurar e excluir documentos',
            'document.download' => 'Baixar arquivos privados',
            'finance.view' => 'Visualizar planos e benefícios financeiros',
            'finance.manage' => 'Administrar configurações e planos financeiros',
            'student_plan.manage' => 'Vincular planos aos alunos de um dojo',
            'discount.manage' => 'Solicitar descontos',
            'discount.approve' => 'Aprovar ou recusar descontos',
            'gratuity.request' => 'Solicitar gratuidades',
            'gratuity.approve_dojo' => 'Decidir gratuidades pelo dojo',
            'gratuity.approve_association' => 'Decidir gratuidades pela associação',
            'charge.view' => 'Visualizar cobranças e extratos',
            'charge.generate' => 'Gerar cobranças recorrentes',
            'payment.manage' => 'Registrar pagamentos',
            'payment.reverse' => 'Estornar pagamentos',
            'finance.report' => 'Visualizar e exportar relatórios financeiros',
            'federation.view' => 'Visualizar federações, filiações e relatórios federativos',
            'federation.manage' => 'Administrar federações, filiações e documentos federativos',
            'championship.view' => 'Visualizar campeonatos, inscrições e resultados',
            'championship.manage' => 'Administrar campeonatos',
            'championship.register' => 'Realizar inscrições em campeonatos',
            'championship.approve' => 'Aprovar ou recusar inscrições em campeonatos',
            'championship.result' => 'Registrar participações e resultados de campeonatos',
            'portal.access' => 'Acessar dados próprios e de dependentes autorizados',
            'dashboard.association' => 'Visualizar dashboard consolidado da associação',
            'dashboard.dojo' => 'Visualizar dashboard do dojo vinculado',
            'dashboard.teacher' => 'Visualizar dashboard do professor',
            'user.manage' => 'Administrar usuários',
            'role.manage' => 'Administrar papéis e permissões',
            'audit.view' => 'Visualizar auditoria',
        ])->map(fn (string $description, string $code) => Permission::query()->updateOrCreate(
            ['code' => $code],
            ['description' => $description],
        ));

        $administrator = Role::query()->firstOrCreate(
            ['association_id' => null, 'code' => 'system_administrator'],
            ['name' => 'Administrador do Sistema', 'scope_type' => 'system', 'status' => 'active'],
        );
        $administrator->permissions()->sync($permissions->pluck('id'));

        $this->call(OperationalRolesSeeder::class);
        $this->call(AssociationRolesSeeder::class);

        foreach (NotificationCatalog::templates() as $eventType => [$subject, $body]) {
            foreach (['internal', 'email'] as $channel) {
                NotificationTemplate::query()->firstOrCreate(
                    ['association_id' => null, 'event_type' => $eventType, 'channel' => $channel],
                    ['subject_template' => $subject, 'body_template' => $body, 'status' => 'active'],
                );
            }
        }

        $email = mb_strtolower(trim((string) env('SIGAM_ADMIN_EMAIL')));
        $password = (string) env('SIGAM_ADMIN_PASSWORD');

        if ($email !== '' && $password !== '') {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => (string) env('SIGAM_ADMIN_NAME', 'Administrador'),
                    'password' => Hash::make($password),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );

            if ($user->wasRecentlyCreated) {
                $user->roles()->syncWithoutDetaching([
                    $administrator->id => ['association_id' => null],
                ]);
            }
        }
    }
}
