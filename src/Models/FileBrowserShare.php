<?php

namespace FileBrowser\Models;

use Illuminate\Database\Eloquent\Model;

class FileBrowserShare extends Model
{
    protected $table = 'filebrowser_shares';

    protected $fillable = [
        'hash', 'path', 'user_id', 'password_hash', 'token', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
