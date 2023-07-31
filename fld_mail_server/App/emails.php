<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class email extends Model
{
    public $timestamps = true;
    use SoftDeletes;
    protected $table = 'FLD_TB';
    protected $primaryKey = 'fld_id';
    protected $guarded = ['fld_id'];

    public function flowerDriveSite(){
        return $this->hasMany('App\site','id','s_id');
    }

    public function items()
    {
        return $this->hasMany('App\item', 'id', 'id');
    }


    public function order()
    {
        return $this->hasMany('App\ConsumerOrder', 'id', 'c_id');
    }
}
