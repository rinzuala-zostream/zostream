<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizApiKey extends Model
{
    protected $table = 'quiz_api_keys';

    protected $fillable = [
        'api_key',
        'owner_name',
        'email',
        'description',
        'valid_from',
        'valid_until',
        'is_active',
        'usage_count',
        'last_used_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // If valid_until is null => never expires
        if ($this->valid_until === null) {
            return true;
        }

        // Compare properly using Carbon
        return now()->lessThanOrEqualTo($this->valid_until);
    }
}
