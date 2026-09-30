<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI source a conversation streams from.
 *
 * type=bridge — a CLI bridge connection (`connection_key` routes to it).
 * type=byok   — a Chat Completions endpoint (`endpoint` + encrypted `api_key`).
 *
 * Unlinked to any project table; consuming apps link via their own pivots.
 *
 * @property int $id
 * @property string $type
 * @property string|null $name
 * @property string|null $connection_key
 * @property string|null $endpoint
 * @property string|null $api_key
 * @property array<int, mixed>|null $last_providers
 * @property array<int, mixed>|null $last_workspaces
 * @property array<string, mixed>|null $last_posture
 * @property array<string, mixed>|null $last_bridge
 * @property \Illuminate\Support\Carbon|null $last_connected_at
 * @property array<string, mixed>|null $metadata
 */
class Connection extends Model
{
    public const TYPE_BRIDGE = 'bridge';

    public const TYPE_BYOK = 'byok';

    protected $table = 'ai_bridge_connections';

    protected $fillable = [
        'type',
        'name',
        'connection_key',
        'endpoint',
        'api_key',
        'last_providers',
        'last_workspaces',
        'last_posture',
        'last_bridge',
        'last_connected_at',
        'metadata',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'last_providers' => 'array',
        'last_workspaces' => 'array',
        'last_posture' => 'array',
        'last_bridge' => 'array',
        'last_connected_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Hide the (decrypted) API key from array/JSON output.
     *
     * @var list<string>
     */
    protected $hidden = ['api_key'];

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'connection_id');
    }

    public function isBridge(): bool
    {
        return $this->type === self::TYPE_BRIDGE;
    }

    public function isByok(): bool
    {
        return $this->type === self::TYPE_BYOK;
    }
}
