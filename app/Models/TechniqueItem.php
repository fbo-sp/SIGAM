<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechniqueItem extends Model
{
    public const KINDS = ['group', 'item'];

    public const KNOWLEDGE_TYPES = ['theoretical', 'practical'];

    protected $fillable = ['association_id', 'martial_art_id', 'parent_id', 'kind', 'knowledge_type', 'name', 'original_name', 'translation', 'description', 'key_points', 'common_error', 'safety_note', 'steps', 'video_url', 'sheet_path', 'sheet_mime', 'sheet_name', 'sort_order', 'status'];

    public function martialArt(): BelongsTo
    {
        return $this->belongsTo(MartialArt::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /** @return list<string> pontos que o professor avalia, um por linha */
    public function keyPointList(): array
    {
        return self::lines($this->key_points);
    }

    /** @return list<string> passo a passo, um passo por linha */
    public function stepList(): array
    {
        return self::lines($this->steps);
    }

    /** Endereço para tocar o vídeo dentro do portal (YouTube ou Vimeo); outros links só abrem fora. */
    public function videoEmbedUrl(): ?string
    {
        $url = (string) $this->video_url;
        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $match)) {
            return 'https://www.youtube-nocookie.com/embed/'.$match[1];
        }
        if (preg_match('~^https?://(?:www\.)?(?:player\.)?vimeo\.com/(?:video/)?(\d{6,12})~', $url, $match)) {
            return 'https://player.vimeo.com/video/'.$match[1];
        }

        return null;
    }

    public function hasStudyContent(): bool
    {
        return $this->translation || $this->description || $this->key_points || $this->common_error || $this->safety_note || $this->steps || $this->video_url || $this->sheet_path;
    }

    /** @return list<string> */
    private static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), fn (string $line) => $line !== ''));
    }

    public function studyLogs(): HasMany
    {
        return $this->hasMany(TechniqueStudyLog::class);
    }

    public function knowledgeLabel(): string
    {
        return $this->knowledge_type === 'theoretical' ? 'Conhecimento Teórico' : 'Conhecimento Prático';
    }
}
