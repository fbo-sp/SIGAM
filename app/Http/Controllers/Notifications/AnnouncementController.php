<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\AnnouncementRequest;
use App\Models\Association;
use App\Models\Dojo;
use App\Services\Notifications\AnnouncementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Avisos nos dois contextos: associação (/associacoes/{association}/avisos) e dojo (/operacao/dojos/{dojo}/avisos). */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $service) {}

    public function index(Request $request): View
    {
        [$association, $dojo, $level] = $this->context($request);
        $announcements = $this->service->visible($request->user(), $association, $dojo, $level)->with(['sender:id,name', 'dojo:id,name'])->latest('sent_at')->paginate(20);

        return view('announcements.index', $this->base($association, $dojo, $level) + compact('announcements'));
    }

    public function create(Request $request): View
    {
        [$association, $dojo, $level] = $this->context($request);
        $options = $this->service->options($request->user(), $association, $dojo, $level);
        $prefill = [
            'target_type' => (string) $request->query('target_type', array_key_first($options['targets'])),
            'class_ids' => array_map('intval', (array) $request->query('class_ids', [])),
            'student_ids' => array_map('intval', (array) $request->query('student_ids', [])),
        ];

        return view('announcements.create', $this->base($association, $dojo, $level) + compact('options', 'prefill'));
    }

    public function preview(AnnouncementRequest $request): View
    {
        [$association, $dojo, $level] = $this->context($request);
        $data = $request->validated();
        $audience = $this->service->audience($request->user(), $association, $dojo, $level, $data);
        $token = (string) Str::uuid();
        $signature = $this->service->signature($request->user(), $dojo, $level);

        return view('announcements.preview', $this->base($association, $dojo, $level) + compact('data', 'audience', 'token', 'signature'));
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        [$association, $dojo, $level] = $this->context($request);
        $announcement = $this->service->send($request->user(), $association, $dojo, $level, $request->validated(), $request->validated('token'));

        return redirect()->route($this->prefix($dojo).'.show', [$dojo ?? $association, $announcement])
            ->with('status', 'Aviso enviado para '.$announcement->recipient_count.' pessoa(s).');
    }

    public function show(Request $request): View
    {
        [$association, $dojo, $level] = $this->context($request);
        $announcement = $this->service->visible($request->user(), $association, $dojo, $level)->findOrFail($this->id($request, 'announcement'));
        $announcement->load(['sender:id,name', 'dojo:id,name', 'recipients.person:id,full_name,social_name', 'recipients.notification:id,read_at']);
        $stats = $this->service->stats($announcement);

        return view('announcements.show', $this->base($association, $dojo, $level) + compact('announcement', 'stats'));
    }

    /** @return array{0: Association, 1: ?Dojo, 2: string} */
    private function context(Request $request): array
    {
        // As rotas dos dois contextos chegam aqui; os ids da URL são resolvidos e validados pelo serviço.
        $dojo = $request->route('dojo') !== null ? Dojo::query()->findOrFail($this->id($request, 'dojo')) : null;
        $association = Association::query()->findOrFail($dojo ? $dojo->association_id : $this->id($request, 'association'));

        return [$association, $dojo, $this->service->level($request->user(), $association, $dojo)];
    }

    private function id(Request $request, string $parameter): int
    {
        $value = $request->route($parameter);

        return $value instanceof Model ? (int) $value->getKey() : (int) $value;
    }

    private function prefix(?Dojo $dojo): string
    {
        return $dojo ? 'operation.announcements' : 'associations.announcements';
    }

    private function base(Association $association, ?Dojo $dojo, string $level): array
    {
        return ['association' => $association, 'dojo' => $dojo, 'level' => $level, 'prefix' => $this->prefix($dojo), 'context' => $dojo ?? $association];
    }
}
