<?php


namespace App\Modules\FlowerDrive\Repositories;


use App\ConsumerPayment;
use App\Modules\FlowerDrive\Contracts\ConsumerPaymentRepositoryInterface;
use App\Repositories\MainRepository;

class ConsumerPaymentRepository extends MainRepository implements ConsumerPaymentRepositoryInterface
{

    function model()
    {
        return 'App\ConsumerPayment';
    }
}
