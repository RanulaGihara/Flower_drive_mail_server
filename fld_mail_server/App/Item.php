<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class FlowerDriveItem extends Model
{
    protected $table = "item_TB";
    protected $primaryKey = "item_TB_id";

    public $appends = ['image'];

    public function contacts()
    {
        return $this->belongsTo('App\Contacts','contact_id','item_TB');
    }

    public function package(){
        return $this->hasOne('App\pack','item_TB','pkg_id');
    }

}
