<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EmailDeliveryService
{
    public function sendBatch(int $limit): array
    {
        $result = ['processed' => 0, 'failed' => 0];
        $ids = Notification::query()->where('channel', 'email')->whereIn('status', ['pending', 'failed'])->where('attempt_count', '<', 5)->where('scheduled_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            // Compare-and-set: also protects direct concurrent CLI executions.
            $claimed = Notification::query()->whereKey($id)->whereIn('status', ['pending', 'failed'])->where('attempt_count', '<', 5)->where('scheduled_at', '<=', now())->update(['status' => 'sending', 'attempt_count' => DB::raw('attempt_count + 1'), 'last_attempt_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $notification = Notification::query()->with('user.association')->findOrFail($id);
            $user = $notification->user;
            $preference = NotificationPreference::query()->where('user_id', $notification->user_id)->where('event_type', $notification->event_type)->first();
            if (! $user || ! $user->isActive() || $user->association_id !== $notification->association_id || $user->association?->status !== 'active' || ($preference && ! $preference->email_enabled) || $user->email !== $notification->recipient_email) {
                $notification->update(['status' => 'suppressed', 'error_message' => 'Entrega cancelada por mudança de conta, contato ou preferência.']);
                $result['processed']++;

                continue;
            }
            try {
                // No financial, student or document details leave the authenticated inbox.
                Mail::raw('Há uma atualização na sua caixa de notificações do JUDOMA. Acesse o sistema pelo endereço habitual.', fn ($message) => $message->to($notification->recipient_email)->subject('Nova notificação no JUDOMA'));
            } catch (\Throwable) {
                $notification->update(['status' => 'failed', 'scheduled_at' => now()->addMinutes(min(60, 2 ** $notification->attempt_count)), 'error_message' => 'SMTP indisponível. Verifique a configuração e a conectividade.']);
                $result['failed']++;
                $result['processed']++;

                continue;
            }
            // Deliberately outside the transport catch: a DB failure after SMTP success
            // leaves sending for manual reconciliation, never an automatic replay.
            $notification->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);
            $result['processed']++;
        }

        return $result;
    }
}
