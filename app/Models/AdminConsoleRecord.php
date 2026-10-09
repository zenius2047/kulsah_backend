<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminConsoleRecord extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['payload' => 'array'];

    public static function payloadFor(string $resource): array
    {
        $payload = static::query()->where('resource', $resource)->value('payload');
        if (is_array($payload)) return $payload;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
