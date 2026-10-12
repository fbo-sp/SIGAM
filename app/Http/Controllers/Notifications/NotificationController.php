<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationContentService;
use App\Support\Notifications\NotificationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = Notification::query()->where('user_id', $request->user()->id)->where('association_id', $request->user()->association_id)->where('channel', 'internal')->with('event')->latest('id')->paginate(25);
        $content = app(NotificationContentService::class);
        foreach ($notifications as $notification) {
            if (! $content->canViewDetails($notification, $request->user())) {
                // Presentation only: preserve the original audit/history in storage.
                $notification->subject = 'Aviso anterior';
                $notification->body = 'Os detalhes deste aviso não estão disponíveis para o seu acesso atual.';
            }
        }
        $preferences = NotificationPreference::query()->where('user_id', $request->user()->id)->get()->keyBy('event_type');
        $eventTypes = NotificationCatalog::EVENTS;

        return view('notifications.index', compact('notifications', 'preferences', 'eventTypes'));
    }

    public function read(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id && $notification->association_id === $request->user()->association_id && $notification->channel === 'internal', 404);
        $notification->update(['status' => 'read', 'read_at' => $notification->read_at ?: now()]);

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        Notification::query()->where('user_id', $request->user()->id)->where('association_id', $request->user()->association_id)->where('channel', 'internal')->whereNull('read_at')->update(['status' => 'read', 'read_at' => now()]);

        return back()->with('status', 'Todas as notificações foram marcadas como lidas.');
    }

    public function preferences(Request $request): RedirectResponse
    {
        $validated = $request->validate(['preferences' => ['array'], 'preferences.*.internal' => ['nullable', 'boolean'], 'preferences.*.email' => ['nullable', 'boolean']]);
        foreach (NotificationCatalog::EVENTS as $eventType => $label) {
            NotificationPreference::query()->updateOrCreate(['user_id' => $request->user()->id, 'event_type' => $eventType], ['internal_enabled' => (bool) ($validated['preferences'][$eventType]['internal'] ?? false), 'email_enabled' => (bool) ($validated['preferences'][$eventType]['email'] ?? false)]);
        }

        return back()->with('status', 'Preferências de contato atualizadas.');
    }
}
