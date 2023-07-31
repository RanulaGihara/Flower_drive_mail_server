<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class FlowerDriveItem extends Model
{
    protected $table = "FLD_LINE_TB";
    protected $primaryKey = "fld_line_id";

    public $appends = ['image'];

    public function itemDetails()
    {
        return $this->belongsTo('App\FlowerDriveItemDetail','fld_line_id','fld_line_id');
    }

    public function package(){
        return $this->hasOne('App\FlowerDrivePackage','fld_pkg_id','pkg_id');
    }

//    public function getImageAttribute($fldLineId = null, $img_path = null){
//        $image = new Image();
//        $images = $image->getImagesByResourceTypeAndResourceId($img_path,$fldLineId);
//        if(isset($images)) {
//            return $images;
//        }
//        else {
//            if(env('S3_ENABLED')) {
//                $images['url'] = env('S3_BUCKET_PUBLIC_URL') . '/public/defaultImages/home-watermark-chapel.png';
//            }else{
//                $images['url'] =  asset('/images/home-watermark-chapel.png', env('USE_HTTPS'));
//            }
//        }
//        return $images;
//    }

    public function getImageAttribute(){
        $image = new Image();
        $images = $image->getImagesByResourceTypeAndResourceId(IMAGE_RESOURCE_TYPE_ITEM_MASTER_GALLERY,$this->item_id);

        if(!is_null($images) && count($images) != 0) {
            return $images;
        }
        else {
            if(env('S3_ENABLED')) {
                return env('S3_BUCKET_PUBLIC_URL') . '/public/defaultImages/home-watermark-chapel.png';
            }else{
                return asset('/images/home-watermark-chapel.png', env('USE_HTTPS'));
            }
        }
    }


}
