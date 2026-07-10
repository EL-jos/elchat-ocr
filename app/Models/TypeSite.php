<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeSite extends BaseModel
{
    protected $table = 'type_sites';

    public function sites(){
        return $this->hasMany(Site::class);
    }
}
