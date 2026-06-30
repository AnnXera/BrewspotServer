<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchDocument extends Model
{
    protected $primaryKey = 'branch_doc_id';

    protected $fillable = [
        'branch_id',
        'doc_type',
        'file',
        'registered_at',
        'expired_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'expired_at'    => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id');
    }
}