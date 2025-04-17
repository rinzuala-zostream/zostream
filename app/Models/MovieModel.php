<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovieModel extends Model
{
    protected $primaryKey = 'num'; // Ensure 'num' is set as the primary key

    protected $keyType = 'int'; // Ensure primary key is integer
    public $incrementing = true;
    
    protected $table = 'movie';
    public $timestamps = true;
}
