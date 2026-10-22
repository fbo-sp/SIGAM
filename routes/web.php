<?php

use App\Http\Controllers\Access\DojoAccessController;
use App\Http\Controllers\Access\DojoChampionshipController;
use App\Http\Controllers\Access\DojoFinanceController;
use App\Http\Controllers\Access\DojoStudentPersonController;
use App\Http\Controllers\Access\OperationalController;
use App\Http\Controllers\Access\UserController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\TeacherInvitationController;
use App\Http\Controllers\Championship\ChampionshipController;
use App\Http\Controllers\Classes\ClassGroupController;
use App\Http\Controllers\Classes\DojoClassController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Federation\FederationController;
use App\Http\Controllers\Finance\ChargeController;
use App\Http\Controllers\Finance\FinanceController;
use App\Http\Controllers\Finance\FinancialReportController;
use App\Http\Controllers\Finance\PaymentController;
use App\Http\Controllers\Import\AssistedImportController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Organization\AssociationController;
use App\Http\Controllers\Organization\DojoController;
use App\Http\Controllers\People\PersonController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Sports\CatalogController;
use App\Http\Controllers\Sports\DirectRegistrationController;
use App\Http\Controllers\Sports\StudentController;
use App\Http\Controllers\Sports\TeacherController;
use App\Http\Controllers\Sports\TeacherInvitationManagementController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/entrar', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
    Route::get('/recuperar-senha', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/recuperar-senha', [ForgotPasswordController::class, 'store'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/redefinir-senha/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/redefinir-senha', [ResetPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/ativar-conta/{token}', [AccountActivationController::class, 'show'])->middleware('throttle:30,1')->name('account-activation.show');
    Route::post('/ativar-conta/{token}', [AccountActivationController::class, 'store'])->middleware('throttle:5,1')->name('account-activation.store');
});

Route::get('/convites/professor/{token}', [TeacherInvitationController::class, 'show'])->middleware('throttle:30,1')->name('teacher-invitations.show');
Route::post('/convites/professor/{token}/aceitar', [TeacherInvitationController::class, 'accept'])->middleware('throttle:5,1')->name('teacher-invitations.accept');
Route::post('/convites/professor/{token}/recusar', [TeacherInvitationController::class, 'decline'])->middleware('throttle:5,1')->name('teacher-invitations.decline');

Route::middleware(['auth', 'active', 'association.context'])->group(function (): void {
    Route::get('/meus-dojos', [OperationalController::class, 'index'])->name('operation.index');
    Route::prefix('/operacao/dojos/{dojo}')->name('operation.')->controller(OperationalController::class)->group(function (): void {
        Route::get('/', 'show')->name('home');
        Route::get('/alunos', 'students')->name('students');
        Route::get('/alunos/novo', [DirectRegistrationController::class, 'createDojoStudent'])->name('students.direct.create');
        Route::post('/alunos', [DirectRegistrationController::class, 'storeDojoStudent'])->name('students.direct.store');
        Route::get('/alunos/{student}/dados', [DojoStudentPersonController::class, 'edit'])->name('students.person.edit');
        Route::put('/alunos/{student}/dados', [DojoStudentPersonController::class, 'update'])->name('students.person.update');
        Route::get('/turmas', [DojoClassController::class, 'index'])->name('classes');
        Route::get('/turmas/nova', [DojoClassController::class, 'create'])->name('classes.create');
        Route::post('/turmas', [DojoClassController::class, 'store'])->name('classes.store');
        Route::get('/turmas/{classGroup}', [DojoClassController::class, 'show'])->name('classes.show');
        Route::get('/turmas/{classGroup}/editar', [DojoClassController::class, 'edit'])->name('classes.edit');
        Route::put('/turmas/{classGroup}', [DojoClassController::class, 'update'])->name('classes.update');
        Route::post('/turmas/{classGroup}/matriculas', [DojoClassController::class, 'enroll'])->name('classes.enrollments.store');
        Route::post('/turmas/{classGroup}/matriculas/{enrollment}/encerrar', [DojoClassController::class, 'endEnrollment'])->name('classes.enrollments.end');
        Route::post('/turmas/{classGroup}/professores', [DojoClassController::class, 'assignTeacher'])->name('classes.teachers.store');
        Route::post('/turmas/{classGroup}/professores/{assignment}/encerrar', [DojoClassController::class, 'endTeacher'])->name('classes.teachers.end');
        Route::post('/turmas/{classGroup}/agenda/substituir', [DojoClassController::class, 'replaceScheduleSet'])->name('classes.schedules.replace-set');
        Route::post('/turmas/{classGroup}/aulas/atualizar', [DojoClassController::class, 'refreshFutureSessions'])->name('classes.sessions.refresh');
        Route::post('/turmas/{classGroup}/aulas/passadas', [DojoClassController::class, 'generatePastSessions'])->name('classes.sessions.past');
        Route::get('/aulas', 'sessions')->name('sessions');
        Route::get('/aulas/{session}', 'session')->name('session');
        Route::post('/aulas/{session}/chamada', 'saveAttendance')->name('attendance');
        Route::post('/aulas/{session}/reabrir', 'reopenAttendance')->name('attendance.reopen');
        Route::post('/aulas/{session}/visitantes', 'addVisitor')->name('visitors.store');
        Route::post('/aulas/{session}/visitantes/{visitor}/revogar', 'revokeVisitor')->name('visitors.revoke');
        Route::get('/professores', 'teachers')->name('teachers');
        Route::post('/professores', 'linkTeacher')->name('teachers.link');
        Route::post('/professores/{link}/encerrar', 'unlinkTeacher')->name('teachers.unlink');
        Route::get('/financeiro', [DojoFinanceController::class, 'index'])->name('finance.index');
        Route::post('/financeiro/planos', [DojoFinanceController::class, 'assignPlan'])->name('finance.plans.store');
        Route::post('/financeiro/cobrancas/{charge}/pagamentos', [DojoFinanceController::class, 'pay'])->name('finance.payments.store');
        Route::post('/financeiro/gratuidades', [DojoFinanceController::class, 'requestGratuity'])->name('finance.gratuities.store');
        Route::post('/financeiro/gratuidades/{gratuity}/decidir', [DojoFinanceController::class, 'decideGratuity'])->name('finance.gratuities.decide');
        Route::get('/campeonatos', [DojoChampionshipController::class, 'index'])->name('championships.index');
        Route::get('/campeonatos/{championship}', [DojoChampionshipController::class, 'show'])->name('championships.show');
        Route::post('/campeonatos/{championship}/inscricoes', [DojoChampionshipController::class, 'register'])->name('championships.register');
    });
    Route::prefix('/associacoes/{association}/usuarios')->name('associations.users.')->middleware('permission:user.manage')
        ->controller(UserController::class)->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/novo', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{user}', 'show')->name('show');
            Route::put('/{user}/perfis', 'updateRoles')->name('roles.update');
            Route::post('/{user}/inativar', 'deactivate')->name('deactivate');
            Route::post('/{user}/reativar', 'reactivate')->name('reactivate');
            Route::post('/{user}/reenviar-convite', 'resendInvitation')->middleware('throttle:5,1')->name('invitation.resend');
        });
    Route::prefix('/associacoes/{association}/acessos-dojos')->name('dojo-access.')->middleware('permission:role.manage')
        ->controller(DojoAccessController::class)->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('/{grant}/revogar', 'revoke')->name('revoke');
        });
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/notificacoes', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notificacoes/ler-todas', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notificacoes/{notification}/ler', [NotificationController::class, 'read'])->name('notifications.read');
    Route::put('/notificacoes/preferencias', [NotificationController::class, 'preferences'])->name('notifications.preferences');

    Route::prefix('/portal')->name('portal.')->middleware('permission:portal.access')->group(function (): void {
        Route::get('/', [PortalController::class, 'index'])->name('index');
        Route::get('/alunos/{student}', [PortalController::class, 'show'])->name('students.show');
        Route::put('/alunos/{student}/contato', [PortalController::class, 'updateContact'])->name('students.contact.update');
        Route::get('/alunos/{student}/documentos/{document}', [PortalController::class, 'download'])->name('students.documents.download');
        Route::post('/alunos/{student}/campeonatos/{championship}', [PortalController::class, 'register'])->name('students.championships.register');
    });

    Route::get('/associacoes', [AssociationController::class, 'index'])->middleware('permission:association.view')->name('associations.index');
    Route::get('/associacoes/nova', [AssociationController::class, 'create'])->middleware('permission:association.manage')->name('associations.create');
    Route::post('/associacoes', [AssociationController::class, 'store'])->middleware('permission:association.manage')->name('associations.store');
    Route::get('/associacoes/{association}', [AssociationController::class, 'show'])->middleware('permission:association.view')->name('associations.show');
    Route::get('/associacoes/{association}/editar', [AssociationController::class, 'edit'])->middleware('permission:association.manage')->name('associations.edit');
    Route::put('/associacoes/{association}', [AssociationController::class, 'update'])->middleware('permission:association.manage')->name('associations.update');

    Route::prefix('/associacoes/{association}')->name('associations.')->group(function (): void {
        Route::get('/importacoes', [AssistedImportController::class, 'index'])->name('imports.index');
        Route::post('/importacoes', [AssistedImportController::class, 'store'])->name('imports.store');
        Route::get('/importacoes/{batch}', [AssistedImportController::class, 'show'])->name('imports.show');
        Route::post('/importacoes/{batch}/processar', [AssistedImportController::class, 'process'])->name('imports.process');
        Route::get('/importacoes/{batch}/erros', [AssistedImportController::class, 'errors'])->name('imports.errors');
        Route::get('/dojos', [DojoController::class, 'index'])->middleware('permission:dojo.view')->name('dojos.index');
        Route::get('/dojos/novo', [DojoController::class, 'create'])->middleware('permission:dojo.manage')->name('dojos.create');
        Route::post('/dojos', [DojoController::class, 'store'])->middleware('permission:dojo.manage')->name('dojos.store');
        Route::get('/dojos/{dojo}', [DojoController::class, 'show'])->middleware('permission:dojo.view')->name('dojos.show');
        Route::get('/dojos/{dojo}/editar', [DojoController::class, 'edit'])->middleware('permission:dojo.manage')->name('dojos.edit');
        Route::put('/dojos/{dojo}', [DojoController::class, 'update'])->middleware('permission:dojo.manage')->name('dojos.update');

        Route::get('/pessoas', [PersonController::class, 'index'])->middleware('permission:person.view')->name('people.index');
        Route::get('/pessoas/exportar', [PersonController::class, 'export'])->middleware('permission:person.view')->name('people.export');
        Route::get('/pessoas/nova', [PersonController::class, 'create'])->middleware('permission:person.manage')->name('people.create');
        Route::post('/pessoas', [PersonController::class, 'store'])->middleware('permission:person.manage')->name('people.store');
        Route::get('/pessoas/{person}', [PersonController::class, 'show'])->middleware('permission:person.view')->name('people.show');
        Route::get('/pessoas/{person}/editar', [PersonController::class, 'edit'])->middleware('permission:person.manage')->name('people.edit');
        Route::put('/pessoas/{person}', [PersonController::class, 'update'])->middleware('permission:person.manage')->name('people.update');

        Route::get('/modalidades', [CatalogController::class, 'index'])->middleware('permission:martial_art.view')->name('catalog.index');
        Route::post('/modalidades', [CatalogController::class, 'storeMartialArt'])->middleware('permission:martial_art.manage')->name('martial-arts.store');
        Route::put('/modalidades/{martialArt}', [CatalogController::class, 'updateMartialArt'])->middleware('permission:martial_art.manage')->name('martial-arts.update');
        Route::post('/modalidades/{martialArt}/graduacoes', [CatalogController::class, 'storeRank'])->middleware('permission:martial_art.manage')->name('ranks.store');
        Route::put('/modalidades/{martialArt}/graduacoes/{rank}', [CatalogController::class, 'updateRank'])->middleware('permission:martial_art.manage')->name('ranks.update');

        Route::get('/alunos', [StudentController::class, 'index'])->middleware('permission:student.view')->name('students.index');
        Route::get('/alunos/novo', [DirectRegistrationController::class, 'createAssociationStudent'])->middleware('permission:student.manage')->name('students.create');
        Route::post('/alunos/cadastro-direto', [DirectRegistrationController::class, 'storeAssociationStudent'])->middleware('permission:student.manage')->name('students.direct.store');
        Route::get('/alunos/{student}', [StudentController::class, 'show'])->middleware('permission:student.view')->name('students.show');
        Route::post('/alunos/{student}/responsaveis', [StudentController::class, 'addGuardian'])->middleware('permission:student.manage')->name('students.guardians.store');
        Route::post('/alunos/{student}/ativar', [StudentController::class, 'activate'])->middleware('permission:student.manage')->name('students.activate');
        Route::post('/alunos/{student}/turmas', [StudentController::class, 'enroll'])->middleware('permission:enrollment.manage')->name('students.enrollments.store');
        Route::post('/alunos/{student}/turmas/{enrollment}/encerrar', [StudentController::class, 'endEnrollment'])->middleware('permission:enrollment.manage')->name('students.enrollments.end');

        Route::get('/professores', [TeacherController::class, 'index'])->middleware('permission:teacher.view')->name('teachers.index');
        Route::get('/professores/novo', [DirectRegistrationController::class, 'createTeacher'])->middleware('permission:teacher.manage')->name('teachers.create');
        Route::post('/professores/cadastro-direto', [DirectRegistrationController::class, 'storeTeacher'])->middleware('permission:teacher.manage')->name('teachers.direct.store');
        Route::post('/professores/{teacher}/convites', [TeacherInvitationManagementController::class, 'store'])->middleware('permission:teacher.manage')->name('teacher-invitations.store');
        Route::post('/professores/convites/{invitation}/reenviar', [TeacherInvitationManagementController::class, 'reissue'])->middleware('permission:teacher.manage')->name('teacher-invitations.reissue');
        Route::post('/professores/convites/{invitation}/revogar', [TeacherInvitationManagementController::class, 'revoke'])->middleware('permission:teacher.manage')->name('teacher-invitations.revoke');

        Route::get('/turmas', [ClassGroupController::class, 'index'])->middleware('permission:class.view')->name('classes.index');
        Route::get('/turmas/nova', [ClassGroupController::class, 'create'])->middleware('permission:class.manage')->name('classes.create');
        Route::post('/turmas', [ClassGroupController::class, 'store'])->middleware('permission:class.manage')->name('classes.store');
        Route::get('/turmas/{classGroup}', [ClassGroupController::class, 'show'])->middleware('permission:class.view')->name('classes.show');
        Route::post('/turmas/{classGroup}/agenda/substituir', [ClassGroupController::class, 'replaceScheduleSet'])->middleware('permission:class.manage')->name('classes.schedules.replace-set');
        Route::post('/turmas/{classGroup}/aulas/atualizar', [ClassGroupController::class, 'refreshFutureSessions'])->middleware('permission:class.manage')->name('classes.sessions.refresh');
        Route::post('/turmas/{classGroup}/horarios/{schedule}/substituir', [ClassGroupController::class, 'replaceSchedule'])->middleware('permission:class.manage')->name('classes.schedules.replace');
        Route::post('/turmas/{classGroup}/matriculas', [ClassGroupController::class, 'enroll'])->middleware('permission:enrollment.manage')->name('classes.enrollments.store');

        Route::get('/aulas', [AttendanceController::class, 'index'])->middleware('permission:session.view')->name('attendance.index');
        Route::get('/aulas/{session}', [AttendanceController::class, 'show'])->middleware('permission:session.view')->name('attendance.show');
        Route::post('/aulas/{session}/chamada', [AttendanceController::class, 'store'])->middleware('permission:attendance.manage')->name('attendance.store');
        Route::post('/aulas/{session}/visitantes', [AttendanceController::class, 'addVisitor'])->middleware('permission:visitor.manage')->name('attendance.visitors.store');
        Route::post('/aulas/{session}/visitantes/{visitor}/revogar', [AttendanceController::class, 'revokeVisitor'])->middleware('permission:visitor.manage')->name('attendance.visitors.revoke');
        Route::post('/aulas/{session}/reabrir', [AttendanceController::class, 'reopen'])->middleware('permission:attendance.reopen')->name('attendance.reopen');
        Route::post('/aulas/{session}/reagendar', [AttendanceController::class, 'reschedule'])->middleware('permission:session.manage')->name('sessions.reschedule');
        Route::post('/aulas/{session}/cancelar', [AttendanceController::class, 'cancel'])->middleware('permission:session.manage')->name('sessions.cancel');

        Route::get('/documentos', [DocumentController::class, 'index'])->middleware('permission:document.view')->name('documents.index');
        Route::post('/documentos/tipos', [DocumentController::class, 'storeType'])->middleware('permission:document.manage')->name('document-types.store');
        Route::post('/documentos', [DocumentController::class, 'store'])->middleware('permission:document.manage')->name('documents.store');
        Route::get('/documentos/{document}/download', [DocumentController::class, 'download'])->middleware('permission:document.download')->name('documents.download');
        Route::delete('/documentos/{document}', [DocumentController::class, 'destroy'])->middleware('permission:document.manage')->name('documents.destroy');

        Route::get('/federacoes', [FederationController::class, 'index'])->middleware('permission:federation.view')->name('federation.index');
        Route::post('/federacoes', [FederationController::class, 'storeFederation'])->middleware('permission:federation.manage')->name('federation.store');
        Route::put('/federacoes/{federation}', [FederationController::class, 'updateFederation'])->middleware('permission:federation.manage')->name('federation.update');
        Route::post('/federacoes/filiacoes', [FederationController::class, 'storeMembership'])->middleware('permission:federation.manage')->name('federation.memberships.store');
        Route::get('/federacoes/filiacoes/exportar', [FederationController::class, 'export'])->middleware('permission:federation.view')->name('federation.memberships.export');
        Route::get('/federacoes/filiacoes/{membership}', [FederationController::class, 'show'])->middleware('permission:federation.view')->name('federation.memberships.show');
        Route::post('/federacoes/filiacoes/{membership}/documentos', [FederationController::class, 'storeDocument'])->middleware('permission:federation.manage')->name('federation.memberships.documents.store');

        Route::get('/campeonatos', [ChampionshipController::class, 'index'])->middleware('permission:championship.view')->name('championships.index');
        Route::get('/campeonatos/novo', [ChampionshipController::class, 'create'])->middleware('permission:championship.manage')->name('championships.create');
        Route::post('/campeonatos', [ChampionshipController::class, 'store'])->middleware('permission:championship.manage')->name('championships.store');
        Route::get('/campeonatos/{championship}/exportar', [ChampionshipController::class, 'export'])->middleware('permission:championship.view')->name('championships.export');
        Route::get('/campeonatos/{championship}', [ChampionshipController::class, 'show'])->middleware('permission:championship.view')->name('championships.show');
        Route::put('/campeonatos/{championship}', [ChampionshipController::class, 'update'])->middleware('permission:championship.manage')->name('championships.update');
        Route::post('/campeonatos/{championship}/inscricoes', [ChampionshipController::class, 'register'])->middleware('permission:championship.register')->name('championships.registrations.store');
        Route::post('/campeonatos/inscricoes/{registration}/decisao', [ChampionshipController::class, 'decide'])->middleware('permission:championship.approve')->name('championships.registrations.decision');
        Route::put('/campeonatos/inscricoes/{registration}/resultado', [ChampionshipController::class, 'result'])->middleware('permission:championship.result')->name('championships.registrations.result');

        Route::get('/financeiro', [FinanceController::class, 'index'])->middleware('permission:finance.view')->name('finance.index');
        Route::put('/financeiro/configuracoes', [FinanceController::class, 'updateSettings'])->middleware('permission:finance.manage')->name('finance.settings.update');
        Route::post('/financeiro/planos', [FinanceController::class, 'storePlan'])->middleware('permission:finance.manage')->name('finance.plans.store');
        Route::post('/financeiro/vinculos', [FinanceController::class, 'assignPlan'])->middleware('permission:finance.manage')->name('finance.student-plans.store');
        Route::post('/financeiro/descontos', [FinanceController::class, 'storeDiscount'])->middleware('permission:discount.manage')->name('finance.discounts.store');
        Route::post('/financeiro/descontos/{discount}/decisao', [FinanceController::class, 'decideDiscount'])->middleware('permission:discount.approve')->name('finance.discounts.decision');
        Route::post('/financeiro/gratuidades', [FinanceController::class, 'storeGratuity'])->middleware('permission:gratuity.request')->name('finance.gratuities.store');
        Route::post('/financeiro/gratuidades/{gratuity}/dojo', [FinanceController::class, 'decideDojo'])->middleware('permission:gratuity.approve_dojo')->name('finance.gratuities.dojo');
        Route::post('/financeiro/gratuidades/{gratuity}/associacao', [FinanceController::class, 'decideAssociation'])->middleware('permission:gratuity.approve_association')->name('finance.gratuities.association');
        Route::get('/financeiro/cobrancas', [ChargeController::class, 'index'])->middleware('permission:charge.view')->name('charges.index');
        Route::post('/financeiro/cobrancas/gerar', [ChargeController::class, 'generate'])->middleware('permission:charge.generate')->name('charges.generate');
        Route::get('/financeiro/cobrancas/{charge}', [ChargeController::class, 'show'])->middleware('permission:charge.view')->name('charges.show');
        Route::post('/financeiro/cobrancas/{charge}/pagamentos', [PaymentController::class, 'store'])->middleware('permission:payment.manage')->name('payments.store');
        Route::post('/financeiro/pagamentos/{payment}/estornar', [PaymentController::class, 'reverse'])->middleware('permission:payment.reverse')->name('payments.reverse');
        Route::get('/financeiro/alunos/{student}/extrato', [ChargeController::class, 'statement'])->middleware('permission:charge.view')->name('charges.statement');
        Route::get('/financeiro/relatorios', [FinancialReportController::class, 'index'])->middleware('permission:finance.report')->name('finance.reports.index');
        Route::get('/financeiro/relatorios/exportar', [FinancialReportController::class, 'export'])->middleware('permission:finance.report')->name('finance.reports.export');
    });

    Route::post('/sair', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::get('/modelos/importacao/{type}/{format}', [AssistedImportController::class, 'template'])
    ->middleware(['auth', 'active'])->name('imports.template');
