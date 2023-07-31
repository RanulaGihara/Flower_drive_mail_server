<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class FlowerDriveLineItemPrice extends Model
{    
    public $timestamps = true;

    protected $table = 'FLD_LINE_ITEM_PRICE_TB';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $appends = ['terr_name'];    

    public function flowerDriveLine()
    {
        return $this->belongsTo('App\FlowerDriveLine');
    }

    public function getTerrNameAttribute()
    {           
        $data = Territory::query()
                ->where('terr_id', $this->terr_id)
                ->select('terr_name')
                ->firstOrFail();

        if ($data)
            return $data->terr_name;
        else    
            return '';
    }

    
}
