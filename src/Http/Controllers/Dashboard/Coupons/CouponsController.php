<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Coupons;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Coupon\StoreCouponRequest;
use RiseTechApps\ApiKey\Http\Request\Dashboard\Coupon\UpdateCouponRequest;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Coupons\CouponsResource;
use RiseTechApps\ApiKey\Models\Coupon;
use RiseTechApps\ApiKey\Repositories\Coupon\CouponRepository;

class CouponsController extends Controller
{
    public function __construct(protected readonly CouponRepository $couponRepository)
    {

    }

    public function index(Request $request):JsonResponse
    {
        try {
            $data = $this->couponRepository->get();
            return response()->jsonSuccess(CouponsResource::collection($data));
        }catch (\Exception $e){
            return response()->jsonGone();
        }
    }

    public function store(StoreCouponRequest $request):JsonResponse
    {
        try{
            $data = $request->validated();
            $data['gateway_coupon_id'] = Str::slug($data['code']);
            $this->couponRepository->store($data);
            return response()->jsonSuccess();
        } catch (\Exception $e) {
            return response()->jsonGone($e->getMessage());

        }
    }

    public function show(Coupon $coupon):JsonResponse
    {
        try{

            if(is_null($coupon)){
                return response()->jsonGone();
            }

            return response()->jsonSuccess(CouponsResource::make($coupon));
        }catch (\Exception $exception){
            return response()->jsonGone($exception->getMessage());
        }
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon):JsonResponse
    {
        try {

            $data = $request->validated();

            if (!is_null($coupon)) {

                $this->couponRepository->update($coupon->getKey(), $data);

                return response()->jsonSuccess();
            }
            return response()->jsonGone();

        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }

    public function delete(Coupon $coupon): JsonResponse
    {

        try {
            if (!is_null($coupon)) {

                $this->couponRepository->findById($coupon->getKey())->delete();
                return response()->jsonSuccess();
            }

            return response()->jsonGone();
        } catch (\Exception $e) {
            return response()->jsonGone();
        }
    }
}
