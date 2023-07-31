<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlowerDrive extends Model
{
    public $timestamps = true;
    use SoftDeletes;
    protected $table = 'FLD_TB';
    protected $primaryKey = 'fld_id';
    protected $guarded = ['fld_id'];

    public function flowerDriveSite(){
        return $this->hasMany('App\FlowerDriveSite','fld_id','fld_id');
    }

    public function lines(){
        return $this->hasMany('App\FlowerDriveLine', 'fld_id');
    }

    public function specialOccassion(){

        return $this->belongsTo('App\SpecialOccassion','special_oc_id','occ_id');
    }

    public function occasion()
    {
        return $this->hasOne('App\SpecialOccassion', 'occ_id', 'special_oc_id');
    }

    public function items()
    {
        return $this->hasMany('App\FlowerDriveItem', 'fld_id', 'fld_id');
    }

    public function fldContacts(){
        return $this->hasMany('App\FlowerDriveContact','fld_id','fld_id');
    }

    public function consumerOrder()
    {
        return $this->hasMany('App\ConsumerOrder', 'fld_id', 'fld_id');
    }

    //To get User tenanat id

    public function getUserTenant()
    {
        return $this->hasOne('App\User', 'id', 'created_by');
    }

    public function getSelectedContactList()
    {
        return $this->hasOne('App\ContactList', 'id', 'contact_list_id');
    }

    public function timezone()
    {
        return $this->hasOne('App\TimeZone', 'id', 'timezone');
    }

    public function sectionOrArea()
    {
        return $this->hasMany('App\FldSections', 'fld_id', 'fld_id');
    }

}
