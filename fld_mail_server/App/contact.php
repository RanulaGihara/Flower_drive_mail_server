<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class contact extends Model
{
    public $timestamps = false;

    protected $table = 'CONTACT_TB';
    protected  $primaryKey = 'id';
    protected $keyType = 'char';

    protected  $fillable = ['id','contact_id','created_by','created_at','updated_by','updated_at','deleted_at'];

    public function mail(){
        return $this->belongsTo('App\Mails','id','e_id');
    }

    public function contactSite() {
        return $this->hasMany('App\contactSite', 'contact_id', 'id');
    }
   
}

