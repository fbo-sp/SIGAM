<?php

namespace App\Services\Notifications;

use App\Models\Association;
use App\Models\Notification;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    /** @param Collection<int, User>|array<int, User> $recipients */
    public function publish(Association $association, string $eventType, string $idempotencyKey, array $context, Collection|array $recipients): NotificationEvent
    {
        return DB::transaction(function () use ($association, $eventType, $idempotencyKey, $context, $recipients): NotificationEvent {
            $event = NotificationEvent::query()->firstOrCreate(
                ['association_id' => $association->id, 'idempotency_key' => $idempotencyKey],
                ['event_type' => $eventType, 'context' => $context, 'occurred_at' => now()],
            );

            foreach (collect($recipients)->unique('id') as $user) {
                if ($user->association_id !== $association->id || ! $user->isActive()) {
                    continue;
                }
                $preference = NotificationPreference::query()->where('user_id', $user->id)->where('event_type', $eventType)->first();
                foreach (['internal', 'email'] as $channel) {
                    $enabled = $channel === 'internal' ? ($preference?->internal_enabled ?? true) : ($preference?->email_enabled ?? true);
                    $email = $user->email;
                    if (! $enabled || ($channel === 'email' && blank($email))) {
                        continue;
                    }
                    $template = $this->template($association->id, $eventType, $channel);
                    if (! $template) {
                        continue;
                    }
                    $values = [...$context, 'recipient_name' => $user->name];
                    Notification::query()->firstOrCreate(
                        ['notification_event_id' => $event->id, 'user_id' => $user->id, 'channel' => $channel],
                        ['association_id' => $association->id, 'person_id' => $user->person_id, 'event_type' => $eventType, 'recipient_email' => $channel === 'email' ? $email : null, 'subject' => $this->render($template->subject_template, $values), 'body' => $this->render($template->body_template, $values), 'status' => $channel === 'internal' ? 'sent' : 'pending', 'scheduled_at' => now(), 'sent_at' => $channel === 'internal' ? now() : null],
                    );
                }
            }

            return $event->refresh();
        }, 3);
    }

    private function template(int $associationId, string $eventType, string $channel): ?NotificationTemplate
    {
        return NotificationTemplate::query()->where('event_type', $eventType)->where('channel', $channel)->where('status', 'active')->where(fn ($query) => $query->where('association_id', $associationId)->orWhereNull('association_id'))->orderByRaw('association_id IS NULL')->first();
    }

    private function render(string $template, array $values): string
    {
        $replace = collect($values)->mapWithKeys(fn ($value, $key) => ['{{'.$key.'}}' => is_scalar($value) ? (string) $value : ''])->all();

        return strtr($template, $replace);
    }
}
