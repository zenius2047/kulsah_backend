<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminConsoleAudit extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['previous_value' => 'array', 'new_value' => 'array'];
}
