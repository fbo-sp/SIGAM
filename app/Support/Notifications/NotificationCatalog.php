<?php

namespace App\Support\Notifications;

class NotificationCatalog
{
    public const EVENTS = [
        'charge.due_soon' => 'Cobrança próxima do vencimento',
        'charge.overdue' => 'Cobrança vencida',
        'document.expiring' => 'Documento vencendo',
        'membership.expiring' => 'Filiação vencendo',
        'championship.opened' => 'Campeonato aberto',
        'championship.deadline' => 'Prazo de campeonato próximo',
        'gratuity.awaiting_approval' => 'Gratuidade aguardando aprovação',
        'class.cancelled' => 'Aula cancelada',
        'announcement.sent' => 'Avisos da associação, do dojo e dos professores',
        'attendance.consecutive_absences' => 'Faltas consecutivas',
        'assessment.due' => 'Avaliação técnica pendente',
    ];

    public static function templates(): array
    {
        return [
            'charge.due_soon' => ['Cobrança próxima do vencimento', 'Olá, {{recipient_name}}. A cobrança “{{item}}” vence em {{date}}. Consulte o portal do JUDOMA.'],
            'charge.overdue' => ['Cobrança vencida', 'Olá, {{recipient_name}}. A cobrança “{{item}}” venceu em {{date}} e possui saldo pendente.'],
            'document.expiring' => ['Documento próximo do vencimento', 'O documento “{{item}}” de {{student_name}} vence em {{date}}.'],
            'membership.expiring' => ['Filiação próxima do vencimento', 'A filiação “{{item}}” de {{student_name}} vence em {{date}}.'],
            'championship.opened' => ['Inscrições abertas para campeonato', 'As inscrições para “{{item}}” estão abertas até {{date}}. Consulte as regras no JUDOMA.'],
            'championship.deadline' => ['Prazo de inscrição próximo', 'O prazo de inscrição para “{{item}}” termina em {{date}}.'],
            'gratuity.awaiting_approval' => ['Gratuidade aguardando decisão', 'A solicitação de gratuidade de {{student_name}} aguarda sua análise no JUDOMA.'],
            'announcement.sent' => ['{{title}}', '{{body}}'],
            'class.cancelled' => ['Aula cancelada', 'A aula “{{item}}” de {{date}} foi cancelada. Consulte o JUDOMA para detalhes.'],
            'attendance.consecutive_absences' => ['Atenção à frequência', '{{student_name}} possui três faltas consecutivas registradas. Procure a associação se precisar de ajuda.'],
            'assessment.due' => ['Avaliação técnica pendente', 'Olá, {{recipient_name}}. Na turma “{{item}}”, {{count}} aluno(s) estão sem avaliação técnica há mais de {{weeks}} semanas. Registre pelo acompanhamento técnico no JUDOMA.'],
        ];
    }
}
