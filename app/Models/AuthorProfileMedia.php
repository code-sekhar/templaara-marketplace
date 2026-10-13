<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthorProfileMedia extends Model
{
    protected $table = 'author_profile_media';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }
}
