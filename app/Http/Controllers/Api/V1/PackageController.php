<?php

namespace App\Http\Controllers\Api\V1;

class PackageController extends DomainResourceController
{
    protected string $table='subscription_packages';
    public function store(\Illuminate\Http\Request $r) { abort_unless($r->user()->isAdmin(),403); return parent::store($r); }
    public function update(\Illuminate\Http\Request $r,string $id) { abort_unless($r->user()->isAdmin(),403); return parent::update($r,$id); }
    public function destroy(string $id) { abort_unless(request()->user()->isAdmin(),403); return parent::destroy($id); }
}
