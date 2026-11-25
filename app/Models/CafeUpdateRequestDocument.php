<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The updated BIR COR one branch sent with a cafe name change request. */
class CafeUpdateRequestDocument extends Model
{
    protected $primaryKey = 'request_doc_id';

    protected $fillable = [
        'request_id',
        'branch_id',
        'file',
        'registered_at',
    ];

    protected $casts = [
        'registered_at' => 'date',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(CafeUpdateRequest::class, 'request_id', 'request_id');
    }

    public function branch(): BelongsTo
    {
        // Trashed too: a branch deleted after the request was sent still shows by name
        return $this->belongsTo(CafeBranch::class, 'branch_id', 'branch_id')->withTrashed();
    }
}
