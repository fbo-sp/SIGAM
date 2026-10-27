<?php

namespace App\Services\Notifications;

use App\Mail\AnnouncementMail;
use App\Models\AnnouncementRecipient;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails dos avisos escritos por pessoas, com o texto completo (decisão do Fabio, 2026-10-27).
 * Mesma reserva exclusiva e espera crescente das demais entregas; vai também a quem não tem conta.
 */
class AnnouncementEmailService
{
    public function sendBatch(int $limit): array
    {
        $result = ['processed' => 0, 'failed' => 0];
        $ids = AnnouncementRecipient::query()->whereIn('email_status', ['pending', 'failed'])->where('email_attempts', '<', 5)
            ->where('email_scheduled_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $claimed = AnnouncementRecipient::query()->whereKey($id)->whereIn('email_status', ['pending', 'failed'])->where('email_attempts', '<', 5)
                ->where('email_scheduled_at', '<=', now())->update(['email_status' => 'sending', 'email_attempts' => DB::raw('email_attempts + 1'), 'email_last_attempt_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $recipient = AnnouncementRecipient::query()->with(['announcement.association', 'announcement.dojo', 'announcement.sender', 'person', 'user'])->findOrFail($id);
            if (! $this->stillValid($recipient)) {
                $recipient->update(['email_status' => 'suppressed', 'email_error' => 'Entrega cancelada por mudança de conta, contato ou preferência.']);
                $result['processed']++;

                continue;
            }
            try {
                Mail::to($recipient->email)->send(new AnnouncementMail($recipient->announcement, $recipient->person->social_name ?: $recipient->person->full_name));
            } catch (\Throwable) {
                $recipient->update(['email_status' => 'failed', 'email_scheduled_at' => now()->addMinutes(min(60, 2 ** $recipient->email_attempts)), 'email_error' => 'SMTP indisponível. Verifique a configuração e a conectividade.']);
                $result['failed']++;
                $result['processed']++;

                continue;
            }
            // Fora do catch do transporte: falha do banco depois do SMTP fica em sending para conferência manual.
            $recipient->update(['email_status' => 'sent', 'email_sent_at' => now(), 'email_error' => null]);
            $result['processed']++;
        }

        return $result;
    }

    private function stillValid(AnnouncementRecipient $recipient): bool
    {
        if ($recipient->announcement->association?->status !== 'active' || blank($recipient->email)) {
            return false;
        }
        if ($recipient->user_id) {
            $user = $recipient->user;
            $preference = NotificationPreference::query()->where('user_id', $recipient->user_id)->where('event_type', AnnouncementService::EVENT)->first();

            return $user && $user->isActive() && $user->association_id === $recipient->announcement->association_id
                && ($preference?->email_enabled ?? true) && mb_strtolower($user->email) === $recipient->email;
        }

        return mb_strtolower((string) $recipient->person->email) === $recipient->email;
    }
}
