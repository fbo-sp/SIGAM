<?php

namespace App\Services\Access;

use App\Mail\TeacherInvitationMail;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TeacherInvitationDeliveryService
{
    public function sendBatch(int $limit): array
    {
        $result = ['processed' => 0, 'failed' => 0];
        TeacherInvitation::query()->whereIn('status', ['pending', 'sent'])
            ->where('delivery_status', 'sending')
            ->where('last_attempt_at', '<=', now()->subMinutes(15))
            ->update([
                'delivery_status' => 'failed',
                'scheduled_at' => now(),
                'error_message' => 'Tentativa anterior interrompida. O convite foi liberado para nova tentativa.',
            ]);

        $ids = TeacherInvitation::query()->whereIn('status', ['pending', 'sent'])
            ->whereIn('delivery_status', ['pending', 'failed'])->where('attempt_count', '<', 5)
            ->where('scheduled_at', '<=', now())->where('expires_at', '>', now())->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            $claimed = TeacherInvitation::query()->whereKey($id)->whereIn('status', ['pending', 'sent'])
                ->whereIn('delivery_status', ['pending', 'failed'])->where('attempt_count', '<', 5)
                ->where('scheduled_at', '<=', now())->where('expires_at', '>', now())
                ->update(['delivery_status' => 'sending', 'attempt_count' => DB::raw('attempt_count + 1'), 'last_attempt_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $invitation = TeacherInvitation::query()->with(['association', 'teacher.person', 'user'])->findOrFail($id);
            $valid = $invitation->encrypted_token !== null
                && hash_equals($invitation->token_hash, hash('sha256', $invitation->encrypted_token))
                && $invitation->association->status === 'active'
                && $invitation->teacher->status === 'active'
                && $invitation->teacher->person->status === 'active'
                && mb_strtolower((string) $invitation->teacher->person->email) === $invitation->email
                && $invitation->user->email === $invitation->email
                && $invitation->user->status !== 'inactive'
                && TeacherAssociationMembership::query()->where('association_id', $invitation->association_id)
                    ->where('teacher_profile_id', $invitation->teacher_profile_id)->where('status', 'active')->exists();
            if (! $valid) {
                $invitation->update(['status' => 'revoked', 'delivery_status' => 'suppressed', 'revoked_at' => now(), 'encrypted_token' => null, 'error_message' => 'Entrega cancelada por mudança no cadastro ou vínculo.']);
                $result['processed']++;

                continue;
            }
            try {
                $url = route('teacher-invitations.show', ['token' => $invitation->encrypted_token]);
                Mail::to($invitation->email)->send(new TeacherInvitationMail(
                    $invitation->association->trade_name,
                    $invitation->teacher->person->social_name ?: $invitation->teacher->person->full_name,
                    $url,
                    $invitation->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                ));
            } catch (\Throwable) {
                $invitation->update([
                    'delivery_status' => 'failed',
                    'scheduled_at' => now()->addMinutes(min(60, 2 ** $invitation->attempt_count)),
                    'error_message' => 'SMTP indisponível. Verifique a configuração e a conectividade.',
                ]);
                $result['failed']++;
                $result['processed']++;

                continue;
            }
            $invitation->update([
                'status' => 'sent',
                'delivery_status' => 'sent',
                'sent_at' => now(),
                'encrypted_token' => null,
                'error_message' => null,
            ]);
            $result['processed']++;
        }

        return $result;
    }
}
