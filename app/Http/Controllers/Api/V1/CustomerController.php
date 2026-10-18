<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerRequest;
use App\Http\Requests\Api\V1\ListCustomersRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Customers
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerRepositoryInterface $customers) {}

    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            $this->customers->paginate($request->string('search')->toString() ?: null, $request->perPage()),
        );
    }

    public function store(CustomerRequest $request): CustomerResource
    {
        return CustomerResource::make($this->customers->create($request->validated()));
    }

    public function show(Customer $customer): CustomerResource
    {
        return CustomerResource::make($customer->loadCount('invoices'));
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->customers->update($customer, $request->validated()));
    }
}
