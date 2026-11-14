<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'owner_id',
        'url',
        'path',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
?>