<?php


namespace App\Modules\FlowerDrive\Repositories;



use App\Modules\FlowerDrive\Contracts\FlowerDriveSiteRepositoryInterface;
use App\Repositories\MainRepository;

class FlowerDriveSiteRepository extends MainRepository implements FlowerDriveSiteRepositoryInterface
{

    function model()
    {
        return 'App\FlowerDriveSite';
    }
}
