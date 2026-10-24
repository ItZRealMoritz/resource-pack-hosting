<?php

namespace ItZRealMoritz\ResourcePackHosting\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property Server $server
 * @property string $name
 * @property string $original_name
 * @property int $size
 * @property string $sha1
 * @property string $token
 * @property ?int $pack_format
 * @property ?string $description
 * @property int $file_count
 * @property int $downloads
 * @property ?\Illuminate\Support\Carbon $last_downloaded_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ResourcePack extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'pack_format' => 'integer',
            'file_count' => 'integer',
            'downloads' => 'integer',
            'last_downloaded_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function getStoragePath(): string
    {
        return 'resource-packs/' . $this->server->uuid . '/' . $this->uuid . '.zip';
    }

    public function getDownloadUrl(): string
    {
        return route('resource-pack-hosting.download', ['token' => $this->token]);
    }
}
