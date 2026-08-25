<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $fillable = [
        'board_id',
        'title',
        'description',
        'position',
        'column_id',
        'priority',
        'assignee',
        'tags',
        'source_id',
    ];

    protected $casts = [
        'tags' => 'array',
        'priority' => 'integer',
    ];

    public function board()
    {
        return $this->belongsTo(Board::class);
    }
}
