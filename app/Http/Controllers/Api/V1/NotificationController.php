<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class NotificationController extends DomainResourceController {
 protected string $table='notification_inbox';
 public function index(Request $request) { $request->merge(['user_id'=>$request->user()->id]);return parent::index($request); }
 private function own(string $id): void { abort_unless(DB::table($this->table)->where('user_id',request()->user()->id)->where(fn($q)=>$q->where('public_id',$id)->orWhere('id',ctype_digit($id)?(int)$id:-1))->exists(),404); }
 public function show(string $id) { $this->own($id); return parent::show($id); }
 public function update(Request $request,string $id) { $this->own($id); return parent::update($request,$id); }
 public function destroy(string $id) { $this->own($id); return parent::destroy($id); }
}
