<?php

namespace App\Services\Sports;

use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Rank;
use App\Services\AuditService;
use App\Support\Sports\RankTemplateCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly RankTemplateCatalog $templates,
    ) {}

    public function createMartialArt(Association $association, array $data, ?string $templateKey = null): MartialArt
    {
        $template = $this->templates->find($templateKey);

        if ($templateKey !== null && $template === null) {
            throw ValidationException::withMessages(['template_key' => 'Selecione um template de graduação válido.']);
        }

        return DB::transaction(function () use ($association, $data, $template, $templateKey): MartialArt {
            $martialArt = $this->persistMartialArt($association, $data);

            if ($template === null) {
                return $martialArt;
            }

            foreach ($template['ranks'] as $rankData) {
                $rank = $martialArt->ranks()->create($rankData);
                $this->audit->record('rank.saved', 'created', $rank, null, ['name' => $rank->name, 'status' => $rank->status], ['association_id' => $association->id]);
            }

            $this->audit->record('rank.template_applied', 'created', $martialArt, null, [
                'template_key' => $templateKey,
                'rank_count' => count($template['ranks']),
                'source_name' => $template['source_name'],
            ], ['association_id' => $association->id]);

            return $martialArt->load('ranks');
        });
    }

    public function saveMartialArt(Association $association, array $data, ?MartialArt $martialArt = null): MartialArt
    {
        return DB::transaction(function () use ($association, $data, $martialArt): MartialArt {
            return $this->persistMartialArt($association, $data, $martialArt);
        });
    }

    public function saveRank(MartialArt $martialArt, array $data, ?Rank $rank = null): Rank
    {
        return DB::transaction(function () use ($martialArt, $data, $rank): Rank {
            $rank ??= new Rank(['martial_art_id' => $martialArt->id]);
            $rank->fill($data)->save();
            $this->audit->record('rank.saved', $rank->wasRecentlyCreated ? 'created' : 'updated', $rank, null, ['name' => $rank->name, 'status' => $rank->status], ['association_id' => $martialArt->association_id]);

            return $rank;
        });
    }

    private function persistMartialArt(Association $association, array $data, ?MartialArt $martialArt = null): MartialArt
    {
        $martialArt ??= new MartialArt(['association_id' => $association->id]);
        $martialArt->fill($data)->save();
        $this->audit->record('martial_art.saved', $martialArt->wasRecentlyCreated ? 'created' : 'updated', $martialArt, null, ['name' => $martialArt->name, 'status' => $martialArt->status]);

        return $martialArt;
    }
}
