<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class FlowerDriveContact extends Model
{
    public $timestamps = false;

    protected $table = 'FLD_CONTACT_TB';
    protected  $primaryKey = 'id';
    protected $keyType = 'char';
//        protected  $guarded = ['id'];
    public $incrementing = false;

    protected  $fillable = ['id','fld_id','contact_id','created_by','created_at','updated_by','updated_at','deleted_at'];

    public function flowerDrive(){
        return $this->belongsTo('App\FlowerDrive','fld_id','fld_id');
    }

    public function person() {
        return $this->hasOne('App\Person', 'id', 'contact_id');
    }

    public function contactSite() {
        return $this->hasMany('App\FlowerDriveContactSite', 'fld_contact_id', 'id');
    }
    public function fldBmContactSite() {
        return $this->hasOne('App\FlowerDriveByondMarketContact', 'fld_contact_id', 'id');
    }

    public function territory()
    {
        return $this->belongsTo('App\Territory', 'terr_id', 'terr_id');
    }
}

