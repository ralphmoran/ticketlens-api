<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecallNoteAttachment extends Model
{
    protected $fillable = ['recall_note_id', 'filename', 'disk_path', 'size_bytes', 'mime_type'];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(RecallNote::class, 'recall_note_id');
    }
}
